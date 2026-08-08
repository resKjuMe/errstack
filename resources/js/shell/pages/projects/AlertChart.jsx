import React from 'react';

// Der Verlauf einer Kennzahl mit den Schwellen darüber.
//
// Der Zweck der Grafik ist eine einzige Frage: **liegt die Schwelle, die ich
// gerade eintippe, überhaupt in der Nähe der Wirklichkeit?** Deshalb steht die
// Linie nicht neben der Kurve, sondern darin — und deshalb wird der Maßstab so
// gewählt, dass beide hineinpassen: eine Kurve, aus deren Bild die Schwelle
// herausfällt, beantwortet die Frage nicht.
//
// Reines SVG ohne Bibliothek, wie die Verlaufsgrafik der Fehlerliste: es sind
// zwei Dutzend Werte und drei Linien.
export default function AlertChart({ preview, t }) {
    const points = preview.points ?? [];
    const thresholds = preview.thresholds ?? [];

    const values = points.map((point) => point.value).filter((value) => value !== null);
    const lines = thresholds.map((line) => line.value);

    if (values.length === 0 && lines.length === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">{t('alerts.preview.empty')}</p>
        );
    }

    // Der Maßstab umfasst Werte **und** Schwellen. Die Null ist immer dabei:
    // ohne sie sähe eine Kurve, die zwischen 980 und 1000 pendelt, aus wie ein
    // Gebirge.
    const max = Math.max(0, ...values, ...lines);
    const min = Math.min(0, ...values, ...lines);
    const span = max - min || 1;

    const width = 100;
    const height = 32;
    const step = points.length > 1 ? width / (points.length - 1) : 0;

    const y = (value) => height - ((value - min) / span) * height;

    // Lücken sind Lücken: ein Fenster ohne Aussage unterbricht die Linie, statt
    // sie über die Stelle hinwegzuziehen. Eine durchgezogene Kurve über einer
    // Messlücke ist eine erfundene Angabe.
    const segments = [];
    let current = [];

    points.forEach((point, index) => {
        if (point.value === null) {
            if (current.length > 0) {
                segments.push(current);
                current = [];
            }

            return;
        }

        current.push(`${(index * step).toFixed(2)},${y(point.value).toFixed(2)}`);
    });

    if (current.length > 0) {
        segments.push(current);
    }

    const tone = {
        warning: 'stroke-amber-500',
        critical: 'stroke-rose-500',
        resolve: 'stroke-emerald-500',
    };

    return (
        <div>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                role="img"
                aria-label={t('alerts.preview.label', { minutes: preview.windowMinutes })}
                className="h-24 w-full"
            >
                {thresholds.map((line) => (
                    <line
                        key={line.status}
                        x1="0"
                        x2={width}
                        y1={y(line.value)}
                        y2={y(line.value)}
                        strokeWidth="0.6"
                        strokeDasharray="2 1.5"
                        className={tone[line.status] ?? 'stroke-gray-400'}
                        vectorEffect="non-scaling-stroke"
                    />
                ))}

                {segments.map((segment, index) => (
                    <polyline
                        key={index}
                        points={segment.join(' ')}
                        fill="none"
                        strokeWidth="1"
                        strokeLinejoin="round"
                        className="stroke-indigo-500"
                        vectorEffect="non-scaling-stroke"
                    />
                ))}
            </svg>

            <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>{points[0]?.atLabel}</span>

                <span className="flex flex-wrap items-center gap-3">
                    {thresholds.map((line) => (
                        <span key={line.status} className="flex items-center gap-1">
                            <span
                                aria-hidden="true"
                                className={`inline-block h-0.5 w-4 ${
                                    {
                                        warning: 'bg-amber-500',
                                        critical: 'bg-rose-500',
                                        resolve: 'bg-emerald-500',
                                    }[line.status] ?? 'bg-gray-400'
                                }`}
                            />
                            {line.label}: {line.valueLabel} {preview.unit}
                        </span>
                    ))}
                </span>

                <span>{points[points.length - 1]?.atLabel}</span>
            </div>
        </div>
    );
}
