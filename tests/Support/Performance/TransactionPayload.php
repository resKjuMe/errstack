<?php

namespace Tests\Support\Performance;

use App\Models\IngestPayload;

/**
 * Baut den Rumpf einer Transaktions-Meldung so, wie ein Sentry-SDK ihn schickt.
 *
 * An einer Stelle, weil das Schema die Prüfung ist: läge die Form in jedem Test
 * einzeln, würde ein Tippfehler im Feldnamen dort mitwandern, wo er sich versteckt
 * — und der Test bestünde gegen einen Rumpf, den es im Feld nicht gibt.
 */
final class TransactionPayload
{
    /**
     * @param  array<string, mixed>  $overrides  Felder, die der Test abweichend setzt.
     * @param  list<mixed>  $spans  Absichtlich nicht auf Feld-Bäume festgelegt: was
     *                              ein SDK unter `spans` schickt, ist ungeprüft, und
     *                              genau das müssen Tests hier hineingeben können.
     * @return array<string, mixed>
     */
    public static function make(array $overrides = [], array $spans = []): array
    {
        $eventId = IngestPayload::freshEventId();
        $startedAt = 1_770_000_000.100000;

        $body = [
            'event_id' => $eventId,
            'type' => 'transaction',
            'transaction' => 'GET /projects',
            'transaction_info' => ['source' => 'route'],
            'platform' => 'php',
            'environment' => 'production',
            'release' => 'errstack@1.2.3',
            'start_timestamp' => $startedAt,
            'timestamp' => $startedAt + 1.5,
            'contexts' => [
                'trace' => [
                    'trace_id' => '1234567890abcdef1234567890abcdef',
                    'span_id' => 'abcdef1234567890',
                    'op' => 'http.server',
                    'status' => 'ok',
                ],
            ],
            'user' => ['id' => '4711'],
            'spans' => $spans,
        ];

        return $overrides + $body;
    }

    /**
     * Ein Einzelschritt, wie er in `spans` steht.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function span(string $spanId, float $startedAt, float $durationSeconds, array $overrides = []): array
    {
        return $overrides + [
            'span_id' => $spanId,
            'parent_span_id' => 'abcdef1234567890',
            'trace_id' => '1234567890abcdef1234567890abcdef',
            'op' => 'db.sql.query',
            'description' => 'SELECT * FROM projects WHERE id = ?',
            'status' => 'ok',
            'start_timestamp' => $startedAt,
            'timestamp' => $startedAt + $durationSeconds,
            'data' => ['db.system' => 'mysql'],
        ];
    }
}
