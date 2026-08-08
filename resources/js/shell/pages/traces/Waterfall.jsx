import React, { useEffect, useRef, useState } from 'react';
import { formatDuration } from '../../duration.js';
import { useTranslations } from '../../i18n.js';
import { ROW_HEIGHT, bar, visibleRows, windowFor } from './rows.js';

// Der Wasserfall: eine Zeile je Schritt, eingerückt nach Verschachtelung, mit
// einem Balken, dessen Ort die Zeit und dessen Breite die Dauer ist.
//
// Gezeichnet wird nur der sichtbare Ausschnitt. Bei tausend Zeilen wäre das noch
// nicht nötig, bei zehntausend ist es der Unterschied zwischen einer Seite und
// einem stehenden Browserfenster — und zehntausend Schritte hat eine Spur, die
// ein N+1-Problem enthält, also genau die, wegen der man hier nachsieht.
export default function Waterfall({ rows, totalUs, collapsed, onToggle, selected, onSelect }) {
    const { t, formats } = useTranslations();
    const viewport = useRef(null);
    const [scrollTop, setScrollTop] = useState(0);
    const [height, setHeight] = useState(600);

    // Die Fensterhöhe wird gemessen und nicht angenommen: sie hängt an der
    // Bildschirmgröße, und eine geratene Höhe zeichnet entweder zu wenig
    // (Löcher beim Rollen) oder zu viel (der Grund, warum hier überhaupt
    // gerechnet wird).
    useEffect(() => {
        const element = viewport.current;

        if (!element || typeof ResizeObserver === 'undefined') {
            return undefined;
        }

        const observer = new ResizeObserver(() => setHeight(element.clientHeight));

        observer.observe(element);
        setHeight(element.clientHeight);

        return () => observer.disconnect();
    }, []);

    const visible = visibleRows(rows, collapsed);
    const { first, last } = windowFor(scrollTop, height, visible.length);
    const slice = visible.slice(first, last);

    return (
        <div>
            <div
                ref={viewport}
                onScroll={(e) => setScrollTop(e.currentTarget.scrollTop)}
                className="max-h-[32rem] overflow-y-auto rounded-md border border-gray-200 dark:border-gray-700"
            >
                {/* Der Platzhalter trägt die volle Höhe aller Zeilen, damit der
                    Rollbalken die Länge der Spur zeigt und nicht die des
                    gezeichneten Ausschnitts. */}
                <div style={{ height: visible.length * ROW_HEIGHT, position: 'relative' }}>
                    <div
                        style={{
                            transform: `translateY(${first * ROW_HEIGHT}px)`,
                            position: 'absolute',
                            insetInlineStart: 0,
                            insetInlineEnd: 0,
                        }}
                    >
                        {slice.map((row) => (
                            <Row
                                key={row.key}
                                row={row}
                                totalUs={totalUs}
                                collapsed={collapsed.has(row.key)}
                                selected={selected === row.spanId}
                                onToggle={onToggle}
                                onSelect={onSelect}
                                t={t}
                                formats={formats}
                            />
                        ))}
                    </div>
                </div>
            </div>

            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {t('traces.waterfall.rows', { shown: visible.length, total: rows.length })}
            </p>
        </div>
    );
}

// Die Farbe sagt, was für ein Schritt es ist — Datenbank, fremder Dienst,
// Zwischenspeicher. Nach der Operation und nicht nach der Dauer: wie lange etwas
// dauern darf, hängt davon ab, was es ist, und die Einordnung soll beim Lesen
// zuerst da sein.
const OP_STYLES = [
    { prefix: 'db', bar: 'bg-amber-500/80', dot: 'bg-amber-500' },
    { prefix: 'http', bar: 'bg-sky-500/80', dot: 'bg-sky-500' },
    { prefix: 'cache', bar: 'bg-violet-500/80', dot: 'bg-violet-500' },
    { prefix: 'ui', bar: 'bg-emerald-500/80', dot: 'bg-emerald-500' },
    { prefix: 'view', bar: 'bg-emerald-500/80', dot: 'bg-emerald-500' },
    { prefix: 'queue', bar: 'bg-teal-500/80', dot: 'bg-teal-500' },
];

