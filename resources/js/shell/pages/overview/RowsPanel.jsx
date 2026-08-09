import React from 'react';
import { Link } from '@inertiajs/react';
import { formatValue } from '../discover/format.jsx';
import StatsPanel from './StatsPanel.jsx';

// Eine kurze Liste: Rangliste, jüngste Einträge, offene Punkte.
//
// **Jede Zeile führt irgendwohin**, und wo eine Zeile mehrere Zahlen trägt,
// führt auch jede Zahl irgendwohin — „412 Fehler" neben einem Projektnamen ist
// der Weg in dessen Fehlerliste und nicht bloß eine Beschriftung.
//
// **Ein Projekt, das noch nichts gemeldet hat, steht mit dem Weg in die
// Einrichtung da** und nicht mit einer Null: die Null sähe aus wie „läuft und
// macht keine Fehler".
export default function RowsPanel({ panel, emptyText, t, formats }) {
    return (
        <div>
            {panel.stats.length > 0 && (
                <div className="mb-3">
                    <StatsPanel stats={panel.stats} t={t} formats={formats} />
                </div>
            )}

            {/* Kennzahlen ohne Liste kommen vor — „drei Teams" ist eine
                Auskunft, auch wenn keines davon aufgeführt wird. Der Hinweis
                tritt deshalb an die Stelle der Liste und nicht der Kachel. */}
            {panel.rows.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">{emptyText}</p>
            ) : (
                <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    {panel.rows.map((row) => (
                        <li key={row.key} className="flex items-center justify-between gap-3 py-2">
                            <div className="min-w-0">
                                <Link
                                    href={row.href}
                                    className="block truncate font-medium text-gray-900 hover:text-rose-600 dark:text-gray-100 dark:hover:text-rose-400"
                                    title={row.title}
                                >
                                    {row.title}
                                </Link>

                                <p className="truncate text-xs text-gray-500 dark:text-gray-400">
                                    {row.pending
                                        ? t('overview.setup.pending')
                                        : (row.subtitle ?? '')}
                                </p>
                            </div>

                            <div className="flex shrink-0 items-center gap-3">
                                {row.badge && <Badge label={row.badge} tone={row.tone} />}

                                {(row.values ?? []).map((value, index) => (
                                    <Metric
                                        key={`${row.key}-${index}`}
                                        metric={value}
                                        t={t}
                                        formats={formats}
                                    />
                                ))}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function Metric({ metric, t, formats }) {
    const formatted = formatValue(metric.value, metric, t, formats) ?? '—';

    const body = (
        <>
            <span className="block text-right text-sm font-semibold text-gray-900 dark:text-gray-100">
                {formatted}
            </span>
            <span className="block text-right text-[0.7rem] text-gray-500 dark:text-gray-400">
                {metric.label}
            </span>
        </>
    );

    return metric.href ? (
        <Link href={metric.href} className="hover:text-rose-600 dark:hover:text-rose-400">
            {body}
        </Link>
    ) : (
        <span>{body}</span>
    );
}

// Der Zustand einer Zeile in einem Wort. Rot nur für „kritisch": ein Bildschirm,
// auf dem alles rot ist, sagt nichts mehr.
function Badge({ label, tone }) {
    const tones = {
        critical: 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-200',
        warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200',
    };

    return (
        <span
            className={`rounded-full px-2 py-0.5 text-[0.7rem] font-medium ${
                tones[tone] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'
            }`}
        >
            {label}
        </span>
    );
}
