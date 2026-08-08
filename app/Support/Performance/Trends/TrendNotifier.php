<?php

namespace App\Support\Performance\Trends;

use App\Enums\NotificationLevel;
use App\Models\TransactionTrendDetection;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use App\Support\Alerts\AlertReference;
use App\Support\Alerts\MetricAlertNotifier;
use App\Support\Formats;
use Illuminate\Support\Carbon;

/**
 * Was hinausgeht, wenn eine Transaktion langsamer geworden ist.
 *
 * Derselbe Schnitt wie bei den Schwellwert-Alarmen
 * ({@see MetricAlertNotifier}): die Entscheidung **ob**
 * gemeldet wird, ist vorher gefallen ({@see BreakpointScan} und
 * {@see TrendScan}); hier steht nur, **was** dann rausgeht. Und derselbe
 * Versandweg — {@see NotificationDispatcher} und die Kanäle der Organisation
 * (A1) —, weil ein eigener Weg für Trendmeldungen eine zweite Stelle wäre, an
 * der jemand seine Ruhezeiten einstellen müsste.
 *
 * **Als Warnung und nicht als Fehler.** Eine Verschlechterung ist keine
 * Störung: die Anwendung tut noch, was sie soll, nur langsamer. Wer sie in
 * derselben Stufe meldet wie einen Ausfall, sorgt dafür, dass beim nächsten
 * Ausfall niemand mehr hinsieht.
 *
 * **Nur Verschlechterungen.** Verbesserungen stehen in der Liste, gehen aber
 * nicht hinaus. Eine Meldung ist eine Unterbrechung, und „etwas ist schneller
 * geworden" rechtfertigt keine — wer wissen will, ob seine Optimierung
 * angekommen ist, sieht selbst nach.
 */
final class TrendNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function send(TransactionTrendDetection $detection): void
    {
        $project = $detection->project;

        if ($project === null || ! $detection->isRegression()) {
            return;
        }

        $this->dispatcher->send($project->organization, new NotificationMessage(
            title: __('performance_trends.notification.title', [
                'transaction' => $detection->name,
                'project' => $project->name,
            ]),
            body: $this->body($detection),
            level: NotificationLevel::Warning,
            url: route('performance.trends.index'),
            context: $this->context($detection),
            reference: AlertReference::forTrendDetection($detection->id),
            // Als veränderliches Carbon: die Nachricht nimmt genau das, und ein
            // unveränderliches ist kein Untertyp davon.
            occurredAt: Carbon::parse($detection->breakpoint_at),
        ));
    }

    /**
     * Der Text sagt, was sich geändert hat und woran man es festmacht.
     *
     * „Eine Transaktion ist langsamer geworden" allein hilft niemandem, der
     * entscheiden soll, ob er etwas unternimmt — die beiden Höhen und der
     * Zeitpunkt tun es.
     */
    private function body(TransactionTrendDetection $detection): string
    {
        return __('performance_trends.notification.body', [
            'transaction' => $detection->name,
            'before' => self::duration($detection->before_p95_us),
            'after' => self::duration($detection->after_p95_us),
            'change' => self::percent($detection->change_ratio),
            'at' => (string) Formats::dateTime($detection->breakpoint_at),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function context(TransactionTrendDetection $detection): array
    {
        $context = [
            __('performance_trends.notification.context_project') => $detection->project?->name ?? '',
            __('performance_trends.notification.context_environment') => $detection->environment,
            __('performance_trends.notification.context_transaction') => $detection->name,
            __('performance_trends.notification.context_before') => self::duration($detection->before_p95_us),
            __('performance_trends.notification.context_after') => self::duration($detection->after_p95_us),
            // Die Zahl der Messungen gehört in die Meldung und nicht nur in die
            // Ansicht: sie ist der Grund, warum man der Aussage glauben darf.
            __('performance_trends.notification.context_samples') => __('performance_trends.notification.samples', [
                'before' => Formats::number($detection->before_count),
                'after' => Formats::number($detection->after_count),
            ]),
        ];

        $deploy = $detection->deploy;

        if ($deploy !== null) {
            $context[__('performance_trends.notification.context_deploy')] = __('performance_trends.notification.deploy', [
                'version' => $deploy->release?->version ?? $deploy->label(),
                'at' => (string) Formats::dateTime($deploy->finished_at),
            ]);
        }

        return $context;
    }

    /**
     * Eine Dauer, wie sie dasteht.
     *
     * Geschrieben wird sie serverseitig: wie eine Zahl aussieht, entscheidet die
     * Sprache, und eine Benachrichtigung hat keinen Browser, der das nachholen
     * könnte. Millisekunden als Eingang, weil {@see Formats::duration()} die
     * Einheit selbst wählt — „900000 µs" liest niemand als knappe Sekunde.
     */
    private static function duration(int $microseconds): string
    {
        return Formats::duration((int) round($microseconds / 1000));
    }

    private static function percent(float $ratio): string
    {
        return Formats::number(abs($ratio) * 100, 0).' %';
    }
}
