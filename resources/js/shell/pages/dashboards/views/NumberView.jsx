import React from 'react';
import { formatValue } from '../../discover/format.jsx';

// Eine einzige Zahl, groß — die Kennzahl, die man im Vorbeigehen liest.
//
// **Genommen wird die erste Kennzahl der ersten Zeile.** Die Abfrage dahinter
// liest genau eine Zeile (der Server kürzt sie dafür ein); eine Kachel mit
// Gruppierung zeigt damit die Spitze der Rangliste und nicht die Summe. Das ist
// die ehrlichere Lesart: aus „p95 = 400 ms" und „p95 = 900 ms" folgt kein
// gemeinsames p95, und eine addierte Zahl über Gruppen wäre bei jeder zweiten
// Kennzahl falsch.
//
// **Kein Wert ist etwas anderes als eine Null.** Keine Messung heißt nicht
// „null Millisekunden" — dieselbe Unterscheidung wie in den Leistungsseiten.
export default function NumberView({ columns, table, t, formats }) {
    const column = columns.find((candidate) => candidate.kind === 'metric') ?? columns[0];
    const row = table?.rows?.[0];
    const value = column && row ? row.values[column.key] : null;
    const formatted = column ? formatValue(value, column, t, formats) : null;

    // Der Gruppenwert der Zeile, sofern die Abfrage gruppiert: „1834" ohne
    // „Chrome" daneben wäre eine Zahl, von der niemand weiß, wovon sie handelt.
    const group = columns
        .filter((candidate) => candidate.kind === 'group')
        .map((candidate) => row?.groups?.[candidate.key] ?? '—')
        .join(' · ');

    return (
        <div className="flex h-full flex-col items-start justify-center">
            <p
                className="text-4xl font-semibold tabular-nums text-gray-900 dark:text-gray-100"
                title={column?.label ?? ''}
            >
                {formatted ?? (
                    <span className="text-2xl font-normal text-gray-400 dark:text-gray-500">
                        {t('dashboards.widget.number.no_value')}
                    </span>
                )}
            </p>

            <p className="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                {group === '' ? (column?.label ?? '') : `${column?.label ?? ''} · ${group}`}
            </p>
        </div>
    );
}
