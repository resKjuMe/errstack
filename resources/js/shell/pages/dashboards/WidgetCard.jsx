import React from 'react';
import { useTranslations } from '../../i18n.js';
import { useWidgetData } from './useWidgetData.js';
import SeriesView from './views/SeriesView.jsx';
import TableView from './views/TableView.jsx';
import NumberView from './views/NumberView.jsx';
import MapView from './views/MapView.jsx';

// Eine Kachel: Überschrift, Zahlen, und — wo man ändern darf — die Griffe zum
// Verschieben und Vergrößern.
//
// **Die Zahlen holt sie selbst.** Die Seite liefert nur ihre Abfrage; der Abruf
// läuft neben dem der anderen Kacheln. Solange er läuft, steht die Kachel
// bereits da — mit Überschrift und Rahmen —, statt dass der Bildschirm leer
// bleibt.
//
// **Ein eigener Ausschnitt steht an der Kachel.** Wenn sie einen anderen
// Zeitraum, eine andere Umgebung oder ein anderes Projekt zeigt als die
// Filterleiste oben, sagt sie es unter der Überschrift. Ohne diesen Vermerk wäre
// sie die gefährlichste Zahl auf dem Bildschirm: sie steht neben Kacheln, die
// etwas anderes meinen, und sieht genauso aus.
export default function WidgetCard({
    widget,
    dataHref,
    grid,
    editable,
    onEdit,
    onDelete,
    onMoveKey,
    dragHandlers,
    resizeHandlers,
    dragging,
}) {
    const { t, formats } = useTranslations();
    const { status, data, reload } = useWidgetData(widget, dataHref);

    return (
        <section
            className={`relative flex h-full flex-col overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800 ${
                dragging ? 'ring-2 ring-rose-500' : ''
            }`}
            aria-label={widget.title}
        >
            <header
                className={`flex items-start justify-between gap-2 border-b border-gray-100 px-3 py-2 dark:border-gray-700 ${
                    editable ? 'cursor-grab touch-none active:cursor-grabbing' : ''
                }`}
                {...(editable ? dragHandlers : {})}
            >
                <div className="min-w-0">
                    <h3
                        className="truncate text-sm font-semibold text-gray-900 dark:text-gray-100"
                        title={widget.title}
                    >
                        {widget.title}
                    </h3>

                    {data?.scope?.overridden && (
                        <p className="truncate text-[0.7rem] text-amber-700 dark:text-amber-400">
                            {t('dashboards.widget.scope', { range: data.scope.rangeLabel })}
                            {data.scope.project && ` · ${data.scope.project.name}`}
                            {data.scope.environment && ` · ${data.scope.environment}`}
                        </p>
                    )}
                </div>

                {editable && (
                    <div className="flex shrink-0 items-center gap-1">
                        <button
                            type="button"
                            onClick={onEdit}
                            className="rounded px-1.5 py-0.5 text-xs text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-100"
                        >
                            {t('dashboards.widget.edit')}
                        </button>
                        <button
                            type="button"
                            onClick={onDelete}
                            className="rounded px-1.5 py-0.5 text-xs text-gray-500 hover:bg-rose-50 hover:text-rose-700 dark:text-gray-400 dark:hover:bg-rose-900/30 dark:hover:text-rose-300"
                        >
                            {t('dashboards.widget.delete')}
                        </button>
                    </div>
                )}
            </header>

            <div className="min-h-0 flex-1 px-3 py-2">
                <Body
                    status={status}
                    data={data}
                    widget={widget}
                    grid={grid}
                    reload={reload}
                    t={t}
                    formats={formats}
                />
            </div>

            {editable && (
                <React.Fragment>
                    {/* Der Griff zum Vergrößern sitzt in der Ecke, dort, wo man ihn
                        sucht. Er ist zugleich der Knopf, den die Tastatur
                        bedient: mit den Pfeiltasten wird verschoben, mit
                        Umschalt die Größe geändert — Ziehen mit der Maus ist
                        damit nicht der einzige Weg. */}
                    <button
                        type="button"
                        aria-label={`${widget.title} — ${t('dashboards.grid.move')}`}
                        title={t('dashboards.grid.keyboard_hint')}
                        onKeyDown={onMoveKey}
                        {...resizeHandlers}
                        className="absolute bottom-0 right-0 h-5 w-5 cursor-se-resize touch-none rounded-tl border-l border-t border-gray-200 bg-white/80 text-gray-400 hover:text-gray-700 dark:border-gray-600 dark:bg-gray-800/80 dark:hover:text-gray-200"
                    >
                        <svg viewBox="0 0 10 10" className="h-full w-full p-1" aria-hidden="true">
                            <path
                                d="M9 3 3 9M9 7l-2 2"
                                stroke="currentColor"
                                strokeWidth="1.2"
                                fill="none"
                            />
                        </svg>
                    </button>
                </React.Fragment>
            )}
        </section>
    );
}

function Body({ status, data, widget, grid, reload, t, formats }) {
    if (status === 'failed') {
        return (
            <div className="text-sm text-gray-500 dark:text-gray-400">
                <p>{t('dashboards.widget.error.failed')}</p>
                <button
                    type="button"
                    onClick={reload}
                    className="mt-1 text-rose-700 underline hover:no-underline dark:text-rose-400"
                >
                    {t('dashboards.widget.error.retry')}
                </button>
            </div>
        );
    }

    if (!data) {
        return (
            <p className="text-sm text-gray-400 dark:text-gray-500">
                {t('dashboards.widget.loading')}
            </p>
        );
    }

    if (data.error) {
        return (
            <div className="text-sm">
                <p className="font-medium text-amber-800 dark:text-amber-300">
                    {t('dashboards.widget.error.title')}
                </p>
                <p className="mt-1 text-gray-600 dark:text-gray-300">{data.error.message}</p>
            </div>
        );
    }

    const column = data.columns.find((candidate) => candidate.kind === 'metric') ?? data.columns[0];

    if (data.series && column) {
        return (
            <SeriesView
                series={data.series}
                column={column}
                type={widget.type}
                t={t}
                formats={formats}
            />
        );
    }

    if (!data.table) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('dashboards.widget.empty')}
            </p>
        );
    }

    if (widget.type === 'big_number') {
        return <NumberView columns={data.columns} table={data.table} t={t} formats={formats} />;
    }

    if (widget.type === 'world_map') {
        return (
            <MapView
                columns={data.columns}
                table={data.table}
                query={widget.query}
                grid={grid}
                t={t}
                formats={formats}
            />
        );
    }

    return <TableView columns={data.columns} table={data.table} t={t} formats={formats} />;
}
