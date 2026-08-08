import React, { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import FilterBar from '../../components/FilterBar.jsx';
import { InputLabel, PrimaryButton, SecondaryButton, TextInput } from '../../components/Form.jsx';
import { filterQuery } from '../../filters/useGlobalFilter.js';
import { formatNumber, useTranslations } from '../../i18n.js';
import { Missing } from './format.jsx';
import { DistributionBar, RatingBadge, vitalValue } from './vitals.jsx';

// Das Ladeerlebnis im Browser: welche Seiten ihre Besucher warten lassen.
//
// Die Reihenfolge ist die Aussage dieser Seite — oben steht, wo die meisten
// Menschen ein schlechtes Erlebnis hatten. Deshalb gibt es hier, anders als in
// der Performance-Übersicht, **keine** anklickbaren Spaltenköpfe: eine Zeile
// trägt sechs Messwerte mit je drei Zahlen, und nach einer von ihnen zu
// sortieren beantwortet keine Frage, die die Rangfolge nicht schon beantwortet.
//
// Ihr Zustand steht trotzdem vollständig in der Adresszeile — Filter, Suche und
// Seite —, nach demselben Muster wie überall: ein Neuladen behält ihn, und ein
// geteilter Link zeigt beim Empfänger dieselbe Liste.
export default function WebVitals({ filter, rows, vitals, q, pagination, truncated, groupLimit }) {
    const { shell } = usePage().props;
    const { t, formats } = useTranslations();

    // Die Sucheingabe ist der einzige Zustand, der **nicht** sofort in die
    // Adresszeile wandert: ein Aufruf je Tastendruck wäre eine Abfrage je
    // Tastendruck.
    const [search, setSearch] = useState(q);

    useEffect(() => {
        setSearch(q);
    }, [q]);

    const visit = (patch) => {
        const query = filterQuery(filter.value);
        const next = { q, page: pagination.page, ...patch };

        if (next.q) {
            query.set('q', next.q);
        }

        // Die erste Seite ist die Voreinstellung und gehört nicht in den Link.
        if (next.page > 1) {
            query.set('page', String(next.page));
        }

        router.get(
            `${window.location.pathname}?${query.toString()}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const submitSearch = (e) => {
        e.preventDefault();
        visit({ q: search.trim(), page: 1 });
    };

    return (
        <>
            <PageHead
                title={t('web_vitals.title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('web_vitals.help.purpose')}</li>
                        <li>{t('web_vitals.help.percentile')}</li>
                        <li>{t('web_vitals.help.thresholds')}</li>
                        <li>{t('web_vitals.help.core')}</li>
                        <li>{t('web_vitals.help.no_data')}</li>
                    </ul>
                }
            />

            <FilterBar filter={filter} />

            <Card>
                <form onSubmit={submitSearch} className="mb-4 flex flex-wrap items-end gap-2">
                    <div className="min-w-64 flex-1">
                        <InputLabel
                            htmlFor="web_vitals_search"
                            value={t('web_vitals.search.label')}
                        />
                        <TextInput
                            id="web_vitals_search"
                            type="search"
                            value={search}
                            placeholder={t('web_vitals.search.placeholder')}
                            className="mt-1 font-mono"
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>

                    <PrimaryButton type="submit">{t('web_vitals.search.submit')}</PrimaryButton>

                    {q !== '' && (
                        <SecondaryButton type="button" onClick={() => visit({ q: '', page: 1 })}>
                            {t('web_vitals.search.clear')}
                        </SecondaryButton>
                    )}
                </form>

                {truncated && (
                    <p className="mb-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                        {t('web_vitals.truncated', { limit: groupLimit })}
                    </p>
                )}

                {rows.length === 0 ? (
                    <Empty searching={q !== ''} />
                ) : (
                    <>
                        {/* Sieben Spalten sind breiter als ein Telefon. Die
                            Tabelle rollt in ihrem eigenen Rahmen, damit nicht
                            die ganze Seite seitlich wandert. */}
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-200 dark:border-gray-700">
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-start font-medium"
                                        >
                                            {t('web_vitals.columns.page')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-3 py-2 text-start font-medium"
                                        >
                                            {t('web_vitals.columns.rating')}
                                        </th>
                                        <th
                                            scope="col"
                                            className="hidden px-3 py-2 text-end font-medium xl:table-cell"
                                        >
                                            {t('web_vitals.columns.measurements')}
                                        </th>
                                        {vitals.map((vital) => (
                                            <th
                                                key={vital.key}
                                                scope="col"
                                                title={vital.description}
                                                className={`px-3 py-2 text-end font-medium ${
                                                    // Die erklärenden Messwerte
                                                    // treten auf schmalen
                                                    // Anzeigen zurück: über die
                                                    // Bewertung entscheiden sie
                                                    // ohnehin nicht.
                                                    vital.core ? '' : 'hidden lg:table-cell'
                                                }`}
                                            >
                                                {vital.label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-700/60">
                                    {rows.map((row) => (
                                        <Row
                                            key={row.name}
                                            row={row}
                                            vitals={vitals}
                                            filter={filter}
                                            t={t}
                                            formats={formats}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <Pagination
                            pagination={pagination}
                            onPage={(page) => visit({ page })}
                            t={t}
                            formats={formats}
                        />
                    </>
                )}
            </Card>
        </>
    );
}

function Row({ row, vitals, filter, t, formats }) {
    return (
        <tr className="hover:bg-gray-50 dark:hover:bg-gray-700/40">
            <td className="px-3 py-2">
                <Link
                    href={detailHref(row.name, filter)}
                    className="font-mono break-all text-indigo-600 hover:underline dark:text-indigo-400"
                >
                    {row.name}
                </Link>
            </td>

            <td className="px-3 py-2">
                {row.hasData ? (
                    <RatingBadge rating={row.rating} label={row.ratingLabel} />
                ) : (
                    // Kein Strich, sondern ein Wort: „keine Daten" ist der
                    // Befund, um dessentwillen die Zeile überhaupt in der Liste
                    // steht.
                    <span
                        title={t('web_vitals.row.no_data_hint')}
                        className="text-xs text-gray-500 dark:text-gray-400"
                    >
                        {t('web_vitals.row.no_data')}
                    </span>
                )}
            </td>

            <td className="hidden px-3 py-2 text-end tabular-nums xl:table-cell">
                {row.hasData ? formatNumber(row.measurements, formats) : <Missing />}
            </td>

            {vitals.map((vital) => (
                <VitalCell
                    key={vital.key}
                    vital={vital}
                    summary={row.vitals[vital.key]}
                    t={t}
                    formats={formats}
                />
            ))}
        </tr>
    );
}

// Eine Zelle trägt drei Auskünfte übereinander: die Zahl, ihre Bewertung als
// Punkt und die Verteilung als Balken. Zusammen beantworten sie „wie gut ist
// es" und „für wie viele" — die zweite Frage ist die, die eine einzelne Zahl
// nie beantwortet.
function VitalCell({ vital, summary, t, formats }) {
    if (!summary || summary.count === 0) {
        return (
            <td
                className={`px-3 py-2 text-end ${vital.core ? '' : 'hidden lg:table-cell'}`}
                title={t('web_vitals.detail.no_measurement')}
            >
                <Missing />
            </td>
        );
    }

    return (
        <td
            className={`px-3 py-2 text-end ${vital.core ? '' : 'hidden lg:table-cell'}`}
            title={t('web_vitals.row.measurements', {
                count: formatNumber(summary.count, formats),
            })}
        >
            <div className="flex flex-col items-end gap-1">
                <span className="tabular-nums">{vitalValue(summary.value, vital, t, formats)}</span>
                <RatingBadge rating={summary.rating} label={summary.ratingLabel} />
                <DistributionBar summary={summary} t={t} formats={formats} />
            </div>
        </td>
    );
}

// Der Weg zur Detailseite, mit derselben Projektauswahl und demselben Zeitraum.
// Ohne sie zeigte die Detailseite den Standard-Zeitraum — und damit womöglich
// nichts von dem, weswegen man geklickt hat.
function detailHref(name, filter) {
    const query = filterQuery(filter.value);

    query.set('name', name);

    return `/ladeerlebnis/seite?${query.toString()}`;
}

// Zwei Leerzustände und nicht einer: „hier ist nichts angekommen" und „deine
// Suche trifft nichts" verlangen verschiedene nächste Schritte.
function Empty({ searching }) {
    const { t } = useTranslations();

    return (
        <div className="py-8 text-center">
            <p className="text-sm text-gray-600 dark:text-gray-300">
                {t(searching ? 'web_vitals.empty.no_results' : 'web_vitals.empty.no_data')}
            </p>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t(
                    searching ? 'web_vitals.empty.no_results_hint' : 'web_vitals.empty.no_data_hint'
                )}
            </p>
        </div>
    );
}

function Pagination({ pagination, onPage, t, formats }) {
    if (pagination.lastPage <= 1) {
        return null;
    }

    return (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('web_vitals.pagination.summary', {
                    page: formatNumber(pagination.page, formats),
                    pages: formatNumber(pagination.lastPage, formats),
                    total: formatNumber(pagination.total, formats),
                })}
            </p>

            <div className="flex gap-2">
                <SecondaryButton
                    type="button"
                    disabled={pagination.page <= 1}
                    onClick={() => onPage(pagination.page - 1)}
                >
                    {t('web_vitals.pagination.previous')}
                </SecondaryButton>
                <SecondaryButton
                    type="button"
                    disabled={pagination.page >= pagination.lastPage}
                    onClick={() => onPage(pagination.page + 1)}
                >
                    {t('web_vitals.pagination.next')}
                </SecondaryButton>
            </div>
        </div>
    );
}
