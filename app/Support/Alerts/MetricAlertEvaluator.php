<?php

namespace App\Support\Alerts;

use App\Enums\AlertComparison;
use App\Enums\AlertMetric;
use App\Models\MetricAlert;
use App\Models\MetricAlertTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Wertet einen Alarm aus: Wert holen, Zustand bestimmen, Wechsel festhalten und
 * melden.
 *
 * Die Reihenfolge ist die eigentliche Aussage dieser Klasse:
 *
 *   1. Den Wert des Fensters ablesen ({@see MetricSource}).
 *   2. Beim Wochenvergleich denselben Wert von vor sieben Tagen holen und die
 *      Veränderung rechnen.
 *   3. Aus altem Zustand und Wert den neuen bestimmen ({@see AlertState}).
 *   4. Den Vermerk „zuletzt gerechnet" schreiben — **immer**, auch ohne Wechsel.
 *   5. Nur bei einem Wechsel: Zustand umschalten, Verlaufseintrag anlegen,
 *      melden.
 *
 * **Schritt 4 steht vor Schritt 5 und ohne Bedingung.** Er ist der Lebensbeweis
 * des Alarms: ohne ihn wäre eine Regel, die seit Wochen nichts meldet, nicht von
 * einer zu unterscheiden, die wegen eines Tippfehlers im Vorgangsnamen nie
 * etwas findet.
 *
 * **Lücken in den Daten halten den Zustand.** Kann der Wert nicht bestimmt
 * werden — keine Messungen für eine Antwortzeit, zu wenige für eine Quote, kein
 * Vergleichswert der Vorwoche —, passiert nichts weiter: kein Alarm, keine
 * Entwarnung. Das ist die vorsichtigere von zwei Möglichkeiten, und zwar
 * bewusst: die andere hieße, dass eine Anwendung, die überhaupt nicht mehr
 * antwortet, ihren Alarm selbst auflöst. Anzahlen sind davon nicht betroffen —
 * für sie ist „nichts" eine Aussage ({@see AlertMetric::isCount()}).
 */
final class MetricAlertEvaluator
{
    public function __construct(
        private readonly MetricSource $source,
        private readonly MetricAlertNotifier $notifier,
    ) {}

    /**
     * Ein Alarm, ein Durchlauf.
     *
     * @return MetricAlertTransition|null der Wechsel, falls einer stattfand
     */
    public function evaluate(MetricAlert $alert, ?CarbonImmutable $now = null): ?MetricAlertTransition
    {
        $now ??= CarbonImmutable::now();
        $stamp = Carbon::parse($now);

        $window = MetricWindow::forAlert($alert, $now);
        $reading = $this->source->read($alert, $window);

        if (! $this->isUsable($alert, $reading)) {
            $alert->recordEvaluation(null, null, $stamp);

            return null;
        }

        $baseline = null;
        $value = (float) $reading->value;

        if ($alert->comparison === AlertComparison::PercentChangeWeek) {
            $baseline = $this->baseline($alert, $window);

            if ($baseline === null) {
                $alert->recordEvaluation(null, null, $stamp);

                return null;
            }

            $value = ($value - $baseline) / $baseline * 100;
        }

        $from = $alert->status;
        $to = AlertState::next(
            $from,
            $value,
            $alert->direction,
            $alert->warning_threshold,
            $alert->critical_threshold,
            $alert->resolve_threshold,
        );

        $alert->recordEvaluation($value, $baseline, $stamp);

        if ($to === $from || ! $alert->transitionTo($to, $stamp)) {
            // Kein Wechsel — oder ein anderer Durchlauf war schneller. In
            // beiden Fällen ist hier nichts zu melden; genau das ist die
            // Einlösung von „höchstens eine Meldung je Übergang".
            return null;
        }

        /** @var MetricAlertTransition $transition */
        $transition = $alert->transitions()->create([
            'from_status' => $from,
            'to_status' => $to,
            'value' => $value,
            'threshold' => $alert->thresholdFor($to),
            'baseline' => $baseline,
            'occurred_at' => $stamp,
        ]);

        $this->notifier->send($alert, $transition);

        return $transition;
    }

    /**
     * Taugt die Ablesung als Grundlage einer Entscheidung?
     *
     * Zwei Gründe, warum nicht: es gibt keinen Wert, oder es stehen zu wenige
     * Messungen dahinter. Der zweite ist die Antwort auf die Fehlerquote von
     * 33 %, die aus drei Aufrufen entsteht — rechnerisch richtig und als Befund
     * wertlos.
     */
    private function isUsable(MetricAlert $alert, MetricReading $reading): bool
    {
        if (! $reading->isKnown()) {
            return false;
        }

        return $alert->minimum_samples === 0 || $reading->samples >= $alert->minimum_samples;
    }

    /**
     * Der Vergleichswert derselben Uhrzeit vor einer Woche.
     *
     * `null`, wenn er fehlt **oder null ist**: eine Veränderung gegenüber null
     * ist keine Prozentangabe. Das ist die bekannte Lücke des Wochenvergleichs —
     * „von nichts auf zehn" meldet er nicht. Wer genau das überwachen will,
     * nimmt den absoluten Vergleich; dafür ist er da.
     */
    private function baseline(MetricAlert $alert, MetricWindow $window): ?float
    {
        $days = $alert->comparison->baselineOffsetDays();

        if ($days === null) {
            return null;
        }

        $before = $this->source->read($alert, $window->shiftedDays($days));

        if (! $before->isKnown() || $before->value <= 0.0) {
            return null;
        }

        return $before->value;
    }
}
