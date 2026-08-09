import React from 'react';
import { formatDuration } from '../../duration.js';
import { formatNumber } from '../../i18n.js';

// Wie ein Wert der Auswertung dasteht.
//
// **Die Schreibweise hängt an der Spalte und nicht am Wert.** Der Server sagt zu
// jeder Kennzahl, was ihre Zahl ist (`format`, `unit`); hier wird daraus die
// Anzeige. Andersherum — aus der Zahl selbst zu raten — käme bei einer Dauer von
// 950 auf „950" statt „950 µs", und bei einer Fehlerquote von 0 auf dieselbe
// Null wie bei einer Anzahl.
//
// Gerechnet wird hier nichts: es sind dieselben Zahlen, die das Diagramm
// zeichnet und die in der CSV-Datei stehen.

// Kein Wert ist etwas anderes als eine Null: keine Messung heißt nicht „null
// Millisekunden". Dieselbe Unterscheidung wie in den Leistungsseiten.
export function Missing({ t }) {
    return (
        <span className="text-gray-400 dark:text-gray-600" title={t('discover.table.missing')}>
            —
        </span>
    );
}

export function formatValue(value, column, t, formats) {
    if (value === null || value === undefined) {
        return null;
    }

    if (column.format === 'duration') {
        return formatDuration(value, t, formats);
    }

    if (column.format === 'percent') {
        return `${formatNumber(value, formats, { maximumFractionDigits: 2 })} %`;
    }

    if (column.format === 'ratio') {
        return formatNumber(value, formats, { maximumFractionDigits: 2 });
    }

    return formatNumber(value, formats, { maximumFractionDigits: 2 });
}

// Der Wert einer Zelle — mit Strich, wo nichts steht.
export function Value({ value, column, t, formats }) {
    const formatted = formatValue(value, column, t, formats);

    return formatted === null ? <Missing t={t} /> : formatted;
}
