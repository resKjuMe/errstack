import React from 'react';
import { formatDuration } from '../../duration.js';
import { formatNumber } from '../../i18n.js';

// Die Schreibweisen, die sich Übersicht und Detailanalyse teilen.
//
// Sie stehen hier und nicht zweimal, weil eine Antwortzeit auf beiden Seiten
// gleich aussehen muss: „1,2 s" auf der einen und „1200 ms" auf der anderen
// wären zwei Zahlen für denselben Wert, und wer zwischen den Seiten wechselt,
// vergleicht sie.
//
// Die Umrechnung selbst steht noch eine Ebene höher, in `shell/duration.js`:
// die Trace-Ansicht (PF4) zeigt dieselben Messwerte und ist keine
// Performance-Seite. Hier bleibt, was die Darstellung in einer Tabelle
// ausmacht — vor allem der Umgang mit dem, was gar nicht gemessen wurde.

// Ein fehlender Wert ist etwas anderes als eine Null: keine Messung heißt nicht
// „null Millisekunden".
export function Missing() {
    return <span className="text-gray-400 dark:text-gray-600">—</span>;
}

// Dieselbe Zahl wie im Wasserfall, nur mit einem Strich statt einer leeren
// Zelle, wenn nichts gemessen wurde.
export function duration(microseconds, t, formats) {
    const formatted = formatDuration(microseconds, t, formats);

    return formatted === null ? <Missing /> : formatted;
}

export function percent(ratio, formats) {
    return `${formatNumber(ratio * 100, formats, { maximumFractionDigits: 2 })} %`;
}
