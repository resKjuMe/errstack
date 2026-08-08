import { formatNumber } from './i18n.js';

// Dauern kommen in Mikrosekunden vom Server — die Einheit, in der gemessen
// wird. Die Anzeige wechselt mit der Größenordnung: „1500000 µs" liest niemand
// als anderthalb Sekunden, und „0 ms" wäre für eine schnelle Abfrage schlicht
// falsch.
//
// An einer Stelle für die ganze Oberfläche: die Performance-Übersicht und der
// Wasserfall zeigen dieselben Messwerte, und zwei Schreibweisen für dieselbe
// Zahl wären ein Widerspruch, den niemand auflösen kann.
export function formatDuration(microseconds, t, formats) {
    if (microseconds === null || microseconds === undefined || Number.isNaN(Number(microseconds))) {
        return null;
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
