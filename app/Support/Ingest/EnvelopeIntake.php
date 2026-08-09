<?php

namespace App\Support\Ingest;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Enums\QuotaCategory;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\ProjectKey;
use App\Support\Crons\CheckInIntake;
use App\Support\Crons\CheckInPayload;
use App\Support\Ingest\Quotas\QuotaGuard;
use App\Support\Ingest\Spikes\SpikeGuard;
use Illuminate\Support\Facades\Log;

/**
 * Legt die Elemente eines zerlegten Envelope ab und schreibt mit, was dabei
 * unter den Tisch fällt.
 *
 * Die Leitlinie ist überall dieselbe: **ein Element gefährdet die anderen
 * nicht**. Ein unbekannter Typ, ein zu großer Anhang, ein Element ohne
 * lesbaren Kopf — jedes davon wird für sich verworfen, gezählt und
 * protokolliert; der Rest des Envelope geht seinen Weg, und die Antwort bleibt
 * 200. Der Grund ist die Gegenseite: ein SDK schickt einen abgewiesenen
 * Envelope nicht neu, und was hier verloren geht, ist ein Fehler, der in der
 * überwachten Anwendung bereits passiert ist.
 *
 * Ausgewertet wird nichts. Jedes Element landet als Rohdaten in derselben
 * Eingangsablage wie eine Meldung von `/store/`, nur mit seinem eigenen Typ;
 * was daraus wird, entscheidet die Verarbeitung (I3) und das jeweilige Feature.
 *
 * Eine Ausnahme gibt es: das Lebenszeichen eines Cronjobs (`check_in`) wird
 * zusätzlich sofort verarbeitet. Sein Wert ist ausschließlich zeitlicher Natur —
 * „hat sich gemeldet" ist eine Aussage über **jetzt**, und ein Ausfall, der
 * erst nach dem nächsten Durchlauf der Verarbeitung auffällt, ist eine
 * Meldung, die zu spät kommt. Abgelegt wird er trotzdem, wie alle anderen: die
 * Rohdaten bleiben die Grundlage, falls sich an der Auswertung etwas ändert.
 */
