import React from 'react';
import { formatValue } from '../../discover/format.jsx';
import { LANDMASSES, MAP_HEIGHT, MAP_WIDTH, locate } from '../worldmap.js';

// Die Verteilung über Länder.
//
// **Eine Blasenkarte und keine Flächenkarte.** Ein eingefärbtes Land setzt
// voraus, dass man seine Grenze kennt — das wären Geodaten in der Größe der
// ganzen Anwendung. Eine Blase am Ort des Landes braucht zwei Zahlen und
// beantwortet dieselbe Frage: wo ist viel, wo ist wenig. Die Fläche der Blase
// ist proportional zum Wert (nicht der Radius): sonst sähe der doppelte Wert
// viermal so groß aus.
//
// **Was die Karte nicht zeigt, verschweigt sie nicht.** Länderkürzel, die die
// Ortsliste nicht kennt, und Zeilen ohne Land stehen als Aufzählung darunter.
// Sie stillschweigend wegzulassen hieße, eine Karte zu zeigen, deren Summe
// kleiner ist als die Zahl daneben.
export default function MapView({ columns, table, query, grid, t, formats }) {
    const groupColumn = columns.find((column) => column.kind === 'group');
    const valueColumn = columns.find((column) => column.kind === 'metric');
    const rows = table?.rows ?? [];

    // Ohne Gruppierung nach einem Land gibt es nichts einzufärben. Die Kachel
    // sagt das, statt ersatzweise etwas anderes zu zeigen.
    if (!groupColumn || !valueColumn) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('dashboards.widget.map.missing_field', { field: grid.countryField })}
            </p>
        );
    }

    if (rows.length === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('dashboards.widget.empty')}
            </p>
        );
    }

    const points = [];
    const unplaced = [];

    rows.forEach((row) => {
        const code = row.groups[groupColumn.key];
        const value = row.values[valueColumn.key];
        const at = locate(code);

        if (at === null || value === null || value === undefined) {
            unplaced.push(code ?? t('dashboards.widget.map.unknown'));

            return;
        }

        points.push({ code, value, at });
    });

    const max = points.reduce((highest, point) => Math.max(highest, point.value), 0);

    return (
        <div className="flex h-full flex-col">
            <svg
                viewBox={`0 0 ${MAP_WIDTH} ${MAP_HEIGHT}`}
                role="img"
                aria-label={valueColumn.label}
                className="min-h-0 w-full flex-1"
            >
                {LANDMASSES.map((points_, index) => (
                    <polygon
                        key={index}
                        points={points_.join(' ')}
                        className="fill-gray-200 dark:fill-gray-700"
                    />
                ))}

                {points.map((point) => (
                    <circle
                        key={point.code}
                        cx={point.at[0]}
                        cy={point.at[1]}
                        r={radius(point.value, max)}
                        className="fill-rose-500/70 stroke-rose-600"
                        strokeWidth="0.4"
                    >
                        <title>{`${point.code}: ${formatValue(point.value, valueColumn, t, formats)}`}</title>
                    </circle>
                ))}
            </svg>

            <p className="mt-2 text-[0.7rem] text-gray-500 dark:text-gray-400">
                {t('dashboards.widget.map.countries', { count: points.length })}
                {query?.fields?.length > 0 ? ` · ${valueColumn.label}` : ''}
                {unplaced.length > 0 &&
                    ` · ${t('dashboards.widget.map.unknown')}: ${unplaced.join(', ')}`}
            </p>
        </div>
    );
}

// Die Fläche wächst mit dem Wert, nicht der Radius — und eine kleinste Blase
// bleibt sichtbar, damit ein Land mit wenigen Ereignissen nicht verschwindet.
function radius(value, max) {
    if (max <= 0) {
        return 1.5;
    }

    return 1.5 + Math.sqrt(Math.max(value, 0) / max) * 7;
}
