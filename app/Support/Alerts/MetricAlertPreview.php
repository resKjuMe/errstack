<?php

namespace App\Support\Alerts;

use App\Enums\AlertComparison;
use App\Models\MetricAlert;
use App\Support\Formats;
use Carbon\CarbonImmutable;

/**
 * Der historische Verlauf einer Kennzahl, über den die Schwellen gelegt werden.
 *
 * Sie beantwortet die Frage, die sich jeder beim Einstellen einer Schwelle
 * stellt und die sonst niemand beantworten kann: **wo liegt der Wert
 * eigentlich normalerweise?** Eine Schwelle, die ohne diese Auskunft gesetzt
 * wird, ist geraten — und zwar in beide Richtungen: zu tief bedeutet einen
 * Dauerton, zu hoch einen Alarm, den man nie hört.
 *
 * Gerechnet wird mit **derselben Ablesung wie im Ernstfall**
 * ({@see MetricSource}), Fenster für Fenster rückwärts. Das ist der Punkt: eine
 * Vorschau, die anders rechnet als der Alarm, zeigt eine Kurve, unter der später
 * etwas anderes passiert.
 *
 * **Ja, das sind so viele Abfragen wie Balken** — und das ist hier vertretbar.
 * Die Vorschau ist eine Einstellungsseite, die jemand von Hand öffnet, kein
 * Weg, den jede Meldung nimmt; die Zahl der Balken ist fest
 * ({@see self::WINDOWS}), und jede einzelne Abfrage ist die bereits vorhandene,
 * indexgestützte Ablesung eines schmalen Zeitfensters. Der Alarm selbst braucht
 * weiterhin genau eine Abfrage je Durchlauf.
 */
final class MetricAlertPreview
{
    /**
     * Wie viele Fenster gezeigt werden.
     *
     * Vierundzwanzig, weil eine Grafik mit mehr Balken bei einem Fenster von
     * fünf Minuten nicht mehr Auskunft gibt, sondern nur schmaler wird — und
     * weil jeder Balken eine Abfrage kostet.
     */
    public const WINDOWS = 24;

    public function __construct(private readonly MetricSource $source) {}

    /**
     * Der Verlauf, ältestes Fenster zuerst.
     *
     * Aufsteigend und nicht wie sonst „jüngstes zuerst": eine Grafik wird von
     * links nach rechts gelesen, und die Umkehrung wäre in der Oberfläche
     * nachzuholen.
     *
     * @return array<string, mixed>
     */
    public function build(MetricAlert $alert, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $window = MetricWindow::forAlert($alert, $now);
        $points = [];

        for ($i = 0; $i < self::WINDOWS; $i++) {
            $points[] = $this->point($alert, $window);

            $window = $window->previous();
        }

        return [
            'unit' => $alert->unit(),
            'decimals' => $alert->comparison === AlertComparison::PercentChangeWeek
                ? 1
                : $alert->metric->decimals(),
            'windowMinutes' => $alert->window_minutes,
            'points' => array_reverse($points),
            'thresholds' => $this->thresholds($alert),
        ];
    }

    /**
     * Ein Balken: Zeitpunkt, Wert und der geschriebene Wert daneben.
     *
     * Ein Fenster ohne Aussage bekommt `null` und keinen Nullwert — dieselbe
     * Unterscheidung wie in der Auswertung. Eine Lücke in der Kurve ist ehrlich;
     * eine Null an dieser Stelle sähe aus wie „alles ruhig" und wäre in
     * Wirklichkeit „nichts gemessen".
     *
     * @return array<string, mixed>
     */
    private function point(MetricAlert $alert, MetricWindow $window): array
    {
        $reading = $this->source->read($alert, $window);
        $value = $reading->value;

        // Der Wochenvergleich zeigt die Kennzahl selbst und nicht die
        // Veränderung: die Kurve soll den Verlauf zeigen, an dem man eine
        // Schwelle abliest. Für die prozentuale Schwelle ist die Kurve deshalb
        // nur der Hintergrund — die Schwellenlinien entfallen dann (siehe
        // {@see thresholds()}).
        return [
            'at' => $window->from->toIso8601String(),
            'atLabel' => Formats::dateTime($window->from),
            'value' => $value,
            'valueLabel' => $value === null
                ? null
                : Formats::number($value, $alert->metric->decimals()),
            'samples' => $reading->samples,
        ];
    }

    /**
     * Die Linien über der Kurve.
     *
     * Beim Wochenvergleich gibt es sie nicht: dort ist die Schwelle eine
     * Veränderung in Prozent und liegt damit in einer anderen Einheit als die
     * Kurve. Eine Linie bei „50" in eine Kurve von Millisekunden zu zeichnen,
     * wäre eine Aussage, die nicht stimmt.
     *
     * @return list<array{status: string, label: string, value: float, valueLabel: string}>
     */
    private function thresholds(MetricAlert $alert): array
    {
        if ($alert->comparison === AlertComparison::PercentChangeWeek) {
            return [];
        }

        $lines = [];

        foreach ([
            'warning' => $alert->warning_threshold,
            'critical' => $alert->critical_threshold,
            'resolve' => $alert->resolve_threshold,
        ] as $status => $value) {
            if ($value === null) {
                continue;
            }

            $lines[] = [
                'status' => $status,
                'label' => __('alerts.thresholds.'.$status),
                'value' => $value,
                'valueLabel' => Formats::number($value, $alert->metric->decimals()),
            ];
        }

        return $lines;
    }
}
