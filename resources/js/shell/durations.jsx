import React from 'react';
import { formatNumber } from './i18n.js';

// Dauern kommen in Mikrosekunden vom Server — die Einheit, in der gemessen
// wird. Die Anzeige wechselt mit der Größenordnung: „1500000 µs" liest niemand
// als anderthalb Sekunden, und „0 ms" wäre für eine schnelle Abfrage schlicht
// falsch.
//
// An einer Stelle für alle Ansichten, die Dauern zeigen — die
// Performance-Übersicht und die Leistungsprobleme. Zwei Kopien wären zwei
// Schreibweisen für dieselbe Zahl, und der Unterschied fiele erst auf, wenn
// jemand die beiden Seiten nebeneinander legt.
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

// Ein fehlender Wert als Strich und nicht als Lücke: eine leere Zelle sieht aus
// wie ein Darstellungsfehler, ein Strich sagt „hier gibt es nichts".
export function Missing() {
    return <span className="text-gray-400 dark:text-gray-600">—</span>;
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
