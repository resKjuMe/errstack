import React from 'react';
import { formatDateTime } from '../../../i18n.js';
import { formatValue } from '../../discover/format.jsx';
import { strokeClass, swatchClass, textClass } from './colors.js';

// Der Verlauf einer Kachel — als Linie, gefüllte Fläche oder Balken.
//
// **Drei Darstellungen und eine Rechnung.** Die Daten sind dieselbe Zeitreihe
// des Motors; was sich unterscheidet, ist die Form. Deshalb steht hier eine
// Komponente mit einer Weiche und nicht dreimal fast dasselbe: eine Änderung an
// der Achse oder an der Behandlung von Lücken gälte sonst für eine der drei.
//
// **Eine Lücke ist keine Null.** Der Motor füllt fehlende Stützstellen bei einer
// Anzahl mit 0 und bei allem anderen mit `null` — aus keiner Messung folgt keine
// Antwortzeit. Die Linie wird deshalb an einem `null` unterbrochen, die Fläche
// ebenso, und ein Balken fehlt dort schlicht. Eine durchgezogene Linie
// behauptete Werte, die es nicht gab.
//
// Reines SVG ohne Bibliothek, wie die übrigen Grafiken der Anwendung.
export default function SeriesView({ series, column, type, t, formats }) {
    const lines = series?.lines ?? [];
    const at = series?.at ?? [];

    const values = lines.flatMap((line) =>
        (line.values[column.key] ?? []).filter((value) => value !== null && value !== undefined)
    );

    if (lines.length === 0 || at.length === 0 || values.length === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('dashboards.widget.empty')}
            </p>
        );
    }

    const max = Math.max(...values);
    const width = 100;
    const height = 30;

    // Eine Achse, die bei 0 beginnt: bei Antwortzeiten und Anzahlen ist der
    // Abstand zur Null die Aussage — dieselbe Entscheidung wie in der freien
    // Auswertung.
    const scale = (value) => height - (max === 0 ? 0 : (value / max) * height);
    const step = at.length > 1 ? width / (at.length - 1) : width;

    return (
        <div className="flex h-full flex-col">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                role="img"
                aria-label={column.label}
                className="min-h-0 w-full flex-1 overflow-visible"
            >
                {type === 'bar'
                    ? lines.map((line, index) => (
                          <Bars
                              key={line.key}
                              values={line.values[column.key] ?? []}
                              scale={scale}
                              height={height}
                              width={width}
                              index={index}
                              count={lines.length}
                          />
                      ))
                    : lines.map((line, index) => (
                          <Curve
                              key={line.key}
                              values={line.values[column.key] ?? []}
                              scale={scale}
                              step={step}
                              height={height}
                              index={index}
                              filled={type === 'area'}
                          />
                      ))}
            </svg>

            <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 text-[0.7rem] text-gray-500 dark:text-gray-400">
                <span>{formatDateTime(at[0], formats)}</span>
                <span>{formatValue(max, column, t, formats)}</span>
                <span>{formatDateTime(at[at.length - 1], formats)}</span>
            </div>

            {lines.length > 1 && (
                <ul className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[0.7rem] text-gray-600 dark:text-gray-300">
                    {lines.map((line, index) => (
                        <li key={line.key} className="flex items-center gap-1.5">
                            <span className={`h-2 w-3 rounded-sm ${swatchClass(index)}`} />
                            <span className="max-w-[10rem] truncate" title={line.label}>
                                {line.label}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

// Linie oder Fläche. Die Fläche ist dieselbe Kurve, unten geschlossen — und sie
// wird je zusammenhängendem Stück geschlossen, damit über einer Lücke keine
// Fläche entsteht, die niemand gemessen hat.
function Curve({ values, scale, step, height, index, filled }) {
    const segments = [];
    let current = [];

    values.forEach((value, position) => {
        if (value === null || value === undefined) {
            if (current.length > 0) {
                segments.push(current);
                current = [];
            }

            return;
        }

        current.push([position * step, scale(value)]);
    });

    if (current.length > 0) {
        segments.push(current);
    }

    return (
        <>
            {filled &&
                segments
                    .filter((segment) => segment.length > 1)
                    .map((segment, position) => (
                        <path
                            key={`fill-${position}`}
                            d={
                                `M${segment[0][0].toFixed(2)} ${height}` +
                                segment
                                    .map(([x, y]) => ` L${x.toFixed(2)} ${y.toFixed(2)}`)
                                    .join('') +
                                ` L${segment[segment.length - 1][0].toFixed(2)} ${height} Z`
                            }
                            className={`${textClass(index)} opacity-20`}
                            fill="currentColor"
                            stroke="none"
                        />
                    ))}

            {segments.map((segment, position) => (
                <path
                    key={`line-${position}`}
                    d={segment
                        .map(
                            ([x, y], point) =>
                                `${point === 0 ? 'M' : 'L'}${x.toFixed(2)} ${y.toFixed(2)}`
                        )
                        .join(' ')}
                    fill="none"
                    strokeWidth="0.6"
                    vectorEffect="non-scaling-stroke"
                    className={strokeClass(index)}
                />
            ))}
        </>
    );
}

// Balken. Bei mehreren Reihen stehen sie nebeneinander und nicht gestapelt: ein
// Stapel beantwortet die Frage „wie viel zusammen", eine Kachel mit mehreren
// Reihen wurde aber gebaut, um sie zu vergleichen.
function Bars({ values, scale, height, width, index, count }) {
    const slot = values.length > 0 ? width / values.length : width;
    const barWidth = Math.max(slot / count - slot * 0.1, 0.2);

    return (
        <>
            {values.map((value, position) =>
                value === null || value === undefined ? null : (
                    <rect
                        key={position}
                        x={position * slot + index * barWidth}
                        y={scale(value)}
                        width={barWidth}
                        height={Math.max(height - scale(value), 0.2)}
                        className={textClass(index)}
                        fill="currentColor"
                    />
                )
            )}
        </>
    );
}
