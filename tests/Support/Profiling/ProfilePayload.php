<?php

namespace Tests\Support\Profiling;

use App\Models\IngestPayload;
use Tests\Support\Performance\TransactionPayload;

/**
 * Baut den Rumpf einer Profil-Meldung so, wie ein Sentry-SDK ihn schickt.
 *
 * An einer Stelle, aus demselben Grund wie bei den Transaktionen
 * ({@see TransactionPayload}): das Schema **ist** die
 * Prüfung. Läge die Form in jedem Test einzeln, würde ein Tippfehler im
 * Feldnamen dort mitwandern, wo er sich versteckt — und der Test bestünde gegen
 * einen Rumpf, den es im Feld nicht gibt.
 *
 * Besonders leicht falsch zu machen und deshalb hier festgehalten: die Stapel
 * stehen **von innen nach außen** (der zuerst genannte Rahmen ist der gerade
 * laufende), und die Stichproben tragen einen Zeitpunkt, keine Dauer.
 */
final class ProfilePayload
{
    /**
     * Der Abstand zwischen zwei Stichproben in den erzeugten Profilen.
     *
     * Zehn Millisekunden — die übliche Abtastrate. Daraus folgt die
     * Erwartung jedes Tests, der über Zeiten rechnet: n Stichproben derselben
     * Funktion ergeben n × 10 ms, die letzte eingeschlossen (sie bekommt den
     * mittleren Abstand).
     */
    public const INTERVAL_NS = 10_000_000;

    /**
     * @param  list<list<string>>  $stacks  Je Stichprobe der Aufrufstapel **von außen nach innen** — so, wie man ihn liest. Gedreht wird hier.
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function make(string $transactionEventId, array $stacks, array $overrides = []): array
    {
        $frames = [];
        $index = [];
        $stackTable = [];
        $samples = [];

        foreach ($stacks as $position => $stack) {
            $entry = [];

            foreach ($stack as $function) {
                if (! isset($index[$function])) {
                    $index[$function] = count($frames);
                    $frames[] = [
                        'function' => $function,
                        'filename' => 'app/'.$function.'.php',
                        'lineno' => 10 + $index[$function],
                        'in_app' => true,
                    ];
                }

                $entry[] = $index[$function];
            }

            // Gedreht: das Schema will die innerste Funktion zuerst.
            $stackTable[] = array_reverse($entry);

            $samples[] = [
                'stack_id' => $position,
                'thread_id' => '1',
                'elapsed_since_start_ns' => $position * self::INTERVAL_NS,
            ];
        }

        return $overrides + [
            'event_id' => IngestPayload::freshEventId(),
            'version' => '1',
            'platform' => 'php',
            'environment' => 'production',
            'release' => 'errstack@1.2.3',
            'timestamp' => 1_770_000_000.100000,
            'transaction' => [
                'id' => $transactionEventId,
                'name' => 'GET /projects',
                'trace_id' => '1234567890abcdef1234567890abcdef',
                'active_thread_id' => '1',
            ],
            'profile' => [
                'frames' => $frames,
                'stacks' => $stackTable,
                'samples' => $samples,
                'thread_metadata' => ['1' => ['name' => 'main']],
            ],
        ];
    }
}
