<?php

namespace App\Support\Integrations\Tickets;

use App\Enums\ExternalIssueState;
use App\Enums\IssueActivityType;
use App\Enums\IssueStatus;
use App\Jobs\SyncTicketState;
use App\Models\Integration;
use App\Models\IntegrationWebhookEvent;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueLink;
use App\Support\Integrations\GitHub\GitHubWebhookProcessor;
use App\Support\Issues\IssueActions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Was mit einer eingegangenen Meldung eines Ticket-Systems geschieht (X4) — die
 * eine Richtung des Abgleichs: **drüben geschlossen, hier erledigt.**
 *
 * Ausgewertet wird nur, was ein Ticket betrifft. Alles andere — Kommentare,
 * Projekte, Zyklen, wovon Linear und Jira reichlich melden — wird festgehalten
 * und nicht ausgewertet. Der Vermerk ist trotzdem kein Beifang: „angekommen,
 * nichts zu tun" ist im Betrieb die häufigste Antwort auf „warum passiert
 * nichts?", und ohne ihn ist sie von „gar nichts angekommen" nicht zu
 * unterscheiden.
 *
 * **Der Zustand wird nachgeführt, das Erledigen nicht erzwungen.** Ein
 * geschlossenes Ticket setzt den Fehler auf erledigt — das ist der Zweck. Ein
 * wieder geöffnetes Ticket öffnet den Fehler dagegen **nicht** von selbst wieder:
 * „erledigt" kann hier auf einem zweiten Ticket beruhen, auf einer Auslieferung
 * oder schlicht darauf, dass jemand es entschieden hat. Ein Klick drüben soll
 * diese Entscheidung nicht überstimmen; die Verknüpfung zeigt dann eben „offen"
 * neben einem erledigten Fehler, und das ist die ehrliche Anzeige. Dieselbe
 * Entscheidung wie in {@see GitHubWebhookProcessor} — sie gehört zur Bedeutung
 * des Abgleichs und nicht zum Anbieter.
 *
 * **Erledigt wird hier absichtlich nicht über {@see IssueActions}**,
 * obwohl das der Weg für jede Zustandsänderung aus der Oberfläche ist: dort hängt
 * der Rückweg zum Ticket dran ({@see SyncTicketState}), und der würde
 * das Ticket schließen, das gerade geschlossen wurde. Die Schleife bräche nach
 * einem Durchgang ab, aber nicht ohne vorher einen Übergang in einem fremden
 * System auszulösen, den niemand angefordert hat.
 */
final class TicketWebhookProcessor
{
    /**
     * @return string die Zusammenfassung, die am Ereignis vermerkt wird
     */
    public static function handle(IntegrationWebhookEvent $event): string
    {
        $integration = self::integration($event);

        if ($integration === null) {
            // Die Anbindung ist gelöst worden, während die Meldung in der
            // Warteschlange lag. Kein Fehler — nur nichts mehr zu tun.
            return __('integrations.webhook.results.disconnected');
        }

        if (! self::isTicketEvent($event)) {
            return __('integrations.webhook.results.ignored', ['event' => $event->event]);
        }

        if (! $integration->syncsInbound()) {
            // Der Schalter steht aus. Die Meldung wird trotzdem festgehalten und
            // der Grund vermerkt: „ich habe den Abgleich abgeschaltet und
            // wundere mich, dass nichts passiert" soll am Ereignis zu klären
            // sein und nicht erst in den Einstellungen.
            return __('integrations.webhook.results.inbound_off');
        }

        $provider = $integration->provider;
        $payload = $event->payload;
        $target = $event->repository;
        $number = TicketWebhook::number($provider, $payload);

        if ($target === null || $number === null) {
            return __('integrations.webhook.results.unmatched');
        }

        /** @var Collection<int, IssueLink> $links */
        $links = IssueLink::query()
            ->where('provider', $provider->value)
            ->where('repository', $target)
            ->where('number', $number)
            // Nur Verknüpfungen dieser Organisation. Anders als bei GitHub steht
            // sie hier fest (die Adresse der Meldung gehört einer Anbindung) —
            // die Bedingung ist deshalb keine Absicherung gegen Mehrdeutigkeit,
            // sondern gegen den Fall, dass zwei Organisationen dieselbe
            // Jira-Instanz nutzen und dieselben Projektschlüssel sehen.
            ->whereHas(
                'issue.project',
                fn ($project) => $project->where('organization_id', $event->organization_id),
            )
            ->with('issue')
            ->get();

        if ($links->isEmpty()) {
            return __('integrations.webhook.results.unlinked');
        }

        $state = TicketWebhook::state($provider, $payload);
        $title = TicketWebhook::title($provider, $payload);
        $resolved = 0;

        foreach ($links as $link) {
            $link->forceFill([
                'state' => $state,
                'title' => $title ?? $link->title,
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
     * Ob diese Meldung überhaupt ein Ticket betrifft.
     *
     * Jira nennt die Art mit Vorsilbe (`jira:issue_updated`), Linear das
     * betroffene Ding (`Issue`). Auf den Zustand zu sehen und den Rest
     * durchzulassen wäre die bequeme Abkürzung — und die Stelle, an der ein
     * Kommentar-Ereignis mit einem eingebetteten Vorgang einen Fehler erledigt.
     */
    private static function isTicketEvent(IntegrationWebhookEvent $event): bool
    {
        return in_array($event->event, [
            'jira:issue_updated',
            'jira:issue_created',
            'jira:issue_deleted',
            'Issue',
        ], true);
    }

    /**
     * Die Anbindung, über die diese Meldung hereinkam.
     *
     * Über Organisation und Anbieter, weil es je Paar genau eine gibt (der
     * eindeutige Index sagt das) — und nicht über das Geheimnis der Adresse: das
     * steht nicht am Ereignis, und es soll auch nicht dorthin.
     */
    private static function integration(IntegrationWebhookEvent $event): ?Integration
    {
        if ($event->organization_id === null) {
            return null;
        }

        return Integration::forOrganization($event->organization_id, $event->provider);
    }

    /**
     * Setzt den Fehler auf erledigt, weil sein Ticket geschlossen wurde.
     *
     * Das bedingte `update` ist die einzige Absicherung und reicht: es trifft
     * nur, was noch offen ist. Zwei Meldungen zu demselben Ticket — Jira schickt
     * bei einem Übergang mit Kommentar zwei — machen daraus nicht zwei Vermerke,
     * weil die zweite null Zeilen ändert.
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
}
