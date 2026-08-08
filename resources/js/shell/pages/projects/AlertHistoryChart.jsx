import React from 'react';

// Wie oft im gewählten Zeitraum etwas passiert ist — Abschnitt für Abschnitt.
//
// Die Frage dahinter ist nicht „wie hoch war der Wert?" (dafür gibt es bei den
// Schwellwert-Alarmen die Kurve daneben), sondern **„flattert das?"**. Ein Alarm,
// der in einer Nacht vierzig Mal auslöst und wieder aufgeht, ist kaputt, und
// genau das sieht man nur an der Häufigkeit.
//
// Deshalb Balken und keine Linie: gezählte Ereignisse sind keine Messreihe, und
// eine Linie zwischen zwei Zählungen behauptet Zwischenwerte, die es nicht gibt.
//
// Reines SVG ohne Bibliothek, wie die übrigen Grafiken der Anwendung — es sind
// zwei Dutzend Balken.
export default function AlertHistoryChart({ chart, t }) {
    const points = chart?.points ?? [];
    const max = Math.max(0, ...points.map((point) => point.value));

    if (points.length === 0 || max === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('alert_overview.chart.empty')}
            </p>
        );
    }

    const width = 100;
    const height = 32;
    const slot = width / points.length;
    // Ein Zwischenraum, der die Balken trennt, ohne sie bei vierundzwanzig
    // Abschnitten zu Strichen zu machen.
    const bar = slot * 0.7;

    return (
        <div>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                role="img"
                aria-label={t('alert_overview.chart.label')}
                className="h-24 w-full"
            >
                {points.map((point, index) => {
                    // Ein Abschnitt mit genau einer Auslösung soll sichtbar sein
                    // und nicht als Haarlinie verschwinden: die Höhe hat einen
                    // Sockel, sobald überhaupt etwas gezählt wurde.
                    const value = point.value === 0 ? 0 : Math.max(1, (point.value / max) * height);

                    return (
                        <rect
                            key={point.at}
                            x={index * slot + (slot - bar) / 2}
                            y={height - value}
                            width={bar}
                            height={value}
                            className="fill-indigo-500"
                        >
                            <title>{`${point.atLabel}: ${point.value}`}</title>
                        </rect>
                    );
                })}
            </svg>

            <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>{points[0]?.atLabel}</span>
                <span>{t('alert_overview.chart.total', { count: chart.total })}</span>
                <span>{points[points.length - 1]?.atLabel}</span>
            </div>

            {chart.truncated && (
                <p className="mt-2 text-xs text-amber-700 dark:text-amber-400">
                    {t('alert_overview.chart.truncated', { limit: chart.total })}
                </p>
            )}
        </div>
    );
}
