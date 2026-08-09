import React from 'react';
import SeriesView from '../dashboards/views/SeriesView.jsx';
import { formatValue } from '../discover/format.jsx';

// Ein Verlauf auf einer Übersichtsseite.
//
// **Gezeichnet wird mit der Komponente der Dashboard-Kacheln.** Es ist dieselbe
// Zeitreihe desselben Motors; eine zweite Zeichenroutine daneben wäre eine
// zweite Entscheidung darüber, wie eine Lücke aussieht und wo die Achse
// beginnt — und dann sähe derselbe Verlauf auf zwei Seiten verschieden aus.
//
// Übersetzt wird dafür nur die Form: der Server liefert hier **eine** Reihe,
// die Kachel-Ansicht erwartet eine Liste von Linien.
export default function SeriesPanel({ panel, t, formats }) {
    const { at, values, column } = panel.series;

    const series = {
        at,
        lines: [{ key: column.key, label: column.label, values: { [column.key]: values } }],
    };

    return (
        <div className="flex h-full flex-col">
            <p className="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                {formatValue(panel.total, column, t, formats) ?? '—'}
                <span className="ms-2 text-xs font-normal text-gray-500 dark:text-gray-400">
                    {column.label}
                </span>
            </p>

            <div className="mt-3 h-24">
                <SeriesView series={series} column={column} type="area" t={t} formats={formats} />
            </div>
        </div>
    );
}
