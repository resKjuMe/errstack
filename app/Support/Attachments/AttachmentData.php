<?php

namespace App\Support\Attachments;

use App\Enums\AttachmentKind;
use App\Http\Controllers\IssueDetailController;
use App\Models\Event;
use App\Models\EventAttachment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Formats;
use Illuminate\Support\Facades\Gate;

/**
 * Die Anhänge einer Meldung, fertig für die Detailseite.
 *
 * Zwei Dinge werden hier entschieden und nicht in der Oberfläche:
 *
 * **Was angesehen werden darf.** Ein Bild wird eingebettet, ein Text auf Abruf
 * angerissen, alles andere nur zum Herunterladen angeboten — und zwar anhand von
 * {@see AttachmentKind}, das beim Ablegen feststand. Die Oberfläche
 * bekommt für eine Datei ohne Vorschau **keine** Adresse; sie muss nicht
 * entscheiden, ob sie sie benutzen darf.
 *
 * **Wer löschen darf.** Die Rechtefrage wird hier beantwortet, wie bei den
 * Kommentaren (S10): eine Schaltfläche, die beim Klick abgewiesen wird, ist
 * schlimmer als keine.
 */
final class AttachmentData
{
    /**
     * @return array{items: list<array<string, mixed>>, retentionDays: int, canDelete: bool}
     */
    public static function forEvent(Issue $issue, Event $event, ?User $viewer): array
    {
        $retentionDays = self::retentionDays($issue);
        $mayDelete = $viewer !== null && Gate::forUser($viewer)->allows('update', $issue);

        $items = EventAttachment::forEvent($event)
            ->get()
            ->map(fn (EventAttachment $attachment): array => self::present(
                $issue,
                $event,
                $attachment,
                $retentionDays,
                $mayDelete,
            ))
            ->all();

        return [
            'items' => $items,
            // Die Frist gehört an die Anzeige und nicht nur in die Einstellungen:
            // wer einen Screenshot vermisst, soll hier lesen können, dass Anhänge
            // eine kürzere Frist haben als die Meldungen, an denen sie hängen.
            'retentionDays' => $retentionDays,
            'canDelete' => $mayDelete,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(
        Issue $issue,
        Event $event,
        EventAttachment $attachment,
        int $retentionDays,
        bool $mayDelete,
    ): array {
        $previewable = $attachment->kind->isPreviewable();
        $previewBytes = max(1, (int) config('attachments.preview.preview_bytes'));
        $expiresAt = $attachment->received_at->addDays($retentionDays);

        return [
            'id' => $attachment->id,
            'name' => $attachment->name,
            'contentType' => $attachment->content_type,
            'kind' => $attachment->kind->value,
            'kindLabel' => $attachment->kind->label(),
            'size' => $attachment->size,
            'sizeLabel' => Formats::bytes($attachment->size),
            'receivedAt' => $attachment->received_at->toIso8601String(),
            'receivedAtLabel' => Formats::dateTimeSeconds($attachment->received_at),
            'expiresAtLabel' => Formats::dateTimeSeconds($expiresAt),
            'downloadHref' => route('issues.attachments.show', [$issue, $event, $attachment]),
            'previewHref' => $previewable
                ? route('issues.attachments.preview', [$issue, $event, $attachment])
                : null,
            // Nur für Text: die Vorschau liest den Anfang der Datei, und eine
            // Logdatei, die dabei abgeschnitten wird, soll nicht aussehen, als
            // hörte sie dort auf. Für Bilder gibt es kein „gekürzt" — sie werden
            // ganz ausgeliefert.
            'previewTruncated' => $attachment->kind === AttachmentKind::Text
                && $attachment->size > $previewBytes,
            'deleteHref' => $mayDelete
                ? route('issues.attachments.destroy', [$issue, $event, $attachment])
                : null,
        ];
    }

    /**
     * Die Frist des Projekts, ersatzweise die des Betreibers.
     *
     * Gefragt wird der **Fehler** und nicht die Meldung, obwohl beide zum selben
     * Projekt gehören: die Detailseite hat es am Fehler ohnehin mitgeladen
     * ({@see IssueDetailController::show()}), und über die
     * Meldung wäre es eine zweite Abfrage mitten im Zusammenbauen der Seite.
     *
     * Der Rückfall greift, wenn das Projekt nach dem Laden verschwunden ist — er
     * steht hier, damit die Anzeige nicht mit einer Null dasteht, die wie „läuft
     * sofort ab" aussieht.
     */
    private static function retentionDays(Issue $issue): int
    {
        $issue->loadMissing('project');
        $project = $issue->project;

        return (int) ($project instanceof Project
            ? $project->attachment_retention_days
            : config('attachments.retention_days'));
    }
}
