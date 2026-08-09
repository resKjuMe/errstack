import React from 'react';
import { Value } from '../../discover/format.jsx';

// Die Rangliste einer Kachel.
//
// **Dieselben Spalten wie in der freien Auswertung**, nur enger gesetzt: eine
// Kachel ist ein Ausschnitt und keine Seite. Was eine Zahl bedeutet, sagt
// weiterhin die Spalte (`format`, `unit`) und nicht der Wert — sonst stünde bei
// einer Dauer von 950 eine nackte 950.
export default function TableView({ columns, table, t, formats }) {
    const rows = table?.rows ?? [];

    if (rows.length === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('dashboards.widget.empty')}
            </p>
        );
    }

    return (
        <div className="-mx-1 h-full overflow-auto">
            <table className="min-w-full text-sm">
                <thead className="sticky top-0 bg-white text-left text-[0.7rem] uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                scope="col"
                                className={`px-1 py-1 font-medium ${column.kind === 'metric' ? 'text-right' : ''}`}
                            >
                                {column.label}
                                {column.unit && (
                                    <span className="ml-1 normal-case text-gray-400">
                                        [{column.unit}]
                                    </span>
                                )}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                    {rows.map((row, index) => (
                        <tr key={index}>
                            {columns.map((column) =>
                                column.kind === 'group' ? (
                                    <td
                                        key={column.key}
                                        className="max-w-[16rem] truncate px-1 py-1 text-gray-700 dark:text-gray-200"
                                        title={row.groups[column.key] ?? ''}
                                    >
                                        {/* Ein fehlender Gruppenwert ist nicht „leer", sondern
                                            „ohne Angabe" — derselbe Strich wie überall. */}
                                        {row.groups[column.key] ?? '—'}
                                    </td>
                                ) : (
                                    <td
                                        key={column.key}
                                        className="whitespace-nowrap px-1 py-1 text-right tabular-nums text-gray-900 dark:text-gray-100"
                                    >
                                        <Value
                                            value={row.values[column.key]}
                                            column={column}
                                            t={t}
                                            formats={formats}
                                        />
                                    </td>
                                )
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