function styleFor(row) {
    if (row.kind === 'missing') {
        return { bar: 'bg-gray-300 dark:bg-gray-600', dot: 'bg-gray-400' };
    }

    if (row.errors.length > 0) {
        return { bar: 'bg-rose-500/80', dot: 'bg-rose-500' };
    }

    const op = row.op ?? '';
    const match = OP_STYLES.find(
        (style) => op === style.prefix || op.startsWith(`${style.prefix}.`)
    );

    return match ?? { bar: 'bg-indigo-500/80', dot: 'bg-indigo-500' };
}

function Row({ row, totalUs, collapsed, selected, onToggle, onSelect, t, formats }) {
    const style = styleFor(row);
    const geometry = bar(row, totalUs);
    const missing = row.kind === 'missing';

    const label = missing
        ? t('traces.waterfall.missing')
        : (row.label ?? t('traces.waterfall.no_description'));

    return (
        <div
            style={{ height: ROW_HEIGHT }}
            className={`flex items-center gap-2 border-b border-gray-100 pe-2 text-xs dark:border-gray-700/60 ${
                selected
                    ? 'bg-rose-50 dark:bg-rose-900/20'
                    : 'hover:bg-gray-50 dark:hover:bg-gray-700/40'
            }`}
        >
            {/* Linke Hälfte: Name, Einrückung, Aufklapper. */}
            <div
                className="flex min-w-0 items-center gap-1"
                style={{ width: '46%', paddingInlineStart: `${row.depth * 12 + 4}px` }}
            >
                {row.childCount > 0 ? (
                    <button
                        type="button"
                        onClick={() => onToggle(row.key)}
                        aria-expanded={!collapsed}
                        title={t(
                            collapsed ? 'traces.waterfall.expand' : 'traces.waterfall.collapse'
                        )}
                        className="w-4 shrink-0 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                    >
                        {collapsed ? '▸' : '▾'}
                    </button>
                ) : (
                    <span className="w-4 shrink-0" />
                )}

                <span className={`h-2 w-2 shrink-0 rounded-full ${style.dot}`} aria-hidden="true" />

                <button
                    type="button"
                    disabled={missing}
                    onClick={() => onSelect(row)}
                    title={missing ? t('traces.waterfall.missing_hint') : label}
                    className={`min-w-0 truncate text-start ${
                        missing
                            ? 'cursor-default text-gray-400 italic dark:text-gray-500'
                            : 'text-gray-800 hover:text-rose-600 dark:text-gray-200 dark:hover:text-rose-400'
                    }`}
                >
                    {row.op && (
                        <span className="me-1 font-mono text-gray-500 dark:text-gray-400">
                            {row.op}
                        </span>
                    )}
                    <span className={row.kind === 'transaction' ? 'font-medium' : ''}>{label}</span>
                </button>

                {row.errors.length > 0 && (
                    <span
                        title={t(
                            row.errors.length === 1
                                ? 'traces.waterfall.error'
                                : 'traces.waterfall.errors',
                            {
                                count: row.errors.length,
                            }
                        )}
                        className="ms-1 shrink-0 rounded bg-rose-100 px-1 font-medium text-rose-700 dark:bg-rose-900/40 dark:text-rose-300"
                    >
                        {row.errors.length}
                    </span>
                )}
            </div>

            {/* Rechte Hälfte: der Balken auf der Zeitachse. */}
            <div className="relative h-full min-w-0 flex-1">
                <div
                    style={{ insetInlineStart: `${geometry.left}%`, width: `${geometry.width}%` }}
                    className={`absolute top-1/2 h-2 -translate-y-1/2 rounded-sm ${style.bar} ${
                        missing
                            ? 'border border-dashed border-gray-400 bg-transparent dark:border-gray-500'
                            : ''
                    }`}
                />
            </div>

            <span className="w-20 shrink-0 text-end tabular-nums text-gray-500 dark:text-gray-400">
                {formatDuration(row.durationUs, t, formats)}
            </span>
        </div>
    );
}