final class EnvelopeIntake
{
    /**
     * @param  int  $maxItems  Wie viele Elemente ein Envelope enthalten darf.
     * @param  int  $maxItemBytes  Obergrenze für ein JSON-Element.
     * @param  int  $maxAttachmentBytes  Obergrenze für Anhänge und Aufzeichnungen.
     * @param  CheckInIntake  $checkIns  Verarbeitung der Cronjob-Lebenszeichen.
     * @param  QuotaGuard  $quotas  Kontingente je Datenart (O1).
     * @param  SpikeGuard  $spikes  Der Ausschlag-Schutz (A7).
     */
    public function __construct(
        private readonly int $maxItems,
        private readonly int $maxItemBytes,
        private readonly int $maxAttachmentBytes,
        private readonly CheckInIntake $checkIns,
        private readonly QuotaGuard $quotas,
        private readonly SpikeGuard $spikes,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxItems: (int) config('ingest.envelope.max_items'),
            maxItemBytes: (int) config('ingest.envelope.max_item_bytes'),
            maxAttachmentBytes: (int) config('ingest.envelope.max_attachment_bytes'),
            checkIns: app(CheckInIntake::class),
            quotas: app(QuotaGuard::class),
            spikes: app(SpikeGuard::class),
        );
    }

    /**
     * Nimmt einen Envelope an und gibt die Nummer zurück, die in die Antwort
     * gehört — oder `null`, wenn er keine enthielt (ein Envelope aus lauter
     * Sitzungen hat keine).
     */
    public function accept(Envelope $envelope, ProjectKey $key, ?string $client): ?string
    {
        // Der Envelope-Kopf ist die genauere Quelle: dort steht das SDK, das
        // den Envelope gebaut hat. Die Zugangsdaten nennen es nur, wenn das SDK
        // sie entsprechend gefüllt hat.
        $sdk = $envelope->sdk() ?? $client;
        $envelopeEventId = $envelope->eventId();

        if ($envelope->unreadable > 0) {
            $this->discard($key, DiscardReason::Unreadable, null, $envelope->unreadable, [
                'meldung' => 'Envelope liess sich ab einer Stelle nicht weiterlesen.',
            ]);
        }

        foreach ($envelope->items as $position => $item) {
            if ($position >= $this->maxItems) {
                // Nicht Element für Element zählen: was hier abgeschnitten
                // wird, ist ein Block, und ein Zählwert je Stunde reicht.
                $this->discard($key, DiscardReason::TooManyItems, null, count($envelope->items) - $this->maxItems, [
                    'grenze' => $this->maxItems,
                    'enthalten' => count($envelope->items),
                ]);

                break;
            }

            $this->acceptItem($item, $key, $sdk, $envelopeEventId);
        }

        return $envelopeEventId;
    }

    private function acceptItem(EnvelopeItem $item, ProjectKey $key, ?string $sdk, ?string $envelopeEventId): void
    {
        $type = $item->type();

        if ($type === null) {
            // Beides zählt als „unbekannter Typ": ein Element ohne Typ ist für
            // uns genauso wenig zuzuordnen wie eines mit einem, den wir nicht
            // kennen.
            $this->discard($key, DiscardReason::UnknownType, $item->rawType(), 1, [
                'kopf' => array_keys($item->header),
            ]);

            return;
        }

        $limit = $type->isBinary() ? $this->maxAttachmentBytes : $this->maxItemBytes;

        if ($item->sizeBytes() > $limit) {
            $this->discard($key, DiscardReason::TooLarge, $type->value, 1, [
                'groesse' => $item->sizeBytes(),
                'grenze' => $limit,
            ]);

            return;
        }

        // Ein JSON-Element, das kein JSON-Objekt ist, kann niemand verarbeiten.
        // Binärelemente sind davon ausgenommen — bei ihnen ist genau das der
        // Normalfall.
        if (! $type->isBinary() && $item->decoded() === null) {
            $this->discard($key, DiscardReason::Unreadable, $type->value, 1, [
                'meldung' => 'Nutzdaten des Elements sind kein JSON-Objekt.',
            ]);

            return;
        }

        // Das Kontingent zuerst und erst hier: geprüft wird je Element und
        // nicht je Envelope, damit ein aufgebrauchtes Kontingent der einen
        // Datenart die anderen in derselben Anfrage nicht mitnimmt. Genau das
        // ist die Zusage, dass ein überzogenes Transaktions-Kontingent
        // Fehlermeldungen nicht härter trifft als eingestellt.
        //
        // Vor dem Ausschlag-Schutz, weil es dieselbe Reihenfolge ist wie auf dem
        // klassischen Weg: dort steht die Prüfung als Middleware am Eingang und
        // der Schutz im Controller dahinter. Was das kostet, steht in
        // {@see QuotaGuard::consume()} — gezählt wird, was ankommt, nicht, was
        // am Ende gespeichert wurde.
        $verdict = $this->quotas->admit($key, QuotaCategory::forIngestType($type));

        if ($verdict->denied()) {
            $this->discard($key, $verdict->reason ?? DiscardReason::RateLimited, $verdict->discardCategory() ?? $type->value, 1, [
                'ebene' => $verdict->scope?->value,
                'wartezeit' => $verdict->retryAfter,
            ]);

            return;
        }

        // Der Ausschlag-Schutz (A7). Je Element und nicht je Envelope: was in
        // einer Anfrage zusammensteckt, ist inhaltlich unabhängig, und er trifft
        // ohnehin nur die Elemente, die als Ereignis zählen — ein Lebenszeichen,
        // eine Verworfen-Meldung des SDK und ein Anhang gehen weiter durch. Die
        // Auskunft darüber wegzuwerfen, während wir wegwerfen, wäre widersinnig.
        if (! $this->spikes->allows($key, $type)) {
            return;
        }

        $accepted = IngestPayload::accept(
            key: $key,
            eventId: $this->eventIdFor($item, $envelopeEventId),
            payload: $item->payload,
            type: $type,
            sdk: $sdk,
            itemHeaders: $item->header,
        );

        // Je Element ein eigener Job, nicht einer für den ganzen Envelope: die
        // Elemente sind voneinander unabhängig, und ein Anhang, dessen
        // Auswertung scheitert, darf die Fehlermeldung nicht mitreißen, mit der
        // er zusammen gesendet wurde.
        ProcessIngestPayload::dispatch($accepted)->delay($this->delayFor($type));

        if ($type === IngestType::ClientReport) {
            $this->countClientReport($item, $key);
        }

        if ($type === IngestType::CheckIn) {
            $this->acceptCheckIn($item, $key);
        }
    }

    /**
     * Wie lange der Job eines Elements wartet, bevor er anläuft.
     *
     * Für fast alles: gar nicht. Die eine Ausnahme ist das Sample-Profil (M4).
     * Es wird an der Transaktion abgelegt, die es vermessen hat, und beide
     * stecken zwar im selben Envelope, laufen danach aber als zwei unabhängige
     * Jobs — welcher zuerst drankommt, hängt an der Zahl der Arbeiter und nicht
     * an der Reihenfolge hier. Ohne Vorsprung sucht das Profil seine
     * Transaktion mitunter, bevor sie abgelegt ist, und wird als `orphaned`
     * verworfen.
     *
     * Das ist bewusst eine Wartezeit und keine Warteschleife im Profil-Job
     * selbst: eine Wiederholung, die auf einen anderen Job hofft, hält einen
     * Arbeiter besetzt, und bei einem Ausfall der Transaktion hielte sie ihn
     * bis zum letzten Versuch besetzt.
     */
    private function delayFor(IngestType $type): int
    {
        return $type === IngestType::Profile
            ? max(0, (int) config('ingest.profiling.dispatch_delay_seconds'))
            : 0;
    }

    /**
     * Reicht ein Lebenszeichen an die Cronjob-Überwachung weiter.
     *
     * Fehler dabei bleiben hier: ein Check-in kommt aus einem Job, der gerade
     * läuft, und steckt fast immer in einem Envelope mit weiteren Elementen. Ein
     * unbekannter Monitor darf weder die Fehlermeldung daneben verhindern noch
     * die Antwort verderben — die Meldung ins Protokoll übernimmt die
     * Verarbeitung selbst.
     */
    private function acceptCheckIn(EnvelopeItem $item, ProjectKey $key): void
    {
        $decoded = $item->decoded();

        if ($decoded === null) {
            return;
        }

        $key->loadMissing('project');

        if ($key->project === null) {
            return;
        }

        $this->checkIns->accept($key->project, CheckInPayload::fromArray($decoded));
    }

    /**
     * Die Nummer, unter der ein Element geführt wird.
     *
     * Vorrang hat die aus dem Element selbst — nur ein Fehler oder eine
     * Transaktion bringt eine mit. Alles andere erbt die des Envelope: so
     * findet ein Anhang später zu der Meldung, mit der er gesendet wurde. Bleibt
     * beides leer (ein Envelope aus Sitzungen), wird eine vergeben, damit der
     * Datensatz eine Kennung hat.
     */
    private function eventIdFor(EnvelopeItem $item, ?string $envelopeEventId): string
    {
        return IngestPayload::normalizeEventId($item->eventId())
            ?? $envelopeEventId
            ?? IngestPayload::freshEventId();
    }

    /**
     * Übernimmt die Zahlen aus einer Verworfen-Meldung des SDK in die eigene
     * Statistik.
     *
     * Der Rumpf sieht so aus:
     *
     *     {"timestamp":"…","discarded_events":[
     *       {"reason":"queue_overflow","category":"error","quantity":23}
     *     ]}
     *
     * Ohne diesen Schritt bliebe die Meldung ein Datensatz, den niemand liest —
     * sie ist aber die einzige Auskunft darüber, was das SDK gar nicht erst
     * abgeschickt hat. Genau diese Lücke erklärt, warum in der Oberfläche
     * weniger Fehler stehen, als in der überwachten Anwendung passiert sind.
     * Die Darstellung der Zahlen ist Sache der Nutzungsstatistik (O3).
     */
    private function countClientReport(EnvelopeItem $item, ProjectKey $key): void
    {
        $report = $item->decoded();
        $discarded = $report === null ? null : ($report['discarded_events'] ?? null);

        if (! is_array($discarded)) {
            return;
        }

        foreach ($discarded as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $reason = $entry['reason'] ?? null;
            $category = $entry['category'] ?? null;
            $quantity = $entry['quantity'] ?? null;

            if (! is_string($reason) || ! is_int($quantity)) {
                continue;
            }

            IngestDiscard::client(
                key: $key,
                reason: $reason,
                category: is_string($category) ? $category : null,
                quantity: $quantity,
            );
        }
    }

    /**
     * Zählen und protokollieren in einem — beides gehört zusammen: die Zahl
     * sagt, wie oft es passiert, die Protokollzeile sagt, was genau.
     *
     * @param  array<string, mixed>  $context
     */
    private function discard(
        ProjectKey $key,
        DiscardReason $reason,
        ?string $category,
        int $quantity,
        array $context = [],
    ): void {
        if ($quantity < 1) {
            return;
        }

        IngestDiscard::server($key, $reason, $category, $quantity);

        Log::warning('Envelope-Element verworfen: '.$reason->label(), $context + [
            'projekt' => $key->project_id,
            'schluessel' => $key->id,
            'grund' => $reason->value,
            'typ' => $category,
            'anzahl' => $quantity,
        ]);
    }
}
