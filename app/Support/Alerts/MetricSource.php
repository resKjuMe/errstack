<?php

namespace App\Support\Alerts;

use App\Enums\AlertMetric;
use App\Models\MetricAlert;
use App\Models\ReleaseSessionCount;
use App\Models\ReleaseSessionUser;
use App\Support\Discover\Aggregate;
use App\Support\Discover\Aggregation;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverEngine;
use App\Support\Discover\DiscoverQuery;
use App\Support\Discover\TimeRange;
use App\Support\Search\SearchQuery;
use LogicException;

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
 * **Die Crash-Free-Rate liest an dem Motor vorbei** (R7): Sitzungen sind (noch) keine
 * Quelle des Motors ({@see Dataset}), weil es die Tabelle erst seit dieser Aufgabe
 * gibt. Solange das so ist, wäre die Alternative eine Kennzahl ohne Zahl dahinter.
 * Sobald die Sitzungen dort als Quelle stehen, gehört diese Ablesung denselben Weg
 * wie die übrigen — der Zweig hier ist der einzige, der dafür fällt.
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
        if ($alert->metric->isSessionMetric()) {
            return $this->sessions($alert, $window);
        }

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
     *
     * Die Sitzungs-Kennzahlen stehen hier nicht als Lücke, sondern als Fall:
     * sie kommen gar nicht bis hierher ({@see read()} zweigt vorher ab), und ein
     * `default` würde genau das verdecken, sobald eine Kennzahl dazukommt.
     */
    private function aggregation(AlertMetric $metric): Aggregation
    {
        return match ($metric) {
            AlertMetric::CrashFreeSessions,
            AlertMetric::CrashFreeUsers => throw new LogicException(
                'Die Sitzungs-Kennzahlen lesen an dem Auswertungs-Motor vorbei.',
            ),
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
