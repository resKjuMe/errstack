<?php

namespace App\Http\Controllers;

use App\Support\Ingest\Processing\ProcessingMetrics;
use App\Support\Operations\BacklogWatch;
use App\Support\Operations\FailedJobs;
use App\Support\Operations\HealthCheck;
use App\Support\Operations\PrometheusText;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/metrics` — dieselben Zahlen wie in der Betriebsansicht, nur für Maschinen.
 *
 * Ausgeliefert **aus** (`ERRSTACK_METRICS_ENABLED`). Das ist keine Vorsicht um
 * ihrer selbst willen: die Antwort nennt Rückstände, Warteschlangenlängen und
 * Laufzeiten und sagt damit einem Fremden, wann diese Installation überlastet
 * ist — und wann ein Angriff also am wenigsten auffällt.
 *
 * Ist die Adresse aus, antwortet sie `404` und nicht `403`: eine abgeschaltete
 * Adresse soll nicht verraten, dass es sie gibt.
 *
 * Der Token ist optional, weil er nicht überall gebraucht wird — steht die
 * Adresse ohnehin nur im inneren Netz, ist das Netz der Schutz. Steht sie
 * öffentlich, ist er es.
 */
class MetricsController extends Controller
{
    public function __invoke(
        Request $request,
        ProcessingMetrics $metrics,
        BacklogWatch $backlog,
        FailedJobs $failedJobs,
        HealthCheck $health,
    ): Response {
        if (! config('operations.metrics.enabled')) {
            throw new NotFoundHttpException;
        }

        self::authorize($request);

        $text = new PrometheusText((string) config('operations.metrics.prefix', 'errstack'));

        $text->gauge('up', 'Ob diese Installation antwortet — immer 1, wenn die Antwort ankommt.', 1);

        foreach ($health->run() as $check => $result) {
            $text
                ->gauge(
                    'health',
                    'Zustand eines Bestandteils: 1 in Ordnung, 0 gestört.',
                    $result['state']->isOk() ? 1 : 0,
                    ['check' => $check],
                )
                ->gauge(
                    'health_duration_milliseconds',
                    'Wie lange die Zustandsprüfung eines Bestandteils gedauert hat.',
                    $result['duration_ms'],
                    ['check' => $check],
                );
        }

        $status = $backlog->status();

        $text
            ->gauge('ingest_backlog', 'Angenommene, noch nicht ausgewertete Meldungen.', $status['pending'])
            ->gauge('ingest_backlog_age_seconds', 'Wie lange die älteste wartende Meldung schon wartet.', $status['oldest_seconds'])
            ->gauge('ingest_backlog_breaching', 'Ob der Rückstand über der Schwelle liegt: 1 ja, 0 nein.', $status['breaching'] ? 1 : 0);

        // Ein Wert je Zustand statt einer eigenen Kennzahl für „gescheitert":
        // wer nur die Fehlschläge will, filtert auf `state="failed"`, und die
        // übrigen Zustände sind dieselbe Abfrage umsonst mitgeliefert.
        foreach ($metrics->states() as $state => $total) {
            $text->gauge('ingest_payloads', 'Angenommene Meldungen je Verarbeitungszustand.', $total, ['state' => $state]);
        }

        foreach ($metrics->queueSizes() as $queue => $size) {
            $text->gauge('queue_size', 'Jobs, die in einer Warteschlange auf ihre Abholung warten.', $size, ['queue' => $queue]);
        }

        $durations = $metrics->durations();
        $latency = $metrics->latency();

        $text
            ->gauge('processing_duration_milliseconds_avg', 'Mittlere Rechenzeit der jüngsten Durchläufe.', $durations['avg_ms'])
            ->gauge('processing_duration_milliseconds_p95', 'Rechenzeit des langsamsten Zwanzigstels der jüngsten Durchläufe.', $durations['p95_ms'])
            ->gauge('processing_duration_milliseconds_max', 'Längste Rechenzeit der jüngsten Durchläufe.', $durations['max_ms'])
            ->gauge('ingest_latency_milliseconds_avg', 'Mittlere Dauer von der Annahme bis zur Sichtbarkeit.', $latency['avg_ms'])
            ->gauge('ingest_latency_milliseconds_p95', 'Dauer von der Annahme bis zur Sichtbarkeit im langsamsten Zwanzigstel.', $latency['p95_ms'])
            ->gauge('ingest_latency_milliseconds_max', 'Längste Dauer von der Annahme bis zur Sichtbarkeit.', $latency['max_ms'])
            ->gauge('failed_jobs', 'Endgültig gescheiterte Jobs in der Fehlerablage der Warteschlange.', $failedJobs->count());

        return response($text->render(), Response::HTTP_OK, [
            // Version 0.0.4 des Textformats — die Angabe, an der Prometheus den
            // Einleser wählt.
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /**
     * Prüft den Token, sofern einer hinterlegt ist.
     *
     * `hash_equals` und kein `===`: der Vergleich läuft über eine feste Zeit,
     * damit sich der Token nicht Zeichen für Zeichen erraten lässt.
     */
    private static function authorize(Request $request): void
    {
        $expected = config('operations.metrics.token');

        if (! is_string($expected) || $expected === '') {
            return;
        }

        $given = $request->bearerToken() ?? '';

        if (! hash_equals($expected, $given)) {
            throw new AccessDeniedHttpException;
        }
    }
}
