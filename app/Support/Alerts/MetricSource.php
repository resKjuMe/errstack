<?php

namespace App\Support\Alerts;

use App\Enums\AlertMetric;
use App\Models\Event;
use App\Models\MetricAlert;
use App\Models\ReleaseSessionCount;
use App\Models\ReleaseSessionUser;
use App\Models\TransactionAggregate;
use App\Support\Performance\DurationHistogram;

/**
 * Liest den Wert einer Kennzahl für ein Zeitfenster — die einzige Stelle, die
 * weiß, aus welcher Tabelle eine Kennzahl kommt.
 *
 * Getrennt von der Auswertung ({@see MetricAlertEvaluator}), weil die beiden
 * verschiedene Dinge tun und getrennt zu prüfen sind: hier steht, **was**
 * gemessen wird, dort, **was daraus folgt**. Die Zustandslogik lässt sich damit
 * ohne Datenbank prüfen, und eine neue Kennzahl ist ein Zweig in dieser Klasse
 * statt einer Änderung an der Zustandsmaschine.
 *
 * **Eine Abfrage je Ablesung, unabhängig von der Datenmenge.** Bei den
 * Antwortzeiten sind es die vorberechneten Minuten-Fenster (PF1); die
 * Verteilungen werden **in der Datenbank** zusammengelegt
 * ({@see DurationHistogram::sumExpressions()}), sodass eine Zeile
 * herauskommt, aus der sich jedes Perzentil lesen lässt. Bei den Fehlern ist es
 * ein `count(*)` über den Index `(project_id, occurred_at)` — ein Bereich von
 * wenigen Minuten, kein Durchlauf. Bei der Crash-Free-Rate sind es die
 * Sitzungszahlen je Version (R7), in derselben Minuten-Rasterung.
 *
 * Warum die Fehler **nicht** aus der Zeitreihe der Fehler-Einträge (I6) kommen:
 * die ist stunden- und tagesweise abgelegt, und ein Alarm über fünf Minuten
 * wäre daraus nicht zu rechnen. Ein Fenster von fünf Minuten über die Ereignisse
 * ist dagegen genau der Zugriff, für den der Index gebaut ist.
 */
final class MetricSource
{
    /**
     * Der Wert der Kennzahl dieses Alarms im übergebenen Fenster.
     */
    public function read(MetricAlert $alert, MetricWindow $window): MetricReading
    {
        if ($alert->metric->isSessionMetric()) {
            return $this->sessions($alert, $window);
        }

        return $alert->metric === AlertMetric::ErrorCount
            ? $this->errors($alert, $window)
            : $this->transactions($alert, $window);
    }

    /**
     * Die Crash-Free-Rate aus den Sitzungszahlen der Auslieferungen (R7).
     *
     * **Über alle Versionen** und nicht je Auslieferung: ein Alarm beantwortet
     * die Frage „stürzt die Anwendung gerade häufiger ab als sonst", und die ist
     * nicht an eine einzelne Version gerichtet. Nach einer schlechten
     * Auslieferung schlägt er trotzdem an — deren Sitzungen sind ja genau die,
     * die den Gesamtwert nach unten ziehen.
     *
     * Eine Abfrage über die vorberechneten Minuten-Fenster, wie bei den
     * Antwortzeiten; die Nutzer-Fassung zählt über die vorverdichteten
     * Nutzer-Zeilen und nicht über Einzelsitzungen.
     */
    private function sessions(MetricAlert $alert, MetricWindow $window): MetricReading
    {
        $overUsers = $alert->metric === AlertMetric::CrashFreeUsers;

        $query = ($overUsers ? ReleaseSessionUser::query() : ReleaseSessionCount::query())
            ->toBase()
            ->where('project_id', $alert->project_id)
            ->where('bucket_start', '>=', $window->from)
            ->where('bucket_start', '<', $window->to);

        if ($alert->environment !== null) {
            $query->where('environment', $alert->environment);
        }

        $row = $query
            ->selectRaw($overUsers
                ? 'count(distinct user_key) as measured,'
                    .' count(distinct case when crashed_count > 0 then user_key end) as crashed'
                : 'sum(session_count) as measured, sum(crashed_count) as crashed')
            ->first();

        /** @var array<string, mixed> $values */
        $values = $row === null ? [] : (array) $row;

        $measured = (int) ($values['measured'] ?? 0);

        // Keine Sitzungen heißt nicht „hundert Prozent absturzfrei", sondern
        // „unbekannt" — und ein Alarm, der darauf Entwarnung gäbe, verstummte
        // genau dann, wenn die Anwendung gar nicht mehr startet.
        if ($measured === 0) {
            return MetricReading::unknown();
        }

        return MetricReading::of((1 - (int) ($values['crashed'] ?? 0) / $measured) * 100, $measured);
    }

