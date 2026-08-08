<?php

use App\Models\TransactionAggregate;
use App\Support\Performance\DurationHistogram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bringt die abgelegten Verteilungen auf **eine** Schreibweise: ein
     * JSON-Objekt, auch wenn die Klassen lückenlos bei null beginnen.
     *
     * Der Hintergrund ist eine Eigenheit von `json_encode`. Die Verteilung ist
     * ein Feld-Baum „Klasse → Häufigkeit" ({@see DurationHistogram::toArray()}),
     * also ein Feld mit Zahlen als Schlüssel. Sind diese Zahlen lückenlos und
     * beginnen bei null — `[0 => 2, 1 => 5]`, der Fall bei lauter sehr kurzen
     * Messungen —, schreibt PHP daraus eine JSON-**Liste** `[2,5]`; sonst ein
     * Objekt `{"7":2,"9":5}`. Dieselbe Zahl steht damit einmal unter `$."0"` und
     * einmal unter `$[0]`.
     *
     * Für PHP ist das gleichgültig, denn `DurationHistogram::fromStored()` liest
     * beides. Für die Performance-Übersicht ist es das nicht: sie legt die
     * Verteilungen eines Zeitraums **in der Datenbank** zusammen und liest dazu
     * je Klasse einen festen Pfad aus. Zwei Schreibweisen hießen dort zwei
     * Pfade je Klasse — und in MySQL wäre der zweite sogar gefährlich, weil
     * `$[0]` auf ein Objekt angewendet das ganze Objekt zurückgibt statt nichts.
     *
     * Neu geschriebene Zeilen sind seit {@see TransactionAggregate} immer
     * Objekte. Dieser Lauf holt nach, was vorher in Listenform abgelegt wurde.
     * Er ist wiederholbar: was schon ein Objekt ist, wird nicht angefasst.
     */
    public function up(): void
    {
        if (! Schema::hasTable('transaction_aggregates')) {
            return;
        }

        // In Blöcken und nicht in einem Rutsch: die Tabelle ist die größte der
        // Antwortzeiten, und eine Migration, die sie vollständig in den Speicher
        // holt, scheitert genau dort, wo sie am nötigsten wäre. Gelesen werden
        // nur die zwei Spalten, um die es geht.
        DB::table('transaction_aggregates')
            ->select(['id', 'duration_histogram'])
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $this->normalize($row->id, $row->duration_histogram);
                }
            });
    }

    /**
     * Schreibt eine einzelne Verteilung als Objekt zurück — sofern sie als Liste
     * dasteht und etwas enthält.
     */
    private function normalize(mixed $id, mixed $stored): void
    {
        if (! is_string($stored) || ! str_starts_with(ltrim($stored), '[')) {
            return;
        }

        $decoded = json_decode($stored, true);

        // Eine leere Liste bleibt, wie sie ist: `[]` und `{}` liefern beim
        // Auslesen dasselbe Nichts, und eine Zeile ohne Messungen ist keine
        // Änderung wert.
        if (! is_array($decoded) || $decoded === []) {
            return;
        }

        DB::table('transaction_aggregates')
            ->where('id', $id)
            ->update(['duration_histogram' => json_encode($decoded, JSON_FORCE_OBJECT)]);
    }

    /**
     * Ohne Gegenstück. Die Objektform ist für den älteren Stand kein Rückschritt
     * — `DurationHistogram::fromStored()` hat sie immer gelesen. Die Listenform
     * wiederherzustellen wäre Arbeit ohne Nutzen und mit Datenverlust-Risiko.
     */
    public function down(): void {}
};
