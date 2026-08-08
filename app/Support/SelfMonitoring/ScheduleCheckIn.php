<?php

namespace App\Support\SelfMonitoring;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Das Lebenszeichen des eigenen Zeitplans (M1).
 *
 * Es ist die einzige Auskunft dieser Anwendung, die nicht von selbst entsteht.
 * Alles andere wird gemeldet, **weil** etwas passiert ist — ein Zeitplan, der
 * überhaupt nicht mehr läuft, meldet dagegen gar nichts, und das sieht von
 * außen aus wie „alles ruhig". Bei dieser Anwendung ist das der schlimmste
 * Fall: an `schedule:run` hängt `crons:sweep`, und damit die Überwachung
 * **aller** fremden Cronjobs. Fällt der Zeitplan aus, fällt lautlos die
 * Überwachung aus, die einen Ausfall melden soll.
 *
 * Gemeldet wird über denselben Endpunkt, den eine fremde Anwendung nimmt —
 * eine HTTP-Anfrage an `/api/{projekt}/cron/{monitor}/{key}`, kein Abzweig ins
 * eigene Datenmodell. Was hier ankommt, beweist damit auch, dass der Endpunkt
 * funktioniert.
 *
 * **Fehler beim Melden bleiben beim Melden.** Ein Zeitplan, der abbricht, weil
 * die Überwachung nicht erreichbar war, ist schlechter dran als einer, der
 * unbeobachtet läuft. Netzwerkfehler landen im Log und sonst nirgends.
 */
final class ScheduleCheckIn
{
    /**
     * Hängt Lebenszeichen an eine geplante Aufgabe.
     *
     * Drei Meldungen und nicht eine: `in_progress` beim Start, danach `ok`
     * oder `error`. Erst das Paar aus Start und Ende macht einen **hängenden**
     * Lauf sichtbar — ein Job, der begonnen und nie geendet hat, ist etwas
     * anderes als einer, der nie begonnen hat, und beide Fälle brauchen
     * verschiedene Antworten.
     */
    public static function attach(Event $event, ?string $monitor = null): Event
    {
        $monitor ??= (string) config('selfmonitoring.schedule.monitor');

        return $event
            ->before(static fn () => self::send($monitor, 'in_progress'))
            ->onSuccess(static fn () => self::send($monitor, 'ok'))
            ->onFailure(static fn () => self::send($monitor, 'error'));
    }

    private static function send(string $monitor, string $status): void
    {
        if (! config('selfmonitoring.schedule.enabled')) {
            return;
        }

        $dsn = Dsn::parse(config('sentry.dsn'));

        if ($dsn === null) {
            return;
        }

        try {
            Http::timeout((int) config('selfmonitoring.schedule.timeout_seconds', 5))
                ->get($dsn->cronCheckInUrl($monitor), ['status' => $status]);
        } catch (Throwable $e) {
            // Bewusst nur ins Log: siehe oben. `warning` und nicht `error`,
            // weil der überwachte Vorgang selbst in Ordnung ist — nur die
            // Nachricht darüber kam nicht an.
            Log::warning('Lebenszeichen des Zeitplans konnte nicht gemeldet werden.', [
                'monitor' => $monitor,
                'status' => $status,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
