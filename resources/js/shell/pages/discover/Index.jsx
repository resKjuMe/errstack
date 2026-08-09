import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { InputLabel, SecondaryButton, SelectInput } from '../../components/Form.jsx';
import { useTranslations } from '../../i18n.js';
import QueryBar from './QueryBar.jsx';
import ResultTable from './ResultTable.jsx';
import SeriesChart from './SeriesChart.jsx';

// Die freie Auswertung: Frage zusammenstellen, Antwort als Tabelle und Diagramm.
//
// **Der ganze Abfragezustand steht in der Adresszeile** — Quelle, Gruppierung,
// Kennzahlen, Suchbedingung, Sortierung, Zeilenzahl und Schrittweite, dazu die
// globale Filterleiste. Ein Neuladen behält ihn, ein geteilter Link zeigt beim
// Empfänger dieselbe Auswertung, und die CSV-Ausgabe daneben ist derselbe Link
// mit einer anderen Endung.
//
// **Diagramm und Tabelle sind eine Abfrage und nicht zwei.** Beide kommen aus
// demselben Aufruf des Motors, einmal mit und einmal ohne Schrittweite; die
// Linien sind die obersten Zeilen der Tabelle. Deshalb steht am Diagramm nur
// eine Auswahl: **welche** Kennzahl gezeichnet wird. Alles andere daran zu
// ändern, hieße eine zweite Auswertung neben der ersten zu führen.
export default function DiscoverIndex({
    query,
    catalog,
    columns,
    table,
    series,
    error,
    seriesError,
    project,
    projectOptions,
    exportHref,
}) {
    const { shell } = usePage().props;
    const { t, formats } = useTranslations();

    const metricColumns = columns.filter((column) => column.kind === 'metric');
    const [metric, setMetric] = useState(metricColumns[0]?.key ?? '');
    const chartColumn = metricColumns.find((column) => column.key === metric) ?? metricColumns[0];

    return (
        <>
            <PageHead
                title={t('discover.title')}
                appName={shell.appName}
                help={t('discover.help')}
                meta={
                    exportHref && (
                        <a href={exportHref} download>
                            <SecondaryButton type="button">
                                {t('discover.export.action')}
                            </SecondaryButton>
                        </a>
                    )
                }
            />

            {project === null ? (
                <ProjectChoice options={projectOptions} t={t} />
            ) : (
                <>
                    <QueryBar query={query} catalog={catalog} columns={columns} t={t} />

                    {error && <QueryError error={error} t={t} />}

                    {seriesError && <QueryError error={seriesError} t={t} />}

                    {series && chartColumn && (
                        <Card className="mb-4">
                            <div className="mb-4 flex flex-wrap items-end justify-between gap-4">
                                <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                                    {t('discover.chart.title')}
                                </h2>

                                {metricColumns.length > 1 && (
                                    <div>
                                        <InputLabel
                                            htmlFor="discover_metric"
                                            value={t('discover.chart.metric')}
                                        />
                                        <SelectInput
                                            id="discover_metric"
                                            className="mt-1"
                                            value={chartColumn.key}
                                            options={metricColumns.map((column) => ({
                                                value: column.key,
                                                label: column.label,
                                            }))}
                                            onChange={(e) => setMetric(e.target.value)}
                                        />
                                    </div>
                                )}
                            </div>

                            <SeriesChart
                                series={series}
                                column={chartColumn}
                                t={t}
                                formats={formats}
                            />

                            {series.truncated && (
                                <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                    {t('discover.notes.series_limit', {
                                        count: series.lines.length,
                                    })}
                                </p>
                            )}
                        </Card>
                    )}

                    {table && (
                        <>
                            <div className="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                                <ResultTable
                                    columns={columns}
                                    table={table}
                                    t={t}
                                    formats={formats}
                                />
                            </div>

                            <Notes table={table} query={query} t={t} />
                        </>
                    )}
                </>
            )}
        </>
    );
}

// Was das Ergebnis über sich selbst sagt: abgeschnitten, aus dem
// Zwischenspeicher, Felder ohne Wirkung, unverstandene Suchbedingung.
//
// Unter der Tabelle und nicht in der Hilfe: „warum steht da nicht mehr" ist die
// zweite Frage nach dem Aufschlagen, und sie wird an der Tabelle gestellt.
function Notes({ table, query, t }) {
    const notes = [];

    if (table.truncated) {
        notes.push(t('discover.notes.truncated', { limit: query.limit }));
    }

    if (table.unavailable.length > 0) {
        notes.push(t('discover.notes.unavailable', { fields: table.unavailable.join(', ') }));
    }

    if (table.cached) {
        notes.push(t('discover.notes.cached'));
    }

    return (
        <div className="mt-3 space-y-1 text-xs text-gray-500 dark:text-gray-400">
            <p>{t('discover.table.rows', { count: table.rows.length })}</p>

            {table.searchError && (
                <p className="text-amber-700 dark:text-amber-400">
                    {t('discover.notes.search_error', {
                        position: table.searchError.position,
                        message: table.searchError.message,
                    })}{' '}
                    {t('discover.notes.search_error_hint')}
                </p>
            )}

            {notes.map((note) => (
                <p key={note}>{note}</p>
            ))}
        </div>
    );
}

// Eine abgelehnte Abfrage — mit Grenze und verlangtem Wert, damit sich die
// Abfrage an Ort und Stelle ändern lässt.
function QueryError({ error, t }) {
    const messages = {
        limit: () =>
            t('discover.error.limit', {
                limit: error.context.limit,
                allowed: error.context.allowed,
                given: error.context.given,
            }),
        timeout: () => t('discover.error.timeout', { timeout: error.context.timeout_ms }),
        unsupported: () => t('discover.error.unsupported', { what: error.context.what }),
        unknown_field: () => t('discover.error.unknown_field', { field: error.context.field }),
    };

    const message = (messages[error.reason] ?? (() => error.message))();

    return (
        <Card className="mb-4 border border-amber-300 dark:border-amber-500/40">
            <h2 className="text-base font-semibold text-amber-800 dark:text-amber-300">
                {t('discover.error.title')}
            </h2>
            <p className="mt-1 text-sm text-gray-700 dark:text-gray-300">{message}</p>
        </Card>
    );
}

// Ohne genau ein Projekt rechnet der Motor nicht — und die Seite rät nicht,
// welches gemeint war.
function ProjectChoice({ options, t }) {
    return (
        <Card>
            <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                {t('discover.project.required')}
            </h2>
            <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {t('discover.project.reason')}
            </p>

            {options.length === 0 ? (
                <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    {t('discover.project.none')}
                </p>
            ) : (
                <>
                    <p className="mt-4 text-sm font-medium text-gray-700 dark:text-gray-200">
                        {t('discover.project.choose')}
                    </p>
                    <ul className="mt-2 flex flex-wrap gap-2">
                        {options.map((option) => (
                            <li key={option.slug}>
                                <Link
                                    href={option.href}
                                    className="inline-flex rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                                >
                                    {option.name}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Card>
    );
}
