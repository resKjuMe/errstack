import React from 'react';
import { formatNumber } from '../../i18n.js';

// Schreibweisen, die auf allen Profiling-Seiten gleich aussehen sollen.
//
// Eine eigene Datei und keine Wiederholung je Seite: Flamegraph, Funktionsliste
// und Vergleich zeigen dieselben Zahlen nebeneinander, und zwei Stellen, die
// „1,2 ms" und „1200 µs" schreiben, lesen sich wie zwei verschiedene Messungen.

// Zeiten kommen in Mikrosekunden aus dem Server (siehe
// App\Support\Profiling\FlamegraphData). Die Einheit wechselt mit der
// Größenordnung, weil „0,000042 s" niemand liest und „42000000 µs" auch nicht.
export function duration(microseconds, t, formats) {
    if (microseconds === null || microseconds === undefined) {
        return <Missing />;
    }

    if (microseconds < 1000) {
        return `${formatNumber(microseconds, formats)} ${t('profiling.units.microseconds')}`;
    }

    if (microseconds < 1_000_000) {
        const milliseconds = microseconds / 1000;

        return `${formatNumber(milliseconds, formats, {
            maximumFractionDigits: milliseconds < 10 ? 1 : 0,
        })} ${t('profiling.units.milliseconds')}`;
    }

    return `${formatNumber(microseconds / 1_000_000, formats, {
        maximumFractionDigits: 2,
    })} ${t('profiling.units.seconds')}`;
}

// Anteile kommen als Zahl zwischen 0 und 1. Eine Nachkommastelle: der
// Unterschied zwischen 12,3 % und 12,4 % der Rechenzeit ist Rauschen aus der
// Abtastung, und ihn auszuweisen behauptet eine Genauigkeit, die es nicht gibt.
export function percent(ratio, formats, options = {}) {
    return `${formatNumber(ratio * 100, formats, { maximumFractionDigits: 1, ...options })} %`;
}

export function Missing() {
    return <span className="text-gray-400 dark:text-gray-500">—</span>;
}

// Ein Rahmen als Text: Funktion, davor das Modul, dahinter die Fundstelle.
// Ohne Namen bleibt ein Platzhalter stehen statt einer leeren Zeile — ein Rahmen
// ohne Namen ist trotzdem einer, und seine Zeit gehört irgendwohin.
export function frameLabel(frame, t) {
    if (!frame) {
        return t('profiling.flamegraph.unknown_frame');
    }

    return frame.module ? `${frame.module}::${frame.function}` : frame.function;
}

export function frameLocation(frame) {
    if (!frame?.file) {
        return '';
    }

    return frame.line ? `${frame.file}:${frame.line}` : frame.file;
}
