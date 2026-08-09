import React from 'react';

// Wie sich eine Version ausgebreitet hat.
//
// Die Frage, die eine einzelne Zahl offenlässt: **steigt das Ausrollen noch,
// oder steht es?** Ein Ausrollen, das bei einem Drittel hängen bleibt, sieht in
// der Momentaufnahme aus wie eines, das gerade erst begonnen hat.
//
// Eine Linie und keine Balken: der Anteil ist eine Messgröße, die zwischen zwei
// Abschnitten tatsächlich Zwischenwerte hat — anders als gezählte Ereignisse.
//
// Reines SVG ohne Bibliothek, wie die übrigen Grafiken der Anwendung.
export default function AdoptionChart({ adoption, t }) {
    const points = adoption?.points ?? [];

    if (!adoption?.hasProjectData) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('releases.adoption.empty_project')}
            </p>
        );
    }

    if (!adoption.hasData) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('releases.adoption.empty')}
            </p>
        );
    }

    const width = 100;
    const height = 32;
    const step = points.length > 1 ? width / (points.length - 1) : 0;

    // Der Maßstab geht immer bis hundert: die Verbreitung ist ein Anteil, und
    // eine Kurve, die bei ihrem eigenen Höchstwert an die Decke stößt, sähe bei
    // 4 % genauso aus wie bei 96 %.
    const y = (value) => height - (value / 100) * height;

    // Lücken sind Lücken: ein Abschnitt ohne Sitzungen des Projekts unterbricht
    // die Linie, statt sie über die Stelle hinwegzuziehen. Eine durchgezogene
    // Kurve über einer stillen Nacht behauptet einen Einbruch, den es nicht gab.
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

    const last = [...points].reverse().find((point) => point.value !== null);

    return (
        <div>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                role="img"
                aria-label={t('releases.adoption.label')}
                className="h-32 w-full"
            >
                {segments.map((segment, index) =>
                    // Ein einzelner Punkt ist keine Linie: `polyline` mit einem
                    // Punkt zeichnet nichts, und eine Version, von der es genau
                    // ein Fenster mit Sitzungen gibt, hätte eine leere Grafik —
                    // also dieselbe Anzeige wie eine ohne jede Sitzung.
                    segment.length === 1 ? (
                        <circle
                            key={index}
                            cx={segment[0].split(',')[0]}
                            cy={segment[0].split(',')[1]}
                            r="1"
                            vectorEffect="non-scaling-stroke"
                            className="fill-indigo-500"
                        />
                    ) : (
                        <polyline
                            key={index}
                            points={segment.join(' ')}
                            fill="none"
                            strokeWidth="1"
                            strokeLinejoin="round"
                            vectorEffect="non-scaling-stroke"
                            className="stroke-indigo-500"
                        />
                    )
                )}
            </svg>

            <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>{points[0]?.atLabel}</span>
                {last && <span className="font-medium">{last.valueLabel}</span>}
                <span>{points[points.length - 1]?.atLabel}</span>
            </div>

            <p className="mt-2 text-xs text-gray-400 dark:text-gray-500">
                {t('releases.adoption.gap_hint')}
            </p>
        </div>
    );
}
