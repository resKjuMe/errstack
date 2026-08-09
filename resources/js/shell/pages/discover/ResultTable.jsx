import React from 'react';
import { Link } from '@inertiajs/react';
import { Missing, Value } from './format.jsx';

// Die Tabelle der Auswertung: die Gruppierung links, die Kennzahlen rechts.
//
// Eine echte Tabelle und keine Liste aus Flex-Zeilen — anders als bei den
// Leistungsseiten, deren Spalten feststehen: hier bestimmt die Abfrage, wie
// viele Spalten es gibt, und ein Raster, das erst zur Laufzeit entsteht, ist
// genau das, wofür es `<table>` gibt. Auf schmalen Geräten scrollt sie
// waagerecht, statt die Zahlen umzubrechen.
//
// **Die Zeile führt zu den Ereignissen dahinter** — sofern eine Ansicht genau
// dieselbe Menge zeigen kann. Wo nicht, bleibt die Zeile ohne Link, und der
// Grund steht als Hinweis daran (App\Support\Discover\DiscoverDrilldown): ein
// Link auf „ungefähr diese Zeilen" wäre der, nach dem niemand mehr weiß, warum
// die Zahlen nicht zusammenpassen.
export default function ResultTable({ columns, table, t, formats }) {
    if (table.rows.length === 0) {
        return (
            <div className="p-6 text-sm text-gray-500 dark:text-gray-400">
                <p>{t('discover.table.empty')}</p>
                <p className="mt-1 text-xs">{t('discover.table.empty_hint')}</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
                <thead>
                    <tr className="border-b border-gray-100 bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                        {columns.map((column) => (
                            <th
                                key={column.key}
                                scope="col"
                                className={`px-4 py-2 ${column.kind === 'metric' ? 'text-right' : 'text-left'}`}
                            >
                                {column.label}
                                {column.unit !== '' && (
                                    <span className="ms-1 font-normal normal-case">
                                        ({column.unit})
                                    </span>
                                )}
                            </th>
                        ))}
                        <th scope="col" className="w-10 px-4 py-2" />
                    </tr>
                </thead>

                <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                    {table.rows.map((row, index) => (
                        <tr key={index} className="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={`px-4 py-2 ${
                                        column.kind === 'metric'
                                            ? 'text-right tabular-nums text-gray-900 dark:text-gray-100'
                                            : 'max-w-xs truncate text-gray-700 dark:text-gray-300'
                                    }`}
                                    title={
                                        column.kind === 'group'
                                            ? (row.groups[column.key] ?? undefined)
                                            : undefined
                                    }
                                >
                                    {column.kind === 'group' ? (
                                        (row.groups[column.key] ?? <Missing t={t} />)
                                    ) : (
                                        <Value
                                            value={row.values[column.key]}
                                            column={column}
                                            t={t}
                                            formats={formats}
                                        />
                                    )}
                                </td>
                            ))}

                            <td className="px-4 py-2 text-right">
                                {row.href === null ? (
                                    <span
                                        className="text-gray-300 dark:text-gray-600"
                                        title={t('discover.table.no_drilldown')}
                                    >
                                        →
                                    </span>
                                ) : (
                                    <Link
                                        href={row.href}
                                        title={t('discover.table.drilldown')}
                                        className="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    >
                                        →
                                    </Link>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
