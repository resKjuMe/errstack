<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentKind;
use App\Models\Event;
use App\Models\EventAttachment;
use App\Models\Issue;
use App\Policies\IssuePolicy;
use App\Support\Attachments\AttachmentStore;
use App\Support\Issues\EventNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Anhänge einer Meldung: ansehen, herunterladen, löschen.
 *
 * **Drei Kennungen stehen in der Adresszeile, und alle drei werden geprüft.**
 * Fehler, Meldung und Anhang kommen als Kennungen daher, nicht aus einer Auswahl
 * — eine vertauschte oder geratene Zeile darf keinen fremden Screenshot unter
 * fremdem Fehler ausliefern. Das Recht hängt am Fehler (ProjektPolicy über
 * {@see IssuePolicy}), die Zugehörigkeit an der Kette darunter.
 *
 * **Ausgeliefert wird als Download, es sei denn, es ist gefahrlos anders.** Nur
 * was beim Ablegen als Bild oder Text eingeordnet wurde
 * ({@see AttachmentKind::isPreviewable()}), geht überhaupt inline an den Browser;
 * alles andere — HTML, SVG, unbekannte Typen — ausschließlich als Datei. Der
 * Grund ist der Absender: eine Datei in einem Anhang kommt von einer überwachten
 * Anwendung, und wer dort schreiben kann, könnte sonst über unsere Adresse
 * beliebiges HTML im Browser eines Teammitglieds ausführen.
 */
class IssueAttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentStore $store,
    ) {}

    /**
     * Der Anhang als Download.
     */
    public function show(Issue $issue, Event $event, EventAttachment $attachment): StreamedResponse
    {
        $this->authorizeAccess($issue, $event, $attachment);

        return $this->deliver($attachment, inline: false);
    }

    /**
     * Der Anhang zum Ansehen im Browser.
     *
     * Bilder gehen ganz durch, Text nur mit seinem Anfang: eine Logdatei von
     * zwanzig Megabyte im `<pre>` einer Fehlerseite ist keine Vorschau. Dass sie
     * gekürzt ist, sagt die Anzeige daneben (`previewTruncated`) — sie weiß es aus
     * der Größe und muss dafür nichts nachladen.
     */
    public function preview(Issue $issue, Event $event, EventAttachment $attachment): Response
    {
        $this->authorizeAccess($issue, $event, $attachment);

        if (! $attachment->kind->isPreviewable()) {
            // Kein 403: die Adresse gibt es für diese Datei nicht, und die
            // Oberfläche bekommt sie auch nicht zu sehen.
            throw new NotFoundHttpException;
        }

        if ($attachment->kind !== AttachmentKind::Text) {
            return $this->deliver($attachment, inline: true);
        }

        $prefix = $this->store->readPrefix(
            $attachment,
            max(1, (int) config('attachments.preview.preview_bytes')),
        );

        if ($prefix === null) {
            throw new NotFoundHttpException;
        }

        // Als reiner Text und nicht mit dem gemeldeten Inhaltstyp: `text/xml` und
        // `application/json` sind für den Browser Dokumente, die er auslegt. Für
        // eine Vorschau, die in ein `<pre>` wandert, ist genau das nicht gewollt.
        return response($prefix, Response::HTTP_OK, $this->headers($attachment, 'text/plain; charset=utf-8', inline: true));
    }

    /**
     * Löscht einen Anhang.
     *
     * Dasselbe Recht wie die Zustandsaktionen am Fehler (`update`) und nicht das
     * Löschen des Fehlers: einen Screenshot wegzuwerfen, der personenbezogene
     * Daten zeigt, ist die Arbeit an der Fehlerliste und keine Verwaltungsaufgabe
     * — und wer sie erst beantragen muss, tut sie nicht.
     */
    public function destroy(Issue $issue, Event $event, EventAttachment $attachment): RedirectResponse
    {
        Gate::authorize('update', $issue);

        $this->ensureBelongsTo($issue, $event, $attachment);

        $this->store->delete($attachment);

        return back()->with('status', __('issues.attachments.flash.deleted', ['name' => $attachment->name]));
    }

    /**
     * Liefert die Datei aus — als Strom, damit ein Speicherabbild nicht erst
     * vollständig in den Speicher wandert.
     */
    private function deliver(EventAttachment $attachment, bool $inline): StreamedResponse
    {
        $stream = $this->store->stream($attachment);

        if ($stream === null) {
            // Die Zeile steht noch, die Datei ist weg — aufgeräumt, verschoben,
            // verloren. Für den Aufrufer ist das ein fehlender Anhang und kein
            // Serverfehler.
            throw new NotFoundHttpException;
        }

        $headers = $this->headers(
            $attachment,
            // Ein Inhaltstyp, den wir nicht eingeordnet haben, wird nicht
            // weitergegeben: er würde im Browser über die Auslegung entscheiden.
            $inline ? (string) $attachment->content_type : 'application/octet-stream',
            $inline,
        ) + ['Content-Length' => (string) $attachment->size];

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, $headers);
    }

    /**
     * Die Kopfzeilen einer Auslieferung.
     *
     * `Content-Disposition` trägt den Namen zweimal — einmal in ASCII und einmal
     * als `filename*`: ein Anhang heißt „Bestellübersicht.png", und ohne die
     * zweite Form kommt er als „Bestellbersicht.png" an.
     *
     * `X-Content-Type-Options: nosniff` steht auch an der Inline-Auslieferung:
     * ohne sie darf ein Browser den Inhalt beschnüffeln und ein als Bild
     * gemeldetes Dokument doch als HTML auslegen — womit die Einordnung beim
     * Ablegen umgangen wäre.
     *
     * @return array<string, string>
     */
    private function headers(EventAttachment $attachment, string $contentType, bool $inline): array
    {
        $name = $attachment->name;
        $ascii = (string) preg_replace('/[^\x20-\x7e]/', '_', $name);

        return [
            'Content-Type' => $contentType === '' ? 'application/octet-stream' : $contentType,
            'Content-Disposition' => sprintf(
                '%s; filename="%s"; filename*=UTF-8\'\'%s',
                $inline ? 'inline' : 'attachment',
                $ascii,
                rawurlencode($name),
            ),
            'X-Content-Type-Options' => 'nosniff',
            // Ein Anhang gehört zu einer Meldung und ändert sich nie. Er darf
            // deshalb im Browser liegen bleiben — aber nur dort: `private`, weil
            // die Datei zu einem Projekt gehört und kein Zwischenspeicher auf dem
            // Weg sie an den nächsten Betrachter geben darf.
            'Cache-Control' => 'private, max-age=3600',
        ];
    }

    /**
     * Darf der Betrachter das, und gehört das alles zusammen?
     */
    private function authorizeAccess(Issue $issue, Event $event, EventAttachment $attachment): void
    {
        Gate::authorize('view', $issue);

        $this->ensureBelongsTo($issue, $event, $attachment);
    }

    /**
     * Meldung am Fehler, Anhang an der Meldung — sonst 404.
     *
     * Nicht 403: ob es die Kennung überhaupt gibt, ist für den Aufrufer keine
     * Auskunft, auf die er Anspruch hat.
     */
    private function ensureBelongsTo(Issue $issue, Event $event, EventAttachment $attachment): void
    {
        if (! EventNavigation::belongsTo($issue, $event) || ! $attachment->belongsToEvent($event)) {
            throw new NotFoundHttpException;
        }
    }
}
