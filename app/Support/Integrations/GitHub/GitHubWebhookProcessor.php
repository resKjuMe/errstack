<?php

namespace App\Support\Integrations\GitHub;

use App\Enums\ExternalIssueState;
use App\Enums\IntegrationProvider;
use App\Enums\IssueActivityType;
use App\Enums\IssueStatus;
use App\Jobs\FetchReleaseCommits;
use App\Models\Integration;
use App\Models\IntegrationWebhookEvent;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueLink;
use App\Models\Release;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Was mit einer eingegangenen Meldung geschieht.
 *
 * Zwei Arten werden ausgewertet, und beide beantworten dieselbe Frage aus
 * verschiedenen Richtungen: **hat sich draußen etwas getan, das hier drin etwas
 * bedeutet?**
 *
 *   issues  ein verknüpftes Ticket wurde geschlossen oder wieder geöffnet — der
 *           Fehler hier folgt (Abnahmekriterium „Statusabgleich")
 *   push    Commits sind angekommen; eine Auslieferung, die genau auf diesen
 *           Stand zeigt und noch keine Commits hat, bekommt sie jetzt
 *
 * Alles andere wird festgehalten und nicht ausgewertet. Der Vermerk ist trotzdem
 * kein Beifang: „angekommen, nichts zu tun" ist im Betrieb die häufigste Antwort
 * auf „warum passiert nichts?", und ohne ihn ist sie von „gar nichts angekommen"
 * nicht zu unterscheiden.
 */
final class GitHubWebhookProcessor
{
    /**
     * @return string die Zusammenfassung, die am Ereignis vermerkt wird
     */
    public static function handle(IntegrationWebhookEvent $event): string
    {
        return match ($event->event) {
            'issues' => self::issues($event),
            'push' => self::push($event),
            default => __('integrations.webhook.results.ignored', ['event' => $event->event]),
        };
    }

    /**
     * Der Zustandsabgleich.
     *
     * **Der Zustand wird nachgeführt, das Erledigen nicht erzwungen.** Ein
     * geschlossenes Ticket setzt den Fehler auf erledigt — das ist der Zweck.
     * Ein wieder geöffnetes Ticket öffnet den Fehler dagegen **nicht** von
     * selbst wieder: „erledigt" kann hier auf einem zweiten Ticket beruhen, auf
     * einer Auslieferung oder schlicht darauf, dass jemand es entschieden hat.
     * Ein Klick drüben soll diese Entscheidung nicht überstimmen; die
     * Verknüpfung zeigt dann eben „offen" neben einem erledigten Fehler, und
     * das ist die ehrliche Anzeige.
     */
    private static function issues(IntegrationWebhookEvent $event): string
    {
        $payload = $event->payload;
        $repository = $event->repository;
        $number = data_get($payload, 'issue.number');

        if ($repository === null || ! is_numeric($number)) {
            return __('integrations.webhook.results.unmatched');
        }

        $state = GitHubWebhook::issueState($payload);
        $title = data_get($payload, 'issue.title');

        /** @var Collection<int, IssueLink> $links */
        $links = IssueLink::query()
            ->where('provider', IntegrationProvider::GitHub->value)
            ->where('repository', $repository)
            ->where('number', (int) $number)
            // Nur Verknüpfungen der Organisation, der dieses Repository gehört.
            // Ohne diese Bedingung könnte ein Ticket in einem öffentlichen
            // Repository Fehler in einer fremden Organisation erledigen, die es
            // ebenfalls verknüpft hat.
            ->when(
                $event->organization_id !== null,
                fn ($query) => $query->whereHas(
                    'issue.project',
                    fn ($project) => $project->where('organization_id', $event->organization_id),
                ),
            )
            ->with('issue')
            ->get();

        if ($links->isEmpty()) {
            return __('integrations.webhook.results.unlinked');
        }

        $resolved = 0;

        foreach ($links as $link) {
            $link->forceFill([
                'state' => $state,
                'title' => is_string($title) ? $title : $link->title,
            ])->save();

            if ($state === ExternalIssueState::Closed && self::resolve($link)) {
                $resolved++;
            }
        }

        return __('integrations.webhook.results.issue', [
            'links' => $links->count(),
            'resolved' => $resolved,
        ]);
    }

    /**
     * Setzt den Fehler auf erledigt, weil sein Ticket geschlossen wurde.
     *
     * Das bedingte `update` ist die einzige Absicherung und reicht: es trifft
     * nur, was noch offen ist. Zwei Meldungen zu demselben Ticket — GitHub
     * schickt bei einem „close with comment" zwei — machen daraus nicht zwei
     * Vermerke, weil die zweite null Zeilen ändert.
     */
    private static function resolve(IssueLink $link): bool
    {
        $issue = $link->issue;

        if (! $issue instanceof Issue || $issue->status !== IssueStatus::Unresolved) {
            return false;
        }

        $now = CarbonImmutable::now();

        $updated = Issue::query()
            ->whereKey($issue->getKey())
            ->where('status', IssueStatus::Unresolved)
            ->update([
                'status' => IssueStatus::Resolved,
                'resolved_at' => $now,
                // Ohne handelndes Konto, wie beim Ausliefern (R3): geschlossen
                // hat das jemand in einer anderen Anwendung, und ein Name, den
                // wir hier hinschreiben könnten, wäre geraten.
                'resolved_by_id' => null,
                'resolved_in_release_id' => null,
                'resolved_in_next_release' => false,
                'for_review_at' => null,
                'updated_at' => $now,
            ]);

        if ($updated === 0) {
            return false;
        }

        IssueActivity::query()->create([
            'issue_id' => $issue->id,
            'project_id' => $issue->project_id,
            'user_id' => null,
            'actor_name' => null,
            'type' => IssueActivityType::ExternalResolved,
            'data' => ['reference' => $link->reference()],
        ]);

        return true;
    }

    /**
     * Angekommene Commits.
     *
     * Sie werden hier **nicht** übernommen, obwohl sie in der Nutzlast stehen:
     * Commits hängen an einer Auslieferung, und ein Push weiß von keiner. Was
     * er beantwortet, ist die umgekehrte Frage — eine Auslieferung, die bereits
     * angekündigt ist und auf genau diesen Stand zeigt, hatte bisher nichts zu
     * zeigen, weil es die Commits noch nicht gab. Genau diese Reihenfolge ist
     * im Betrieb der Regelfall: die Pipeline meldet die Version, während der
     * Push noch läuft.
     */
    private static function push(IntegrationWebhookEvent $event): string
    {
        if ($event->organization_id === null) {
            return __('integrations.webhook.results.unmatched');
        }

        $shas = self::pushedShas($event->payload);

        if ($shas === []) {
            return __('integrations.webhook.results.unmatched');
        }

        $releases = Release::query()
            ->whereIn('ref', $shas)
            ->whereHas('project', fn ($project) => $project->where('organization_id', $event->organization_id))
            ->whereDoesntHave('commits')
            ->get();

        foreach ($releases as $release) {
            FetchReleaseCommits::dispatch($release->id);
        }

        $integration = Integration::forOrganization($event->organization_id);
        $integration?->markSynced();

        return __('integrations.webhook.results.push', ['releases' => $releases->count()]);
    }

    /**
     * Die Hashes, die dieser Push mitbringt — der Kopf und die einzelnen
     * Commits.
     *
     * Beide, weil eine Pipeline die Auslieferung mal auf den Kopf des Pushes
     * und mal auf den Commit meldet, aus dem sie gebaut hat (bei einem Push mit
     * mehreren Commits sind das zwei verschiedene).
     *
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private static function pushedShas(array $payload): array
    {
        $shas = [];

        $after = $payload['after'] ?? null;

        if (is_string($after) && $after !== '' && ! self::isNullSha($after)) {
            $shas[] = $after;
        }

        $commits = $payload['commits'] ?? null;

        if (is_array($commits)) {
            foreach ($commits as $commit) {
                $sha = is_array($commit) ? ($commit['id'] ?? null) : null;

                if (is_string($sha) && $sha !== '') {
                    $shas[] = $sha;
                }
            }
        }

        return array_values(array_unique($shas));
    }

    /**
     * Der Hash aus lauter Nullen: so meldet GitHub das Löschen eines Zweiges.
     * Er zeigt auf nichts und dürfte höchstens zufällig eine Auslieferung
     * treffen.
     */
    private static function isNullSha(string $sha): bool
    {
        return trim($sha, '0') === '';
    }
}
