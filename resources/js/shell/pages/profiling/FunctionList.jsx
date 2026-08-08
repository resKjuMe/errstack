import React, { useMemo, useState } from 'react';
import { formatNumber, useTranslations } from '../../i18n.js';
import { duration, percent } from './format.jsx';

// Die Funktionsliste neben dem Flamegraph.
//
// Sie beantwortet die Frage, die das Bild nicht beantwortet: dieselbe
// Hilfsfunktion, von zwanzig Stellen aus aufgerufen, ist dort zwanzig schmale
// Balken und hier eine Zeile. Erst dadurch fällt auf, dass sie zusammen ein
// Drittel der Zeit kostet.
//
// Sortiert wird im Browser und nicht über die Adresszeile — anders als in der
// Performance-Übersicht. Der Unterschied ist die Datenmenge: dort steht eine
// Seite von vielen und ein Klick auf eine Spalte ist eine andere Abfrage, hier
// liegt die ganze Liste bereits vor. Ein Serverbesuch für eine Umsortierung
// wäre eine Wartezeit ohne Gegenwert.

const COLUMNS = [
    { key: 'function', numeric: false },
    { key: 'self', numeric: true },
    { key: 'total', numeric: true },
    { key: 'samples', numeric: true },
];

const VALUES = {
    function: (row) => `${row.module ?? ''}${row.function}`.toLowerCase(),
    self: (row) => row.selfUs,
    total: (row) => row.totalUs,
    samples: (row) => row.samples,
};

export default function FunctionList({ functions, totalUs, limit, functionCount }) {
    const { t, formats } = useTranslations();
    const [sort, setSort] = useState('self');
    const [descending, setDescending] = useState(true);

    const rows = useMemo(() => {
        const value = VALUES[sort] ?? VALUES.self;
        const sorted = [...functions].sort((a, b) => {
            const left = value(a);
            const right = value(b);

            if (left === right) {
                return 0;
            }

            return (left < right ? -1 : 1) * (descending ? -1 : 1);
        });

        return sorted;
    }, [functions, sort, descending]);

    const toggle = (column) => {
        if (column.key === sort) {
            setDescending((v) => !v);

            return;
        }

        setSort(column.key);
        // Eine neue Spalte beginnt mit der Richtung, die dort etwas zeigt: bei
        // Zahlen das Größte zuerst, bei Namen das Alphabet.
        setDescending(column.numeric);
    };

    if (rows.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-gray-600 dark:text-gray-300">
                {t('profiling.functions.empty')}
            </p>
        );
    }

    return (
        <div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="border-b border-gray-200 text-xs uppercase dark:border-gray-700">
                        <tr>
                            {COLUMNS.map((column) => (
                                <Header
                                    key={column.key}
                                    column={column}
                                    active={column.key === sort}
                                    descending={descending}
                                    onSort={() => toggle(column)}
                                />
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                        {rows.map((row, position) => (
                            <tr
                                key={`${row.module ?? ''}|${row.function}|${row.file ?? ''}|${position}`}
                                className="align-top"
                            >
                                <td className="px-3 py-2">
                                    <div className="max-w-160">
                                        <span className="font-mono break-all text-gray-900 dark:text-gray-100">
                                            {row.module
                                                ? `${row.module}::${row.function}`
                                                : row.function}
                                        </span>
                                        {row.inApp && (
                                            <span className="ms-2 rounded bg-rose-100 px-1 text-[10px] text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
                                                {t('profiling.functions.in_app')}
                                            </span>
                                        )}
                                        {row.file && (
                                            <div className="text-xs break-all text-gray-500 dark:text-gray-400">
                                                {row.line ? `${row.file}:${row.line}` : row.file}
                                            </div>
                                        )}
                                    </div>
                                </td>
                                <td className="px-3 py-2 text-end tabular-nums">
                                    {duration(row.selfUs, t, formats)}
                                    <div className="text-xs text-gray-500 dark:text-gray-400">
                                        {percent(totalUs > 0 ? row.selfUs / totalUs : 0, formats)}
                                    </div>
                                </td>
                                <td className="px-3 py-2 text-end tabular-nums">
                                    {duration(row.totalUs, t, formats)}
                                </td>
                                <td className="px-3 py-2 text-end tabular-nums">
                                    {formatNumber(row.samples, formats)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {functionCount > rows.length && (
                <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {t('profiling.functions.limit', {
                        limit: formatNumber(limit, formats),
                        total: formatNumber(functionCount, formats),
                    })}
                </p>
            )}
        </div>
    );
}

function Header({ column, active, descending, onSort }) {
    const { t } = useTranslations();
    const label = t(`profiling.functions.columns.${column.key}`);

    // `aria-sort` gehört an die Zelle und nicht an die Schaltfläche darin —
    // Vorleseprogramme lesen es an der Spalte.
    const ariaSort = !active ? 'none' : descending ? 'descending' : 'ascending';

    // Angekündigt wird, was ein Klick **täte**, nicht was gerade gilt.
    const next = active
        ? descending
            ? 'ascending'
            : 'descending'
        : column.numeric
          ? 'descending'
          : 'ascending';

    return (
        <th
            scope="col"
            aria-sort={ariaSort}
            className={`px-3 py-2 font-medium ${column.numeric ? 'text-end' : 'text-start'}`}
        >
            <button
                type="button"
                onClick={onSort}
                title={t(`profiling.functions.sort.${next}`, { column: label })}
                className={`inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-gray-100 ${
                    active ? 'text-gray-900 dark:text-gray-100' : 'text-gray-500 dark:text-gray-400'
                }`}
            >
                {label}
                <span aria-hidden="true" className={active ? '' : 'opacity-0'}>
                    {descending ? '↓' : '↑'}
                </span>
            </button>
        </th>
    );
}
