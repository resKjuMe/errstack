import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import FilterBar from '../../components/FilterBar.jsx';
import { formatDateTime, formatNumber, useTranslations } from '../../i18n.js';
import { duration, Missing, percent } from './format.jsx';

// Die Detailanalyse einer Transaktion: warum diese Seite langsam ist.
//
// Die Reihenfolge der Abschnitte ist die Reihenfolge der Fragen, die jemand
// stellt, der hier ankommt: Wie schlimm ist es (Kennzahlen)? Wie verteilt sich
// das (Verteilung)? Seit wann (Verlauf)? Woran liegt es (Zeitfresser, Merkmale)?
// Wo sehe ich einen echten Fall (Beispiele)? Und was ist dabei kaputtgegangen
// (Fehler)?
//
// Ihr Zustand steht wie überall in der Adresszeile — Filterleiste, Name und
// Operation. Ein Link auf diese Seite zeigt beim Empfänger dieselbe Auswertung.
export default function Transaction({ filter, detail, overviewHref }) {
    const { shell } = usePage().props;
    const { t, formats } = useTranslations();

    const { summary, sample } = detail;
    const empty = summary.count === 0;

    return (
        <>
            <PageHead
                title={t('performance.transaction.title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('performance.transaction.help.purpose')}</li>
                        <li>{t('performance.transaction.help.histogram')}</li>
                        <li>{t('performance.transaction.help.sample')}</li>
                        <li>{t('performance.transaction.help.samples')}</li>
                        <li>{t('performance.transaction.help.facets')}</li>
                    </ul>
                }
            />

            <FilterBar filter={filter} />

            <Card className="mb-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <p className="font-mono text-lg break-all text-gray-900 dark:text-gray-100">
                            {detail.name}
                        </p>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {detail.op === '' ? t('performance.row.no_op') : detail.op}
                        </p>
                    </div>

                    <Link
                        href={overviewHref}
                        className="text-sm text-gray-600 underline underline-offset-2 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100"
                    >
                        {t('performance.transaction.back')}
                    </Link>
                </div>
            </Card>

            {empty ? (
                <Card>
                    <div className="py-8 text-center">
                        <p className="text-sm text-gray-600 dark:text-gray-300">
                            {t('performance.transaction.empty')}
                        </p>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('performance.transaction.empty_hint')}
                        </p>
                    </div>
                </Card>
            ) : (
                <>
                    <Summary summary={summary} t={t} formats={formats} />

                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <Histogram bars={detail.histogram} t={t} formats={formats} />
                        <Series series={detail.series} t={t} formats={formats} />
                    </div>

                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <Spans spans={detail.spans} sample={sample} t={t} formats={formats} />
                        <Facets facets={detail.facets} t={t} formats={formats} />
                    </div>

                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <Samples samples={detail.samples} t={t} formats={formats} />
                        <Issues issues={detail.issues} t={t} formats={formats} />
                    </div>
                </>
            )}
        </>
    );
}

