import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import useFilter from '../../filters/useFilter.js';
import { filterQuery } from '../../filters/useGlobalFilter.js';
import { formatDateTime, formatNumber, useTranslations } from '../../i18n.js';
import { Missing } from './format.jsx';
import {
    DistributionBar,
    RatingBadge,
    plainVitalValue,
    ratingColor,
    vitalValue,
} from './vitals.jsx';

// Das Ladeerlebnis einer einzelnen Seite: alle Messwerte, dazu Verteilung,
// Verlauf und Aufschlüsselung des ausgewählten.
//
// Der ausgewählte Messwert steht in der Adresszeile und nicht im Zustand der
// Komponente. Das ist derselbe Grundsatz wie überall — ein Link muss zeigen,
// was der Absender gesehen hat —, hier aber besonders wichtig: „schau dir das
// LCP dieser Seite an" ist genau die Nachricht, die man verschickt.
export default function WebVital({ detail, vitals, overviewHref }) {
    const { shell } = usePage().props;
    const filter = useFilter();
    const { t, formats } = useTranslations();

    const selected = vitals.find((vital) => vital.key === detail.selected) ?? vitals[0];
    const summary = detail.vitals[detail.selected];

    const select = (key) => {
        const query = filterQuery(filter.value);

        query.set('name', detail.name);
        query.set('vital', key);

        router.get(
            `${window.location.pathname}?${query.toString()}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    return (
        <>
            <PageHead
                title={t('web_vitals.detail.title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('web_vitals.detail.help.purpose')}</li>
                        <li>{t('web_vitals.detail.help.select')}</li>
                        <li>{t('web_vitals.detail.help.thresholds')}</li>
                        <li>{t('web_vitals.detail.help.facets')}</li>
                    </ul>
                }
            />

            <Card className="mb-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <p className="min-w-0 font-mono text-lg break-all text-gray-900 dark:text-gray-100">
                        {detail.name}
                    </p>

                    <Link
                        href={overviewHref}
                        className="text-sm text-gray-600 underline underline-offset-2 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100"
                    >
                        {t('web_vitals.detail.back')}
                    </Link>
                </div>
            </Card>

            {!detail.hasData ? (
                <Card>
                    <div className="py-8 text-center">
                        <p className="text-sm text-gray-600 dark:text-gray-300">
                            {t('web_vitals.detail.empty')}
                        </p>
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('web_vitals.detail.empty_hint')}
                        </p>
                    </div>
                </Card>
            ) : (
                <>
                    <VitalCards
                        vitals={vitals}
                        summaries={detail.vitals}
                        selected={detail.selected}
                        onSelect={select}
                        t={t}
                        formats={formats}
                    />

                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <Histogram
                            bars={detail.histogram}
                            vital={selected}
                            summary={summary}
                            t={t}
                            formats={formats}
                        />
                        <Series series={detail.series} vital={selected} t={t} formats={formats} />
                    </div>

                    <Facets
                        facets={detail.facets}
                        vital={selected}
                        sampled={detail.sampledTransactions}
                        limit={detail.sampleLimit}
                        t={t}
                        formats={formats}
                    />
                </>
            )}
        </>
    );
}

// Die Messwerte als Karten — und zugleich die Auswahl.
//
// Eine Karte ist eine Schaltfläche und keine Registerkarte daneben: die Auswahl
// betrifft genau das, was die Karte zeigt, und ein zweites Bedienelement für
// dieselbe Entscheidung wäre eines zu viel.
function VitalCards({ vitals, summaries, selected, onSelect, t, formats }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {vitals.map((vital) => {
                const summary = summaries[vital.key];
                const active = vital.key === selected;

                return (
                    <button
                        key={vital.key}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onSelect(vital.key)}
                        title={vital.description}
                        className={`rounded-lg bg-white p-4 text-start shadow transition dark:bg-gray-800 ${
                            active
                                ? 'ring-2 ring-indigo-500 dark:ring-indigo-400'
                                : 'hover:bg-gray-50 dark:hover:bg-gray-700/40'
                        }`}
                    >
                        <div className="flex items-baseline justify-between gap-2">
                            <span className="text-sm font-medium text-gray-500 dark:text-gray-400">
                                {vital.label}
                            </span>
                            <span className="text-xl tabular-nums text-gray-900 dark:text-gray-100">
                                {vitalValue(summary.value, vital, t, formats)}
                            </span>
                        </div>

                        <div className="mt-2 flex items-center justify-between gap-2">
                            <RatingBadge rating={summary.rating} label={summary.ratingLabel} />
                            <span className="text-xs text-gray-500 dark:text-gray-400 tabular-nums">
                                {summary.count === 0
                                    ? t('web_vitals.detail.no_measurement')
                                    : formatNumber(summary.count, formats)}
                            </span>
                        </div>

                        <div className="mt-2">
                            <DistributionBar summary={summary} t={t} formats={formats} />
                        </div>

                        <p className="mt-2 text-xs text-gray-400 dark:text-gray-500">
                            {t('web_vitals.row.threshold', {
                                good: plainVitalValue(summary.goodMax, vital, t, formats),
                                poor: plainVitalValue(summary.poorMin, vital, t, formats),
                            })}
                        </p>
                    </button>
                );
            })}
        </div>
    );
}

// Die Verteilung des ausgewählten Messwerts.
//
// Sie beantwortet die Frage, die ein Perzentil offenlässt: sind alle
// Ladevorgänge gleich langsam, oder sind es zwei Gruppen? Ein zweiter Hügel weit
// rechts ist ein anderer Befund als ein breiter Berg.
function Histogram({ bars, vital, summary, t, formats }) {
    const peak = bars.reduce((max, bar) => Math.max(max, bar.count), 0);

    return (
        <Card title={t('web_vitals.detail.histogram.title')}>
            {bars.length === 0 ? (
                <Missing />
            ) : (
                <div className="flex h-40 items-end gap-1">
                    {bars.map((bar) => (
                        <div
                            key={bar.from}
                            className="flex min-w-0 flex-1 flex-col justify-end"
                            title={t('web_vitals.detail.histogram.bar', {
                                count: formatNumber(bar.count, formats),
                                from: plainVitalValue(bar.from, vital, t, formats),
                                to:
                                    bar.to === null
                                        ? t('web_vitals.detail.histogram.open_end')
                                        : plainVitalValue(bar.to, vital, t, formats),
                            })}
                        >
                            <div
                                // Die Klasse wird in der Farbe ihrer eigenen
                                // Bewertung gezeichnet: so ist auf einen Blick
                                // zu sehen, welcher Teil der Besucher jenseits
                                // der Schwelle liegt — und nicht nur, wie die
                                // Werte streuen.
                                className={`rounded-t ${ratingColor(ratingOf(bar.from, summary))}`}
                                style={{
                                    // Mindestens zwei Pixel, solange etwas
                                    // gemessen wurde: eine Klasse mit einem
                                    // Treffer ist etwas anderes als eine leere.
                                    height: `${bar.count === 0 || peak === 0 ? 0 : Math.max(2, (bar.count / peak) * 100)}%`,
                                }}
                            />
                        </div>
                    ))}
                </div>
            )}

            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {t('web_vitals.detail.histogram.hint')}
            </p>
        </Card>
    );
}

// Die Bewertung einer Klasse an ihrer **Untergrenze**: eine Klasse, die die
// Schwelle überschreitet, gilt erst ab dem Wert als schlechter, ab dem sie es
// tatsächlich ist.
function ratingOf(value, summary) {
    if (value <= summary.goodMax) {
        return 'good';
    }

    return value <= summary.poorMin ? 'needs_improvement' : 'poor';
}

// Der Verlauf: das p75 je Zeitfenster, jeder Balken in der Farbe seiner
// Bewertung. Damit ist nicht nur zu sehen, dass ein Wert gestiegen ist, sondern
// wann er die Schwelle überschritten hat — und das ist der Zeitpunkt, an dem
// man nach der Ursache sucht.
function Series({ series, vital, t, formats }) {
    const points = series.points;
    const peak = points.reduce((max, point) => Math.max(max, point.value ?? 0), 0);

    return (
        <Card title={t('web_vitals.detail.series.title')}>
            {points.length === 0 ? (
                <Missing />
            ) : (
                <div className="flex h-40 items-end gap-px">
                    {points.map((point) => (
                        <div
                            key={point.window}
                            className="flex min-w-0 flex-1 flex-col justify-end"
                            title={t('web_vitals.detail.series.point', {
                                at: formatDateTime(point.window, formats),
                                value: plainVitalValue(point.value, vital, t, formats),
                                count: formatNumber(point.count, formats),
                            })}
                        >
                            <div
                                className={`rounded-t ${ratingColor(point.rating)}`}
                                style={{
                                    height:
                                        peak === 0 || point.value === null
                                            ? 0
                                            : `${Math.max(2, (point.value / peak) * 100)}%`,
                                }}
                            />
                        </div>
                    ))}
                </div>
            )}

            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {t(`web_vitals.detail.series.period_${series.period}`)}
            </p>
        </Card>
    );
}

// Die Aufschlüsselung nach Gerät, Browser und Land — der Teil, der aus „das LCP
// ist schlecht" ein „das LCP ist auf Mobilgeräten schlecht" macht.
function Facets({ facets, vital, sampled, limit, t, formats }) {
    return (
        <Card className="mt-4" title={t('web_vitals.detail.facets.title')}>
            {facets.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('web_vitals.detail.facets.empty')}
                </p>
            ) : (
                <div className="grid gap-4 lg:grid-cols-3">
                    {facets.map((facet) => (
                        <div key={facet.key}>
                            <h3 className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                                {facet.label}
                            </h3>

                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                        <th scope="col" className="py-1 text-start font-medium">
                                            {t('web_vitals.detail.facets.value')}
                                        </th>
                                        <th scope="col" className="py-1 text-end font-medium">
                                            {t('web_vitals.detail.facets.measured')}
                                        </th>
                                        <th scope="col" className="py-1 text-end font-medium">
                                            {t('web_vitals.detail.facets.count')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-700/60">
                                    {facet.values.map((value) => (
                                        <tr key={value.value}>
                                            <td className="py-1 pe-2">
                                                <span
                                                    className="block truncate"
                                                    title={value.value}
                                                >
                                                    {value.value}
                                                </span>
                                                <RatingBadge
                                                    rating={value.rating}
                                                    label={value.ratingLabel}
                                                />
                                            </td>
                                            <td className="py-1 text-end tabular-nums">
                                                {vitalValue(value.measuredValue, vital, t, formats)}
                                            </td>
                                            <td className="py-1 text-end tabular-nums text-gray-500 dark:text-gray-400">
                                                {formatNumber(value.count, formats)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            {facet.truncated && (
                                <p className="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                    {t('web_vitals.detail.facets.truncated')}
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {/* Die Größe der Stichprobe steht dabei und wird nicht verschwiegen:
                eine Aufschlüsselung aus 500 von 40.000 Ladevorgängen ist eine
                andere Auskunft als eine aus allen. */}
            <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {t('web_vitals.detail.facets.hint', {
                    sampled: formatNumber(sampled, formats),
                    limit: formatNumber(limit, formats),
                })}
            </p>
        </Card>
    );
}
