<?php

namespace App\Support\Ingest\Processing;

use App\Enums\ProcessingState;
use App\Enums\QueueName;
use App\Models\IngestPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Die Zahlen, an denen sich ablesen lässt, ob die Verarbeitung mitkommt.
 *
 * Zwei Größen zusammen, weil einzeln keine von beiden etwas sagt: der
 * **Rückstand** wächst auch dann, wenn jeder einzelne Durchlauf schnell ist —
 * dann sind es zu viele Meldungen. Die **Dauer** kann gut aussehen, während
 * niemand die Warteschlange abarbeitet. Erst nebeneinander zeigen sie, ob mehr
 * Arbeiter nötig sind oder ein Schritt langsam geworden ist.
 *
 * Die Auswertung in der Oberfläche gehört zur Nutzungsstatistik (O3); hier
 * stehen nur die Zahlen, damit `ingest:status` und später jene Ansichten
 * dieselben verwenden.
 */
final class ProcessingMetrics
{
    /**
     * Wie viele Meldungen auf ihre Auswertung warten.
     */
    public function backlog(): int
    {
        return IngestPayload::query()->awaitingProcessing()->count();
    }

    /**
     * Seit wann die älteste unbearbeitete Meldung wartet, in Sekunden.
     *
     * Die aussagekräftigere Hälfte des Rückstands: 10.000 wartende Meldungen
     * sind harmlos, wenn die älteste zwei Sekunden alt ist, und ein Notfall,
     * wenn sie von gestern ist.
     */
    public function oldestPendingSeconds(): ?int
    {
        $oldest = IngestPayload::query()
            ->awaitingProcessing()
            ->min('created_at');

        if (! is_string($oldest)) {
            return null;
        }

        // Carbon rechnet auf Bruchteile genau; für die Frage „seit wann liegt
        // das hier?" sind ganze Sekunden die passende Auflösung.
        return (int) round(Carbon::parse($oldest)->diffInSeconds(absolute: true));
    }

    /**
     * Wie viele Meldungen endgültig gescheitert sind.
     */
    public function failed(): int
    {
        return IngestPayload::query()->failedProcessing()->count();
    }

    /**
     * Wie viele Jobs in der Ingest-Warteschlange stehen.
     *
     * Nicht dasselbe wie der Rückstand: der zählt Meldungen ohne Ergebnis, das
     * hier zählt Jobs, die noch abzuholen sind. Gehen sie auseinander, ist
     * genau das die Auskunft — etwa nach einem verlorenen Job, der nie lief.
     *
     * Nicht jede Warteschlangen-Anbindung kann zählen; kann sie es nicht, ist
     * das keine Störung, sondern eine Auskunft weniger.
     */
    public function queued(): ?int
    {
        return $this->queueSizes()[QueueName::Ingest->value];
    }

    /**
     * Dasselbe für alle Warteschlangen der Anwendung.
     *
     * Erst nebeneinander sind die Zahlen zu gebrauchen: steht die Aufnahme
     * still, während die Benachrichtigungen leer sind, fehlen Arbeiter für
     * `ingest`; stauen sich alle gleichzeitig, läuft überhaupt keiner mehr.
     *
     * @return array<string, int|null>
     */
    public function queueSizes(): array
    {
        $sizes = [];

        foreach (QueueName::cases() as $queue) {
            try {
                $sizes[$queue->value] = Queue::size($queue->value);
            } catch (Throwable) {
                $sizes[$queue->value] = null;
            }
        }

        return $sizes;
    }

    /**
     * Verarbeitungsdauern der jüngsten Durchläufe.
     *
     * Der Mittelwert allein verdeckt genau den Fall, um den es geht: laufen 99
     * Meldungen in 5 ms und eine in 30 Sekunden, steht da eine knappe
     * dreihundertstel Sekunde. Deshalb zusätzlich das langsamste Zwanzigstel
     * und der Höchstwert.
     *
     * Gerechnet wird über eine begrenzte Zahl jüngster Durchläufe statt über
     * alle: die Tabelle wächst mit jeder Meldung, die Frage aber lautet „wie
     * schnell ist es **gerade**".
     *
     * @return array{count: int, avg_ms: int|null, p95_ms: int|null, max_ms: int|null}
     */
    public function durations(int $sample = 1000): array
    {
        $durations = IngestPayload::query()
            ->where('processing_state', ProcessingState::Processed)
            ->whereNotNull('duration_ms')
            ->orderByDesc('processed_at')
            ->limit($sample)
            ->pluck('duration_ms')
            ->all();

        return self::summarize($durations);
    }

    /**
     * Wie lange es von der Annahme bis zur Sichtbarkeit dauert.
     *
     * Die andere Hälfte der Wahrheit neben {@see durations()}: die misst, wie
     * lange die Kette *rechnet*, diese hier, wie lange eine Meldung insgesamt
     * unterwegs ist — Wartezeit in der Warteschlange eingeschlossen. Das ist
     * die Zahl, die der Nutzer merkt. Sie geht auseinander, sobald zu wenige
     * Arbeiter laufen: jeder einzelne Durchlauf bleibt schnell, die Meldung
     * erscheint trotzdem erst Minuten später.
     *
     * @return array{count: int, avg_ms: int|null, p95_ms: int|null, max_ms: int|null}
     */
    public function latency(int $sample = 1000): array
    {
        $rows = IngestPayload::query()
            ->where('processing_state', ProcessingState::Processed)
            ->whereNotNull('processed_at')
            ->orderByDesc('processed_at')
            ->limit($sample)
            ->get(['created_at', 'processed_at']);

        $latencies = $rows
            // Negative Werte kann es nicht geben; ein Uhrensprung auf dem
            // Server erzeugt sie trotzdem. Auf null gedeckelt, damit ein
            // einzelner Ausreißer nicht den Mittelwert nach unten zieht.
            ->map(fn (IngestPayload $payload): int => max(0, (int) round(
                $payload->created_at->diffInMilliseconds($payload->processed_at, absolute: false),
            )))
            ->all();

        return self::summarize($latencies);
    }

    /**
     * Mittelwert, langsamstes Zwanzigstel und Höchstwert einer Messreihe.
     *
     * @param  list<int>  $values
     * @return array{count: int, avg_ms: int|null, p95_ms: int|null, max_ms: int|null}
     */
    private static function summarize(array $values): array
    {
        $count = count($values);

        if ($count === 0) {
            return ['count' => 0, 'avg_ms' => null, 'p95_ms' => null, 'max_ms' => null];
        }

        sort($values);

        // Nächstgelegener Rang: der kleinste Wert, unter oder auf dem 95 % der
        // Durchläufe liegen. `ceil` bestimmt den Rang, der Abzug macht daraus
        // den Platz in der bei null beginnenden Liste.
        $index = max(0, (int) ceil($count * 0.95) - 1);

        return [
            'count' => $count,
            'avg_ms' => (int) round(array_sum($values) / $count),
            'p95_ms' => $values[$index],
            'max_ms' => $values[$count - 1],
        ];
    }

    /**
     * Die Meldungen je Verarbeitungszustand.
     *
     * @return array<string, int>
     */
    public function states(): array
    {
        /** @var array<string, int> $counts */
        $counts = IngestPayload::query()
            ->selectRaw('processing_state, count(*) as total')
            ->groupBy('processing_state')
            ->pluck('total', 'processing_state')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $states = [];

        foreach (ProcessingState::cases() as $state) {
            $states[$state->value] = $counts[$state->value] ?? 0;
        }

        return $states;
    }
}
