import React from 'react';
import { formatNumber } from '../../i18n.js';

// Die Schreibweisen, die sich Übersicht, Detailanalyse und die
// Leistungsprobleme teilen.
//
// Sie stehen hier und nicht zweimal, weil eine Antwortzeit auf beiden Seiten
// gleich aussehen muss: „1,2 s" auf der einen und „1200 ms" auf der anderen
// wären zwei Zahlen für denselben Wert, und wer zwischen den Seiten wechselt,
// vergleicht sie.

// Ein fehlender Wert ist etwas anderes als eine Null: keine Messung heißt nicht
// „null Millisekunden".
export function Missing() {
    return <span className="text-gray-400 dark:text-gray-600">—</span>;
}

// Die Einheit richtet sich nach der Größe: Mikrosekunden für Einzelschritte,
// Millisekunden für Antwortzeiten, Sekunden für alles, was jemand merkt. Eine
// feste Einheit wäre entweder „0 ms" für eine Datenbankabfrage oder
// „4200000 µs" für einen hängenden Aufruf.
export function duration(microseconds, t, formats) {
    if (microseconds === null || microseconds === undefined) {
        return <Missing />;
    }

    if (microseconds < 1000) {
        return `${formatNumber(microseconds, formats)} ${t('performance.units.microseconds')}`;
    }

    if (microseconds < 1_000_000) {
        const milliseconds = microseconds / 1000;

        return `${formatNumber(milliseconds, formats, {
            maximumFractionDigits: milliseconds < 10 ? 1 : 0,
        })} ${t('performance.units.milliseconds')}`;
    }

    return `${formatNumber(microseconds / 1_000_000, formats, {
        maximumFractionDigits: 2,
    })} ${t('performance.units.seconds')}`;
}

export function percent(ratio, formats) {
    return `${formatNumber(ratio * 100, formats, { maximumFractionDigits: 2 })} %`;
}

// Größen in Bytes, in der Einheit, die zur Größenordnung passt — dieselbe
// Überlegung wie bei den Dauern. 1024 und nicht 1000, weil Browser und
// Betriebssystem es ebenso rechnen.
export function bytes(value, t, formats) {
    if (value === null || value === undefined) {
        return <Missing />;
    }

    if (value < 1024) {
        return `${formatNumber(value, formats)} ${t('performance.units.bytes')}`;
    }

    if (value < 1024 * 1024) {
        return `${formatNumber(value / 1024, formats, { maximumFractionDigits: 0 })} ${t(
            'performance.units.kilobytes'
        )}`;
    }

    return `${formatNumber(value / (1024 * 1024), formats, { maximumFractionDigits: 1 })} ${t(
        'performance.units.megabytes'
    )}`;
}
