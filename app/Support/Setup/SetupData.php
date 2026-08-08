<?php

namespace App\Support\Setup;

use App\Enums\DiscardReason;
use App\Events\IssueCreated;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Support\Formats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nutzlast des Einrichtungs-Assistenten.
 *
 * Sie trägt die DSN im Klartext und wird deshalb — wie die Schlüssel-Seite —
 * nur denen ausgeliefert, die die Schlüssel auch verwalten dürfen (siehe
 * ProjectSetupController).
 */
final class SetupData
{
    /**
     * Wie viele Verwerfungen der Hilfe-Abschnitt zeigt. Mehr wäre keine
     * Hilfestellung mehr, sondern eine Statistik — die gehört in die
     * Nutzungsübersicht.
     */
    private const MAX_DISCARDS = 5;

    /**
     * @return array<string, mixed>
     */
    public static function index(Project $project, SetupGuide $guide): array
    {
        $project->loadMissing('organization');
        $organization = $project->organization;

        $key = self::key($project);
        $dsn = $key?->dsn();

        return [
            'project' => [
                'slug' => $project->slug,
                'name' => $project->name,
                'platform' => $project->platform->value,
                'platformLabel' => $project->platform->label(),
                'href' => route('projects.show', [$organization, $project]),
                'keysHref' => route('projects.keys.index', [$organization, $project]),
                'setupHref' => route('projects.setup.index', [$organization, $project]),
            ],
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            // Ohne aktiven Schlüssel gäbe es nichts zu kopieren. Der Fall ist
            // selten (jedes Projekt entsteht mit einem), aber möglich: wer
            // seinen einzigen Schlüssel abschaltet, soll hier nicht eine DSN
            // vorgesetzt bekommen, unter der nichts angenommen wird.
            'dsn' => $dsn,
            'keyName' => $key?->name,
            'guides' => SetupGuide::options(),
            'guide' => self::guide($guide, $dsn ?? ''),
            // Woher der Wartebildschirm seinen Stand holt, ohne die Seite neu zu
            // laden.
            'statusHref' => route('projects.setup.status', [$organization, $project]),
            // Und woran er merkt, dass sich etwas getan hat, ohne zu fragen:
            // derselbe Kanal wie die Fehlerliste. Er trägt die ganze
            // Organisation — welches Projekt gemeint ist, steht daneben.
            'live' => [
                'channel' => IssueCreated::channelName($organization->id),
                'projectId' => $project->id,
            ],
            'state' => self::state($project),
        ];
    }

    /**
     * Der Stand der Einrichtung — dieselbe Nutzlast für die erste Seitenlast und
     * für die Abfragen des Wartebildschirms.
     *
     * **Gemeldet wird die angenommene Meldung, nicht der fertige Fehler.** Der
     * Datensatz in `ingest_payloads` entsteht beim Annehmen, der Fehlereintrag
     * erst danach in der Warteschlange. Auf den Eintrag zu warten hieße, dem
     * Einrichtenden bei stehendem Queue-Worker minutenlang „nichts angekommen"
     * anzuzeigen, obwohl seine Meldung längst hier ist — und ihn nach der
     * falschen Ursache suchen zu lassen. Der Verweis auf den Fehler kommt
     * nach, sobald es ihn gibt.
     *
     * @return array<string, mixed>
     */
    public static function state(Project $project): array
    {
        $project->loadMissing('organization');

        $payload = IngestPayload::query()
            ->where('project_id', $project->id)
            ->orderBy('id')
            ->first();

        $issue = Issue::query()
            ->where('project_id', $project->id)
            ->orderBy('id')
            ->first();

        return [
            'received' => $payload !== null,
            'receivedAt' => Formats::dateTimeSeconds($payload?->created_at),
            // Womit gemeldet wurde. Die erste Rückmeldung des Assistenten, die
            // nicht von uns stammt, sondern vom angeschlossenen SDK — und
            // damit der Beleg, dass die Anleitung befolgt wurde.
            'sdk' => $payload?->sdk,
            'issue' => $issue === null ? null : [
                'title' => (string) ($issue->title ?? $issue->culprit ?? ''),
                'href' => route('issues.show', $issue),
            ],
            'issuesHref' => route('issues.index', ['projects' => [$project->slug]]),
            'discards' => self::discards($project),
        ];
    }

    /**
     * Was angekommen und trotzdem nicht geblieben ist — der Unterschied
     * zwischen „nichts gesendet" und „gesendet, aber abgewiesen".
     *
     * Genau das ist die Frage, an der eine Einrichtung hängen bleibt: die
     * allgemeine Hilfe („DSN richtig? Firewall offen?") führt in die Irre, wenn
     * die Meldung längst hier war und ein Eingangsfilter sie genommen hat.
     * Deshalb steht die Zählung neben der Hilfe und nicht in einer
     * Verwaltungsansicht, die man erst kennen muss.
     *
     * Gezählt wird nur die jüngste Vergangenheit: eine Verwerfung von letzter
     * Woche erklärt nicht, warum die Probe von eben nicht ankommt.
     *
     * @return list<array{reason: string, label: string, origin: string, quantity: int}>
     */
    private static function discards(Project $project): array
    {
        $since = Carbon::now()->subDay()->startOfHour();

        // Zusammengefasst wird in der Datenbank und nicht hier: die Tabelle
        // zählt je Stunde, und über einen Tag mit mehreren Schlüsseln wären das
        // Dutzende Zeilen für die fünf Angaben, die auf der Seite stehen.
        $rows = IngestDiscard::query()
            ->where('project_id', $project->id)
            ->where('bucket', '>=', $since)
            ->groupBy('origin', 'reason')
            ->orderByDesc('total')
            ->limit(self::MAX_DISCARDS)
            ->get(['origin', 'reason', DB::raw('SUM(quantity) AS total')]);

        $discards = [];

        foreach ($rows as $row) {
            $discards[] = [
                'reason' => $row->reason,
                'label' => self::discardLabel($row),
                'origin' => $row->origin->value,
                'quantity' => (int) $row->getAttribute('total'),
            ];
        }

        return $discards;
    }

    /**
     * Die Bezeichnung einer Verwerfung. Unsere eigenen Gründe sind übersetzt;
     * was ein SDK selbst verworfen hat, trägt dessen Bezeichnung
     * (`queue_overflow`, `before_send` …) und bleibt unübersetzt — die Liste
     * wächst mit jeder SDK-Fassung, und ein erfundener deutscher Name dafür
     * wäre nicht wiederzuerkennen.
     */
    private static function discardLabel(IngestDiscard $discard): string
    {
        return DiscardReason::tryFrom($discard->reason)?->label() ?? $discard->reason;
    }

    /**
     * Der aktive Schlüssel, dessen DSN der Assistent zeigt: der älteste, damit
     * bei mehreren derselbe stehen bleibt und nicht bei jedem Aufruf ein
     * anderer erscheint. In der Regel ist das der beim Anlegen entstandene.
     */
    private static function key(Project $project): ?ProjectKey
    {
        return $project->keys()
            ->where('active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private static function guide(SetupGuide $guide, string $dsn): array
    {
        return [
            'value' => $guide->value,
            'label' => $guide->label(),
            'package' => $guide->package(),
            'docsHref' => $guide->docsHref(),
            'steps' => $guide->steps($dsn),
        ];
    }
}
