import React from 'react';
import { formatDateTime } from '../../i18n.js';
import { formatValue } from './format.jsx';

// Der Verlauf der Auswertung: eine Linie je Zeile der Tabelle.
//
// **Die Linien sind nicht neu bestimmt**, sondern kommen aus derselben Abfrage
// wie die Tabelle (der Motor liest erst die Rangliste und fragt dann die Reihe
// für genau diese Gruppen ab). Deshalb steht hier auch keine eigene Auswahl der
// Gruppen: was die Tabelle zeigt, zeigt das Diagramm — und was das Diagramm
// zeigt, steht in der Tabelle.
//
// Reines SVG ohne Bibliothek, wie die übrigen Grafiken der Anwendung.
//
// **Eine Lücke ist keine Null.** Der Motor füllt fehlende Stützstellen bei einer
// Anzahl mit 0 und bei allem anderen mit `null` — aus keiner Messung folgt keine
// Antwortzeit. Eine Linie wird deshalb an einem `null` unterbrochen und nicht
// über das Loch hinweggezogen: eine durchgezogene Linie behauptet Werte, die es
// nicht gab.
export default function SeriesChart({ series, column, t, formats }) {
    const lines = series?.lines ?? [];
    const at = series?.at ?? [];

    const values = lines.flatMap((line) =>
        (line.values[column.key] ?? []).filter((value) => value !== null && value !== undefined)
    );

    if (lines.length === 0 || at.length === 0 || values.length === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">{t('discover.chart.empty')}</p>
        );
    }

    const max = Math.max(...values);
    const width = 100;
    const height = 30;

    // Eine Achse, die bei 0 beginnt: bei Antwortzeiten und Anzahlen ist der
    // Abstand zur Null die Aussage. Ein Ausschnitt, der bei 480 ms anfängt,
    // macht aus zwei Prozent Unterschied einen Berg.
    const scale = (value) => height - (max === 0 ? 0 : (value / max) * height);
    const step = at.length > 1 ? width / (at.length - 1) : width;

    return (
        <div>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                role="img"
                aria-label={t('discover.chart.label')}
                className="h-48 w-full overflow-visible"
            >
                {lines.map((line, index) => (
                    <path
                        key={line.key}
                        d={path(line.values[column.key] ?? [], scale, step)}
                        fill="none"
                        strokeWidth="0.6"
                        vectorEffect="non-scaling-stroke"
                        className={strokeClass(index)}
                    />
                ))}
            </svg>

            <div className="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>{formatDateTime(at[0], formats)}</span>
                <span>{formatDateTime(at[at.length - 1], formats)}</span>
            </div>

            <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                {lines.map((line, index) => (
                    <li key={line.key} className="flex items-center gap-1.5">
                        <span className={`h-2 w-3 rounded-sm ${fillClass(index)}`} />
                        <span className="max-w-[16rem] truncate" title={line.label}>
                            {line.label}
                        </span>
                        <span className="text-gray-400 dark:text-gray-500">
                            {peak(line.values[column.key] ?? [], column, t, formats)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

// Der Pfad einer Linie, an Lücken unterbrochen: `M` beginnt ein neues Stück.
function path(values, scale, step) {
    const parts = [];
    let open = false;

    values.forEach((value, index) => {
        if (value === null || value === undefined) {
            open = false;

            return;
        }

        parts.push(`${open ? 'L' : 'M'}${(index * step).toFixed(2)} ${scale(value).toFixed(2)}`);
        open = true;
    });

    return parts.join(' ');
}

// Der größte Wert der Linie — die Zahl, an der sich zwei Linien im Diagramm
// unterscheiden lassen, ohne die Legende zu verlassen.
function peak(values, column, t, formats) {
    const known = values.filter((value) => value !== null && value !== undefined);

    return known.length === 0 ? '' : formatValue(Math.max(...known), column, t, formats);
}

// Feste Farben statt einer gerechneten Palette: sie stehen in derselben
// Reihenfolge wie die Zeilen der Tabelle, und zehn Linien sind die Grenze des
// Motors (`max_series_groups`).
const COLORS = [
    'indigo',
    'emerald',
    'amber',
    'rose',
    'sky',
    'violet',
    'lime',
    'orange',
    'teal',
    'fuchsia',
];

// Ausgeschriebene Klassennamen, damit Tailwind sie beim Bauen findet: eine
// zusammengesetzte Klasse (`text-${color}-500`) steht in keiner Datei und fehlt
// deshalb im fertigen Stylesheet.
const STROKES = {
    indigo: 'stroke-indigo-500',
    emerald: 'stroke-emerald-500',
    amber: 'stroke-amber-500',
    rose: 'stroke-rose-500',
    sky: 'stroke-sky-500',
    violet: 'stroke-violet-500',
    lime: 'stroke-lime-500',
    orange: 'stroke-orange-500',
    teal: 'stroke-teal-500',
    fuchsia: 'stroke-fuchsia-500',
};

const FILLS = {
    indigo: 'bg-indigo-500',
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
    rose: 'bg-rose-500',
    sky: 'bg-sky-500',
    violet: 'bg-violet-500',
    lime: 'bg-lime-500',
    orange: 'bg-orange-500',
    teal: 'bg-teal-500',
    fuchsia: 'bg-fuchsia-500',
};

function strokeClass(index) {
    return STROKES[COLORS[index % COLORS.length]];
}

function fillClass(index) {
    return FILLS[COLORS[index % COLORS.length]];
}
