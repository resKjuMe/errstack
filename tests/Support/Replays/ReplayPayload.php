<?php

namespace Tests\Support\Replays;

use App\Models\IngestPayload;

/**
 * Baut die beiden Hälften einer Sitzungs-Aufzeichnung so, wie ein
 * Sentry-Browser-SDK sie schickt.
 *
 * An einer Stelle, aus demselben Grund wie bei den Profilen
 * ({@see Tests\Support\Profiling\ProfilePayload}): das Schema **ist** die
 * Prüfung. Läge die Form in jedem Test einzeln, würde ein Tippfehler im
 * Feldnamen dort mitwandern, wo er sich versteckt.
 *
 * Zwei Dinge sind an diesem Format besonders leicht falsch zu machen und stehen
 * deshalb hier festgehalten:
 *
 *   1. **rrweb zählt in Millisekunden**, die übrigen SDK-Felder in Sekunden mit
 *      Bruchteilen. Beide Einheiten stehen in derselben Meldung nebeneinander.
 *   2. **Der Abschnitt beginnt mit einer Kopfzeile**, danach folgt der gepackte
 *      Datenstrom — ohne Trennzeichen außer dem einen Zeilenumbruch.
 */
final class ReplayPayload
{
    /**
     * rrweb-Ereignisart „Kopfdaten" (Adresse, Fenstergröße).
     */
    public const TYPE_META = 4;

    /**
     * rrweb-Ereignisart „eigenes Ereignis" — die Hülle, in der das SDK
     * Breadcrumbs, Netzwerkmessungen und seine eigenen Einstellungen mitschickt.
     */
    public const TYPE_CUSTOM = 5;

    /**
     * Die Kopfdaten (`replay_event`).
     *
     * @param  list<string>  $errorIds
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function header(string $replayId, array $errorIds = [], array $overrides = []): array
    {
        $startedAt = now()->subSeconds(30);

        return array_replace([
            'type' => 'replay_event',
            'replay_id' => $replayId,
            'event_id' => $replayId,
            'segment_id' => 0,
            'replay_type' => 'session',
            'timestamp' => now()->getTimestamp(),
            'replay_start_timestamp' => $startedAt->getTimestamp(),
            'platform' => 'javascript',
            'environment' => 'production',
            'release' => '1.4.0',
            'urls' => ['https://example.com/kasse'],
            'error_ids' => $errorIds,
            'trace_ids' => [],
            'sdk' => ['name' => 'sentry.javascript.browser', 'version' => '8.42.0'],
            'user' => ['id' => '4711'],
            'contexts' => [
                'browser' => ['name' => 'Chrome', 'version' => '120'],
                'os' => ['name' => 'Windows', 'version' => '11'],
            ],
        ], $overrides);
    }

    /**
     * Ein Abschnitt (`replay_recording`) — Kopfzeile, Zeilenumbruch, gepackte
     * rrweb-Ereignisse.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public static function recording(int $segmentId, array $events, bool $compress = true): string
    {
        $json = (string) json_encode($events);

        return (string) json_encode(['segment_id' => $segmentId])
            ."\n"
            .($compress ? (string) gzcompress($json) : $json);
    }

    /**
     * Ein brauchbarer Satz rrweb-Ereignisse: Seitenaufruf, Klick, Konsolenzeile,
     * Netzwerkanfrage — und die Einstellungen des SDK.
     *
     * @return list<array<string, mixed>>
     */
    public static function events(int $startMs, bool $masked = true): array
    {
        return [
            [
                'type' => self::TYPE_CUSTOM,
                'timestamp' => $startMs,
                'data' => [
                    'tag' => 'options',
                    'payload' => [
                        'maskAllText' => $masked,
                        'maskAllInputs' => $masked,
                        'blockAllMedia' => true,
                    ],
                ],
            ],
            [
                'type' => self::TYPE_META,
                'timestamp' => $startMs + 10,
                'data' => ['href' => 'https://example.com/kasse', 'width' => 1280, 'height' => 800],
            ],
            [
                'type' => self::TYPE_CUSTOM,
                'timestamp' => $startMs + 1_000,
                'data' => [
                    'tag' => 'breadcrumb',
                    'payload' => [
                        'type' => 'default',
                        'category' => 'ui.click',
                        'message' => 'button#bezahlen',
                        // Sekunden mit Bruchteilen — anders als der Rahmen
                        // darüber, der in Millisekunden zählt.
                        'timestamp' => ($startMs + 1_000) / 1000,
                    ],
                ],
            ],
            [
                'type' => self::TYPE_CUSTOM,
                'timestamp' => $startMs + 2_000,
                'data' => [
                    'tag' => 'breadcrumb',
                    'payload' => [
                        'type' => 'default',
                        'category' => 'console',
                        'level' => 'error',
                        'message' => 'Zahlung fehlgeschlagen',
                        'timestamp' => ($startMs + 2_000) / 1000,
                        'data' => ['arguments' => ['code', 402]],
                    ],
                ],
            ],
            [
                'type' => self::TYPE_CUSTOM,
                'timestamp' => $startMs + 2_500,
                'data' => [
                    'tag' => 'performanceSpan',
                    'payload' => [
                        'op' => 'resource.fetch',
                        'description' => 'https://example.com/api/zahlung',
                        'startTimestamp' => ($startMs + 2_100) / 1000,
                        'endTimestamp' => ($startMs + 2_500) / 1000,
                        'data' => ['method' => 'POST', 'statusCode' => 402, 'size' => 128],
                    ],
                ],
            ],
        ];
    }

    /**
     * Eine Fehlermeldung, die ihre laufende Aufzeichnung nennt.
     *
     * @return array<string, mixed>
     */
    public static function errorEvent(string $replayId, ?string $eventId = null): array
    {
        return [
            'event_id' => $eventId ?? IngestPayload::freshEventId(),
            'timestamp' => now()->getTimestamp(),
            'platform' => 'javascript',
            'environment' => 'production',
            'level' => 'error',
            'exception' => [
                'values' => [
                    ['type' => 'TypeError', 'value' => 'Kaputt', 'stacktrace' => ['frames' => []]],
                ],
            ],
            'contexts' => ['replay' => ['replay_id' => $replayId]],
        ];
    }
}
