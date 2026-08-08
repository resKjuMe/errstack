<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Die Spur an der Fehlermeldung: zwei Spalten, damit ein Fehler und der
     * Ablauf, in dem er auftrat, zueinander finden.
     *
     * Die Angaben stehen bereits in `contexts.trace` — dort abgelegt von der
     * Normalisierung (I4), die dieses eine Fach ausdrücklich mit festen Feldern
     * versieht. Als Spalten stehen sie hier ein zweites Mal, und das ist
     * Absicht: die Trace-Ansicht (PF4) fragt „welche Fehler gehören zu dieser
     * Spur", und diese Frage über ein JSON-Fach zu stellen hieße, für jede
     * Trace-Ansicht die ganze Ereignistabelle zu lesen. Ein Index über einen
     * JSON-Ausdruck wäre die Alternative — er ist in MySQL und SQLite
     * verschieden zu schreiben, und die Anwendung läuft auf beiden.
     *
     * Der Index trägt bewusst kein `project_id` vorneweg: eine Spur führt über
     * mehrere Dienste und damit über mehrere Projekte, und genau darum geht es
     * bei ihr. Dieselbe Entscheidung wie bei `transactions.trace_id`.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Nullable, weil eine Spur nicht garantiert ist: ein SDK ohne
            // Performance-Aufzeichnung schickt keine, und eine Meldung aus
            // einem Cron-Lauf hat keinen Aufruf, zu dem sie gehören könnte.
            $table->char('trace_id', 32)->nullable()->after('event_id');

            // Der Schritt, in dem der Fehler auftrat. Er ist der Grund, warum
            // die Trace-Ansicht den Fehler an der richtigen Stelle markieren
            // kann und nicht nur „irgendwo in diesem Ablauf".
            $table->char('trace_span_id', 16)->nullable()->after('trace_id');

            $table->index('trace_id');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['trace_id']);
            $table->dropColumn(['trace_id', 'trace_span_id']);
        });
    }

    /**
     * Die Spalten aus dem füllen, was schon da ist.
     *
     * Ohne diesen Schritt wäre die Trace-Ansicht für den gesamten vorhandenen
     * Bestand leer — und zwar unauffällig leer: die Fehler stünden weiter in
     * ihren Listen, nur fände sie kein Ablauf. Gelesen wird der Feld-Baum in
     * PHP und nicht per JSON-Ausdruck in SQL, weil dieselbe Migration auf
     * MySQL und SQLite laufen muss.
     */
    private function backfill(): void
    {
        DB::table('events')
            ->select(['id', 'contexts'])
            ->whereNotNull('contexts')
            ->orderBy('id')
            // In Blöcken, weil eine gewachsene Ereignistabelle nicht in den
            // Speicher passt. `chunkById` und nicht `chunk`: der Bestand wird
            // während der Migration weiter beschrieben, und eine Seitenzahl
            // verschöbe sich dabei.
            ->chunkById(500, function (iterable $rows): void {
                foreach ($rows as $row) {
                    $trace = self::traceOf($row->contexts);

                    if ($trace === null) {
                        continue;
                    }

                    DB::table('events')->where('id', $row->id)->update($trace);
                }
            });
    }

    /**
     * Die Spur-Kennungen aus einem abgelegten `contexts`-Feld-Baum.
     *
     * @return array{trace_id: string, trace_span_id: string|null}|null
     */
    private static function traceOf(mixed $contexts): ?array
    {
        if (! is_string($contexts)) {
            return null;
        }

        $decoded = json_decode($contexts, true);

        if (! is_array($decoded) || ! isset($decoded['trace']) || ! is_array($decoded['trace'])) {
            return null;
        }

        $traceId = $decoded['trace']['trace_id'] ?? null;
        $spanId = $decoded['trace']['span_id'] ?? null;

        if (! is_string($traceId) || $traceId === '') {
            return null;
        }

        return [
            'trace_id' => $traceId,
            'trace_span_id' => is_string($spanId) && $spanId !== '' ? $spanId : null,
        ];
    }
};