// Die Kennzahlen des Zeitraums — dieselben wie in der Zeile, aus der man kam.
// Sie stehen hier noch einmal, weil eine Detailseite, die andere Zahlen nennt
// als die Liste davor, Vertrauen kostet, das sie nicht zurückbekommt.
function Summary({ summary, t, formats }) {
    const metrics = [
        { key: 'p50', value: duration(summary.p50Us, t, formats) },
        { key: 'p95', value: duration(summary.p95Us, t, formats) },
        { key: 'p99', value: duration(summary.p99Us, t, formats) },
        { key: 'avg', value: duration(summary.avgUs, t, formats) },
        {
            key: 'throughput',
            value: `${formatNumber(summary.throughput, formats, {
                minimumFractionDigits: 1,
                maximumFractionDigits: 1,
            })} ${t('performance.units.per_minute')}`,
        },
        {
            key: 'failureRate',
            value:
                summary.failureRate === null ? <Missing /> : percent(summary.failureRate, formats),
        },
        { key: 'users', value: formatNumber(summary.users, formats) },
        { key: 'count', value: formatNumber(summary.count, formats) },
    ];

    return (
        <Card>
            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                {metrics.map((metric) => (
                    <div key={metric.key}>
                        <dt className="text-xs text-gray-500 dark:text-gray-400">
                            {t(`performance.columns.${metric.key}`)}
                        </dt>
                        <dd className="mt-1 text-lg tabular-nums text-gray-900 dark:text-gray-100">
                            {metric.value}
                        </dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

// Die Verteilung als Balken über logarithmisch wachsenden Klassen.
//
// Sie beantwortet die Frage, die ein einzelner Mittelwert verschluckt: sind alle
// Aufrufe gleich langsam, oder sind es zwei Gruppen? Ein zweiter Hügel weit
// rechts ist ein anderer Befund als ein breiter Berg — im ersten Fall gibt es
// einen Sonderweg im Code, im zweiten ist die Seite als Ganzes zu schwer.
function Histogram({ bars, t, formats }) {
    const peak = bars.reduce((max, bar) => Math.max(max, bar.count), 0);

    return (
        <Card title={t('performance.transaction.histogram.title')}>
            {bars.length === 0 ? (
                <Missing />
            ) : (
                <div className="flex h-40 items-end gap-1">
                    {bars.map((bar) => (
                        <div
                            key={bar.fromUs}
                            className="flex min-w-0 flex-1 flex-col justify-end"
                            title={t('performance.transaction.histogram.bar', {
                                count: formatNumber(bar.count, formats),
                                from: plainDuration(bar.fromUs, t, formats),
                                to:
                                    bar.toUs === null
                                        ? t('performance.transaction.histogram.open_end')
                                        : plainDuration(bar.toUs, t, formats),
                            })}
                        >
                            <div
                                className="rounded-t bg-indigo-500/80 dark:bg-indigo-400/80"
                                style={{
                                    // Mindestens ein Pixel, solange etwas gemessen
                                    // wurde: eine Klasse mit einem Treffer ist
                                    // etwas anderes als eine leere, und genau
                                    // dieser Unterschied verschwände beim Runden.
                                    height: `${bar.count === 0 ? 0 : Math.max(2, (bar.count / peak) * 100)}%`,
                                }}
                            />
                        </div>
                    ))}
                </div>
            )}

            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {t('performance.transaction.histogram.hint')}
            </p>
        </Card>
    );
}

// Der Verlauf: p95 je Zeitfenster, dazu der Durchsatz als Grundlinie.
//
// Zwei Zahlen in einer Grafik, weil sie zusammen gelesen werden müssen: ein
// steigendes p95 bei gleichbleibendem Verkehr ist eine Verschlechterung, bei
// verdoppeltem Verkehr eine Auslastungsfrage.
function Series({ series, t, formats }) {
    const points = series.points;
    const peak = points.reduce((max, point) => Math.max(max, point.p95Us ?? 0), 0);

    return (
        <Card title={t('performance.transaction.series.title')}>
            {points.length === 0 ? (
                <Missing />
            ) : (
                <div className="flex h-40 items-end gap-px">
                    {points.map((point) => (
                        <div
                            key={point.window}
                            className="flex min-w-0 flex-1 flex-col justify-end"
                            title={t('performance.transaction.series.point', {
                                at: formatDateTime(point.window, formats),
                                p95: plainDuration(point.p95Us, t, formats),
                                count: formatNumber(point.count, formats),
                            })}
                        >
                            <div
                                className="rounded-t bg-emerald-500/80 dark:bg-emerald-400/80"
                                style={{
                                    height:
                                        peak === 0 || point.p95Us === null
                                            ? 0
                                            : `${Math.max(2, (point.p95Us / peak) * 100)}%`,
                                }}
                            />
                        </div>
                    ))}
                </div>
            )}

            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {t(`performance.transaction.series.period_${series.period}`)}
            </p>
        </Card>
    );
}

// Wohin die Zeit geht, nach Vorgangsart.
function Spans({ spans, sample, t, formats }) {
    return (
        <Card
            title={t('performance.transaction.spans.title')}
            description={t('performance.transaction.spans.description', {
                transactions: formatNumber(sample.transactions, formats),
            })}
        >
            {spans.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('performance.transaction.spans.empty')}
                </p>
            ) : (
                <ul className="space-y-3">
                    {spans.map((span) => (
                        <li key={span.op}>
                            <div className="flex items-baseline justify-between gap-3 text-sm">
                                <span className="font-mono break-all text-gray-900 dark:text-gray-100">
                                    {span.op === '' ? t('performance.row.no_op') : span.op}
                                </span>
                                <span className="shrink-0 tabular-nums text-gray-600 dark:text-gray-300">
                                    {percent(span.share, formats)}
                                </span>
                            </div>

                            <div className="mt-1 h-2 rounded bg-gray-100 dark:bg-gray-700">
                                <div
                                    className="h-2 rounded bg-amber-500/80 dark:bg-amber-400/80"
                                    style={{ width: `${Math.max(1, span.share * 100)}%` }}
                                />
                            </div>

                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {t('performance.transaction.spans.detail', {
                                    count: formatNumber(span.count, formats),
                                    total: plainDuration(span.totalUs, t, formats),
                                    average: plainDuration(span.averageUs, t, formats),
                                })}
                            </p>

                            {span.example && (
                                <p
                                    className="mt-1 truncate font-mono text-xs text-gray-400 dark:text-gray-500"
                                    title={span.example}
                                >
                                    {span.example}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

// Auffällige Merkmale: liegt es an allen oder nur an einer Version?
function Facets({ facets, t, formats }) {
    return (
        <Card
            title={t('performance.transaction.facets.title')}
            description={t('performance.transaction.facets.description')}
        >
            {facets.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('performance.transaction.facets.empty')}
                </p>
            ) : (
                <div className="space-y-4">
                    {facets.map((facet) => (
                        <div key={facet.key}>
                            <p className="text-xs font-medium text-gray-500 dark:text-gray-400">
                                {t(`performance.transaction.facets.keys.${facet.key}`)}
                            </p>

                            <ul className="mt-1 space-y-1">
                                {facet.values.map((value) => (
                                    <li
                                        key={value.value}
                                        className="flex items-baseline justify-between gap-3 text-sm"
                                    >
                                        <span className="min-w-0 truncate text-gray-900 dark:text-gray-100">
                                            {value.value}
                                            {value.outlier && (
                                                <span className="ms-2 rounded bg-rose-100 px-1.5 py-0.5 text-xs text-rose-700 dark:bg-rose-900/40 dark:text-rose-200">
                                                    {t('performance.transaction.facets.outlier')}
                                                </span>
                                            )}
                                        </span>
                                        <span className="shrink-0 tabular-nums text-gray-600 dark:text-gray-300">
                                            {duration(value.p95Us, t, formats)}
                                            <span className="ms-2 text-xs text-gray-400 dark:text-gray-500">
                                                {formatNumber(value.count, formats)}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
        </Card>
    );
}

// Die Beispielfälle, je Perzentil-Bereich einer.
function Samples({ samples, t, formats }) {
    return (
        <Card
            title={t('performance.transaction.samples.title')}
            description={t('performance.transaction.samples.description')}
        >
            {samples.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('performance.transaction.samples.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-gray-100 dark:divide-gray-700/60">
                    {samples.map((item) => (
                        <li key={item.eventId} className="flex items-baseline gap-3 py-2 text-sm">
                            <span className="w-10 shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                {`p${Math.round(item.percentile * 100)}`}
                            </span>

                            <span className="min-w-0 flex-1">
                                {item.traceHref ? (
                                    <Link
                                        href={item.traceHref}
                                        className="font-mono break-all text-gray-900 underline underline-offset-2 dark:text-gray-100"
                                    >
                                        {item.traceId}
                                    </Link>
                                ) : (
                                    <span
                                        className="font-mono break-all text-gray-900 dark:text-gray-100"
                                        title={t('performance.transaction.samples.no_trace_view')}
                                    >
                                        {item.traceId}
                                    </span>
                                )}

                                <span className="block text-xs text-gray-500 dark:text-gray-400">
                                    {t('performance.transaction.samples.detail', {
                                        at: formatDateTime(item.startedAt, formats),
                                        spans: formatNumber(item.spanCount, formats),
                                        release:
                                            item.release ??
                                            t('performance.transaction.samples.no_release'),
                                    })}
                                </span>
                            </span>

                            <span className="shrink-0 tabular-nums text-gray-900 dark:text-gray-100">
                                {duration(item.durationUs, t, formats)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

// Die Fehler, die unter diesem Transaktionsnamen gemeldet wurden.
function Issues({ issues, t, formats }) {
    return (
        <Card
            title={t('performance.transaction.issues.title')}
            description={t('performance.transaction.issues.description')}
        >
            {issues.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('performance.transaction.issues.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-gray-100 dark:divide-gray-700/60">
                    {issues.map((issue) => (
                        <li key={issue.id} className="flex items-baseline gap-3 py-2 text-sm">
                            <span className="min-w-0 flex-1">
                                {issue.href ? (
                                    <Link
                                        href={issue.href}
                                        className="break-all text-gray-900 underline underline-offset-2 dark:text-gray-100"
                                    >
                                        {issue.title}
                                    </Link>
                                ) : (
                                    <span className="break-all text-gray-900 dark:text-gray-100">
                                        {issue.title}
                                    </span>
                                )}

                                {issue.culprit && (
                                    <span className="block truncate font-mono text-xs text-gray-500 dark:text-gray-400">
                                        {issue.culprit}
                                    </span>
                                )}
                            </span>

                            <span className="shrink-0 tabular-nums text-gray-600 dark:text-gray-300">
                                {formatNumber(issue.count, formats)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

// Dieselbe Schreibweise wie `duration`, aber als Zeichenkette: in einem
// `title`-Attribut steht kein React-Baum.
function plainDuration(microseconds, t, formats) {
    const value = duration(microseconds, t, formats);

    return typeof value === 'string' ? value : '—';
}
