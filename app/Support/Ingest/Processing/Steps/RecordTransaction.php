<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\ProjectKey;
use App\Models\Transaction;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Ingest\Sampling\SamplingDecision;
use App\Support\Performance\TransactionEvent;
use App\Support\Performance\TransactionStore;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Legt eine gemeldete Transaktion samt Einzelschritten ab.
 *
 * Der Schritt fasst nur Meldungen des Typs `transaction` an und reicht alles
 * andere unverändert weiter. Das ist keine Sparsamkeit, sondern die Zusage aus
 * der Aufgabe: eine Transaktion ist kein Fehler und darf nirgends als solcher
 * auftauchen. Umgekehrt gilt dasselbe — die Schritte, die Fehler gruppieren und
 * zu Einträgen zusammenfassen (I5, I6), sehen hier keine Transaktion.
 *
 * Er sortiert **nicht** aus: eine Transaktion, die sich nicht deuten lässt, wird
 * gezählt und protokolliert, die Kette läuft weiter. Ein `drop()` wäre hier das
 * falsche Werkzeug — es beendet die Verarbeitung der ganzen Meldung, und
 * spätere Schritte (etwa das Erfassen der Version, R1) hätten mit derselben
 * Meldung noch etwas zu tun.
 *
 * Das Ergebnis liegt unter {@see RESULT} im Kontext, damit folgende Schritte
 * damit arbeiten können, ohne die Ablage erneut zu befragen.
 */
final class RecordTransaction implements ProcessingStep
{
    /**
     * Name, unter dem die abgelegte Transaktion im Kontext steht.
     */
    public const RESULT = 'transaction';

    public function __construct(
        private readonly TransactionStore $store,
    ) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;

        if ($payload->type !== IngestType::Transaction) {
            $next($context);

            return;
        }

        // Die Entscheidung der Stichprobe (I9), falls dieser Schritt in der Kette
        // vor uns steht. Sie wird nicht neu gefällt und auch nicht ersetzt: eine
        // Messung ist hier entweder schon ausgesondert — dann läuft die Kette
        // gar nicht bis hierher — oder sie bringt die Quoten mit, mit denen sie
        // später hochzurechnen ist.
        $decision = $context->get(SamplingDecision::CONTEXT_KEY);

        $transaction = $this->record(
            $payload,
            $context->data,
            $decision instanceof SamplingDecision ? $decision : null,
        );

        if ($transaction !== null) {
            $context->with(self::RESULT, $transaction);
        }

        $next($context);
    }

    /**
     * @param  array<mixed>|null  $data
     */
    private function record(IngestPayload $payload, ?array $data, ?SamplingDecision $decision): ?Transaction
    {
        $project = $payload->project;

        if ($data === null || $project === null) {
            // Ohne Projekt gibt es keinen Ort für die Messung. Das trifft nur
            // Meldungen, deren Projekt nach der Annahme gelöscht wurde — dann ist
            // das Wegfallen richtig und keine Meldung wert.
            return null;
        }

        $event = TransactionEvent::fromPayload($data, $payload->event_id, $this->maxSpans());

        if ($event === null) {
            $this->discard($payload, DiscardReason::Unreadable, 1, [
                'meldung' => 'Transaktion ohne verwertbaren Anfang oder Ende.',
            ]);

            return null;
        }

        // Was auf dem Weg liegen blieb, wird gezählt: ohne diese Zahlen wäre ein
        // abgeschnittener Ablauf in der Trace-Ansicht nicht von einem
        // vollständigen zu unterscheiden, und niemand käme auf die Idee, dass die
        // Anwendung zehntausend Abfragen für einen Aufruf macht.
        if ($event->excessSpans > 0) {
            $this->discard($payload, DiscardReason::TooManyItems, $event->excessSpans, [
                'grenze' => $this->maxSpans(),
                'transaktion' => $event->name,
            ]);
        }

        if ($event->unreadableSpans > 0) {
            $this->discard($payload, DiscardReason::Unreadable, $event->unreadableSpans, [
                'meldung' => 'Einzelschritt ohne Kennung oder ohne Zeitangaben.',
                'transaktion' => $event->name,
            ]);
        }

        return $this->store->store($event, $project, $payload, $decision);
    }

    /**
     * Wie viele Einzelschritte je Transaktion abgelegt werden.
     *
     * Nach oben begrenzt, und zwar nicht aus Vorsicht: die Zahl der abgelegten
     * Schritte und ihre Reihenfolge stehen in Spalten, die bis 65.535 reichen.
     * Ein größerer Wert in der Konfiguration würde dort überlaufen — und zwar
     * lautlos, mit falschen Zahlen als Ergebnis statt einer Fehlermeldung.
     */
    private function maxSpans(): int
    {
        return max(0, min(65_535, (int) config('ingest.performance.max_spans')));
    }

    /**
     * Zählt und protokolliert, was nicht abgelegt wurde — dieselbe Form wie bei
     * der Aufnahme, damit die Nutzungsstatistik (O3) beides nebeneinander
     * ausweisen kann.
     *
     * @param  array<string, mixed>  $context
     */
    private function discard(IngestPayload $payload, DiscardReason $reason, int $quantity, array $context): void
    {
        $key = $payload->key;

        if ($key instanceof ProjectKey) {
            IngestDiscard::server($key, $reason, IngestType::Transaction->value, $quantity);
        }

        Log::warning('Teil einer Transaktion nicht abgelegt: '.$reason->label(), $context + [
            'projekt' => $payload->project_id,
            'meldung' => $payload->id,
            'grund' => $reason->value,
            'anzahl' => $quantity,
        ]);
    }
}
