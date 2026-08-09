<?php

namespace App\Support\Alerts;

use App\Enums\AlertMetric;
use App\Models\MetricAlert;
use App\Support\Discover\Aggregate;
use App\Support\Discover\Aggregation;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverEngine;
use App\Support\Discover\DiscoverQuery;
use App\Support\Discover\TimeRange;
use App\Support\Search\SearchQuery;

/**
 * Liest den Wert einer Kennzahl für ein Zeitfenster — die einzige Stelle, die weiß,
 * welche Kennzahl eines Alarms welche Auswertung ist.
 *
 * **Gerechnet wird nicht hier.** Der Wert kommt aus dem Auswertungs-Motor (D1),
 * derselben Stelle, aus der die freien Auswertungen und die Bausteine der
 * Übersichtsseiten ihre Zahlen bekommen. Vorher stand die Rechnung zweimal in der
 * Anwendung — einmal für die Übersicht, einmal für den Alarm —, und genau daran
 * scheitert das Vertrauen in einen Alarm: wenn die Kachel 480 ms zeigt und der Alarm
 * bei einer Schwelle von 500 ms schweigt, ist eine der beiden Zahlen falsch, und
 * niemand weiß, welche.
 *
 * Was hier bleibt, ist die **Übersetzung**: aus der Kennzahl eines Alarms wird eine
 * Quelle, eine Rechenart und eine Suchbedingung; aus der Antwort wird eine Ablesung,
 * die zwischen „null" und „unbekannt" unterscheidet ({@see MetricReading}).
 *
 * **Die Antwortzeiten kommen weiterhin aus den vorberechneten Fenstern** (PF1) und
 * nicht aus den Einzelmessungen — der Motor kennt beide Quellen, und die Wahl
 * gehört hierher: eine Auswertung, die jede Minute läuft, muss von der Datenmenge
 * unabhängig sein. Bei den Fehlern ist es ein Zählen über den Index
 * `(project_id, occurred_at)`, ein Bereich von wenigen Minuten und kein Durchlauf.
 *
 * **Ohne Zwischenspeicher.** Jede Ablesung ist ein anderes Fenster, und eine Zahl
 * aus dem Zwischenspeicher wäre hier keine Ersparnis, sondern ein Alarm auf der
 * Vergangenheit.
 */
final class MetricSource
{
    public function __construct(
        private readonly DiscoverEngine $engine = new DiscoverEngine,
    ) {}

    /**
     * Der Wert der Kennzahl dieses Alarms im übergebenen Fenster.
     */
    public function read(MetricAlert $alert, MetricWindow $window): MetricReading
    {
        $metric = $alert->metric;
        $query = $this->query($alert, $window);
        $row = $this->engine->table($query)->first();

        if ($row === null) {
            return MetricReading::unknown();
        }

        // Die Zahl der Messungen ist der Nenner jeder Quote und jedes Perzentils —
        // und bei den Fehlern die Aussage selbst.
        $measured = (int) ($row->value('count') ?? 0);
        $value = $row->value($this->aggregation($metric)->alias());

        if ($metric === AlertMetric::ErrorCount) {
            // Eine Anzahl ist auch dann eine Aussage, wenn sie null ist — genau
            // daran hängt die Entwarnung nach einem stillen Zeitfenster.
            return MetricReading::of((float) $measured, $measured);
        }

        if ($metric === AlertMetric::TransactionThroughput) {
            // Hochgerechnet und nicht gemessen: bei aktiver Stichprobe (I9) ist die
            // Zahl der gespeicherten Messungen nicht die Zahl der Aufrufe, und „der
            // Durchsatz ist eingebrochen" wäre sonst eine Aussage über die
            // Stichprobenquote.
            return MetricReading::of($value ?? 0.0, $measured);
        }

        // Ab hier braucht jede Kennzahl Messungen, um überhaupt etwas zu bedeuten.
        // Keine Messungen heißt nicht „0 ms", sondern „unbekannt" — und ein Alarm,
        // der darauf Entwarnung gäbe, verstummte genau dann, wenn die Anwendung
        // nicht mehr antwortet.
        if ($measured === 0 || $value === null) {
            return MetricReading::unknown($measured);
        }

        return MetricReading::of($this->inDisplayUnit($metric, $value), $measured);
    }

    /**
     * Die Abfrage hinter einer Kennzahl.
     */
    private function query(MetricAlert $alert, MetricWindow $window): DiscoverQuery
    {
        $dataset = $alert->metric === AlertMetric::ErrorCount
            ? Dataset::Errors
            : Dataset::TransactionWindows;

        return DiscoverQuery::for($dataset, $alert->project_id, TimeRange::of($window->from, $window->to))
            ->withSearch($this->filter($alert))
            ->measuring([Aggregation::of(Aggregate::Count), $this->aggregation($alert->metric)])
            ->uncached();
    }

    /**
     * Die Einschränkung des Alarms — in der Suchsprache und nicht als eigener
     * Parametersatz.
     *
     * Dieselbe Schreibweise, die jemand in eine freie Auswertung tippt: dann meint
     * „nur Produktion" hier und dort dieselbe Menge, und ein Alarm lässt sich in eine
     * Auswertung übersetzen, indem man seinen Ausdruck kopiert.
     */
    private function filter(MetricAlert $alert): ?string
    {
        $terms = [];

        if ($alert->environment !== null) {
            $terms[] = SearchQuery::term('environment', $alert->environment);
        }

        if ($alert->transaction_name !== null && $alert->metric->isTransactionMetric()) {
            $terms[] = SearchQuery::term('name', $alert->transaction_name);
        }

        return $terms === [] ? null : implode(' ', $terms);
    }

    /**
     * Welche Rechenart hinter der Kennzahl steht.
     */
    private function aggregation(AlertMetric $metric): Aggregation
    {
        return match ($metric) {
            AlertMetric::ErrorCount => Aggregation::of(Aggregate::Count),
            AlertMetric::TransactionThroughput => Aggregation::of(Aggregate::Sum, 'throughput'),
            AlertMetric::TransactionFailureRate => Aggregation::of(Aggregate::FailureRate),
            AlertMetric::TransactionDurationAvg => Aggregation::of(Aggregate::Avg, 'duration'),
            AlertMetric::TransactionDurationP50 => Aggregation::of(Aggregate::P50, 'duration'),
            AlertMetric::TransactionDurationP95 => Aggregation::of(Aggregate::P95, 'duration'),
            AlertMetric::TransactionDurationP99 => Aggregation::of(Aggregate::P99, 'duration'),
        };
    }

    /**
     * Der Motor rechnet Dauern in Mikrosekunden, die Schwellen eines Alarms stehen
     * in Millisekunden — umgerechnet wird hier und nicht im Motor: dort wäre es eine
     * Einheit, die von der Kennzahl abhängt, die sie gerade liest.
     */
    private function inDisplayUnit(AlertMetric $metric, float $value): float
    {
        return $metric->unit() === 'ms' ? $value / 1000 : $value;
    }
}
