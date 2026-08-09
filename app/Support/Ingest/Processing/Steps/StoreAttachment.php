<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Models\EventAttachment;
use App\Models\IngestPayload;
use App\Support\Attachments\AttachmentStore;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Legt einen angenommenen Anhang in der Dateiablage ab und hängt ihn an seine
 * Meldung.
 *
 * Der Schritt fasst nur Meldungen des Typs `attachment` an und reicht alles
 * andere unverändert weiter — dasselbe Muster wie {@see RecordProfile} und
 * {@see RecordUserReport}.
 *
 * **Er steht hinter dem Schwärzen und vor allem, was Fehler auswertet.** Hinter
 * dem Schwärzen, weil dort die Entscheidung fällt, ob das Projekt Anhänge
 * überhaupt speichert ({@see ScrubEvent}) — an einer Datei gibt es nichts zu
 * schwärzen, sie ist entweder erlaubt oder nicht. Vor der Auswertung, weil ein
 * Anhang keine Fehlermeldung ist und in keiner Fehlerliste auftauchen darf.
 *
 * **Er sortiert nicht aus, wenn die Meldung noch fehlt.** Das ist der Unterschied
 * zum Sample-Profil, und er ist beabsichtigt: ein Anhang kommt als eigenes
 * Element mit eigenem Job und trifft regelmäßig vor der Meldung ein, zu der er
 * gehört. Er wird deshalb unter deren Nummer abgelegt und beim Aufschlagen der
 * Fehlerseite darüber gefunden — ein Profil dagegen braucht die Transaktion, an
 * der es hängt, weil es ohne sie nichts aussagt.
 *
 * **Zwei Grenzen greifen hier und nicht bei der Annahme.** Die Annahme kennt nur
 * den Envelope; erst hier ist das Projekt bekannt. Was eine Grenze reißt, wird für
 * sich verworfen und gezählt — die Meldung, zu der der Anhang gehört, ist ein
 * eigenes Element und kommt trotzdem an. Der Grund steht in der
 * Verworfen-Statistik, damit ein fehlender Screenshot erklärbar bleibt statt still
 * zu verschwinden.
 */
final class StoreAttachment implements ProcessingStep
{
    /** Name, unter dem der abgelegte Anhang im Kontext steht. */
    public const RESULT = 'attachment';

    public function __construct(
        private readonly AttachmentStore $store,
    ) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;

        if ($payload->type !== IngestType::Attachment) {
            $next($context);

            return;
        }

        $stored = EventAttachment::query()
            ->where('ingest_payload_id', $payload->id)
            ->first();

        if ($stored !== null) {
            // Zweiter Durchlauf desselben Belegs: die Warteschlange darf einen Job
            // erneut ausliefern. Er endet hier, damit die Mengenprüfung darunter
            // nicht den eigenen Anhang mitzählt und ihn an einer ausgeschöpften
            // Meldung als „zu viele" abweist.
            $context->with(self::RESULT, $stored);

            $next($context);

            return;
        }

        if ($payload->project === null) {
            // Das Projekt wurde nach der Annahme gelöscht — dann gibt es keinen
            // Ort für die Datei und niemanden, der sie ansehen würde.
            $context->drop(DiscardReason::Unreadable, IngestType::Attachment->value);

            return;
        }

        $maxBytes = max(1, (int) config('attachments.max_bytes'));

        if ($payload->size_bytes > $maxBytes) {
            $this->reject($payload, DiscardReason::TooLarge, 'Anhang über der Größengrenze des Betreibers.', [
                'groesse' => $payload->size_bytes,
                'grenze' => $maxBytes,
            ]);

            $context->drop(DiscardReason::TooLarge, IngestType::Attachment->value);

            return;
        }

        $content = $payload->bytes();

        if ($content === '' && $payload->size_bytes > 0) {
            // Der Beleg sagt, er trage Daten, liefert aber keine: die Base64-Spalte
            // ist beschädigt ({@see IngestPayload::bytes()} gibt dann eine leere
            // Zeichenkette zurück). Ohne diese Prüfung entstünde daraus ein
            // 0-Byte-Anhang mit funktionierendem Download — und niemand könnte
            // sehen, dass der Screenshot verloren ist.
            $this->reject($payload, DiscardReason::Unreadable, 'Nutzdaten des Anhangs ließen sich nicht entpacken.', [
                'groesse' => $payload->size_bytes,
            ]);

            $context->drop(DiscardReason::Unreadable, IngestType::Attachment->value);

            return;
        }

        $maxPerEvent = max(1, (int) config('attachments.max_per_event'));
        $existing = $this->store->countFor($payload->project_id, $payload->event_id);

        // Eine gezählte Grenze und keine erzwungene: zwei Arbeiter, die im selben
        // Augenblick zwei Anhänge derselben Meldung ablegen, sehen beide denselben
        // Stand und kommen beide durch. Das ist in Kauf genommen — die Grenze wehrt
        // ein SDK ab, das in einer Schleife hundert Dateien schickt, und dafür
        // genügt sie. Ein Sperren je Ereignisnummer wäre ein Schloss im heißen
        // Aufnahmeweg, um eine Anzeige um zwei Bilder genauer zu machen.
        if ($existing >= $maxPerEvent) {
            $this->reject($payload, DiscardReason::TooManyItems, 'Meldung trägt schon die erlaubte Zahl an Anhängen.', [
                'vorhanden' => $existing,
                'grenze' => $maxPerEvent,
            ]);

            $context->drop(DiscardReason::TooManyItems, IngestType::Attachment->value);

            return;
        }

        $attachment = $this->store->store($payload, $content);

        if ($attachment !== null) {
            $context->with(self::RESULT, $attachment);
        }

        $next($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reject(IngestPayload $payload, DiscardReason $reason, string $message, array $context = []): void
    {
        Log::warning('Anhang nicht abgelegt: '.$message, $context + [
            'projekt' => $payload->project_id,
            'meldung' => $payload->id,
            'ereignis' => $payload->event_id,
            'grund' => $reason->value,
        ]);
    }
}