    /**
     * Wie viele Fehlermeldungen im Fenster eingegangen sind.
     *
     * Gezählt wird nach der Uhr der überwachten Anwendung (`occurred_at`) und
     * nicht nach unserer: ein SDK, das nach einer Netztrennung seine
     * Warteschlange leert, würde sonst einen Ausschlag melden, den es zu diesem
     * Zeitpunkt nie gegeben hat.
     */
    private function errors(MetricAlert $alert, MetricWindow $window): MetricReading
    {
        $query = Event::query()
            ->where('project_id', $alert->project_id)
            ->where('occurred_at', '>=', $window->from)
            ->where('occurred_at', '<', $window->to);

        if ($alert->environment !== null) {
            $query->where('environment', $alert->environment);
        }

        $count = $query->count();

        // Eine Anzahl ist auch dann eine Aussage, wenn sie null ist — genau
        // daran hängt die Entwarnung nach einem stillen Zeitfenster.
        return MetricReading::of((float) $count, $count);
    }

    /**
     * Durchsatz, Fehlerquote und Antwortzeiten aus den vorberechneten Fenstern.
     */
    private function transactions(MetricAlert $alert, MetricWindow $window): MetricReading
    {
        $query = TransactionAggregate::query()
            ->where('project_id', $alert->project_id)
            ->where('window_start', '>=', $window->from)
            ->where('window_start', '<', $window->to);

        if ($alert->environment !== null) {
            $query->where('environment', $alert->environment);
        }

        if ($alert->transaction_name !== null) {
            $query->where('name', $alert->transaction_name);
        }

        $selects = array_merge(
            [
                'sum(transaction_count) as measured_count',
                'sum(extrapolated_count) as extrapolated_count',
                'sum(failure_count) as failure_count',
                'sum(duration_sum_us) as duration_sum_us',
            ],
            // Nur für die Perzentile nötig — und genau dann auch nur geholt:
            // 31 Summen für eine Fehlerquote wären Ballast in einer Abfrage,
            // die jede Minute läuft.
            $alert->metric->percentile() === null ? [] : DurationHistogram::sumExpressions(),
        );

        $row = $query->selectRaw(implode(', ', $selects))->toBase()->first();

        /** @var array<string, mixed> $values */
        $values = $row === null ? [] : (array) $row;

        $measured = (int) ($values['measured_count'] ?? 0);

        if ($alert->metric === AlertMetric::TransactionThroughput) {
            // Hochgerechnet und nicht gemessen: bei aktiver Stichprobe (I9) ist
            // die Zahl der gespeicherten Messungen nicht die Zahl der Aufrufe,
            // und „der Durchsatz ist eingebrochen" wäre sonst eine Aussage über
            // die Stichprobenquote.
            return MetricReading::of((float) ($values['extrapolated_count'] ?? 0), $measured);
        }

        // Ab hier braucht jede Kennzahl Messungen, um überhaupt etwas zu
        // bedeuten. Keine Messungen heißt nicht „null Millisekunden", sondern
        // „unbekannt" — und ein Alarm, der darauf Entwarnung gäbe, verstummte
        // genau dann, wenn die Anwendung nicht mehr antwortet.
        if ($measured === 0) {
            return MetricReading::unknown();
        }

        return match ($alert->metric) {
            AlertMetric::TransactionFailureRate => MetricReading::of(
                (int) ($values['failure_count'] ?? 0) / $measured * 100,
                $measured,
            ),
            AlertMetric::TransactionDurationAvg => MetricReading::of(
                (int) ($values['duration_sum_us'] ?? 0) / $measured / 1000,
                $measured,
            ),
            default => $this->percentile($alert->metric, $values, $measured),
        };
    }

    /**
     * Ein Perzentil aus der zusammengelegten Verteilung, in Millisekunden.
     *
     * @param  array<string, mixed>  $values
     */
    private function percentile(AlertMetric $metric, array $values, int $measured): MetricReading
    {
        $percentile = $metric->percentile();

        if ($percentile === null) {
            return MetricReading::unknown($measured);
        }

        $us = DurationHistogram::fromRowSums($values)->percentile($percentile);

        // Die Verteilung kann leer sein, obwohl Messungen gezählt wurden: eine
        // Zeile aus der Zeit vor der Verteilung (PF1 kam später als die
        // Zähler). Dann ist das Perzentil unbekannt und nicht null.
        return $us === null
            ? MetricReading::unknown($measured)
            : MetricReading::of($us / 1000, $measured);
    }
}
