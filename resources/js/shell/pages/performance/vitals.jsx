import React from 'react';
import { formatDuration } from '../../duration.js';
import { formatNumber } from '../../i18n.js';
import { Missing } from './format.jsx';

// Die Schreibweisen und Farben, die sich Übersicht und Detailseite des
// Ladeerlebnisses teilen.
//
// Sie stehen hier und nicht zweimal, aus demselben Grund wie bei den
// Antwortzeiten: derselbe Messwert muss auf beiden Seiten gleich aussehen. Ein
// LCP, das in der Liste „2,4 s" heißt und auf der Detailseite „2400 ms", sind
// zwei Zahlen für denselben Wert — und wer zwischen den Seiten wechselt,
// vergleicht sie.

// Alle Messwerte kommen in Millionsteln vom Server. Für die Dauern ist das die
// Mikrosekunde, und damit dieselbe Einheit, die die ganze Oberfläche ohnehin
// schreibt — der Verschiebungswert ist der einzige Sonderfall.
export function vitalValue(value, vital, t, formats) {
    if (value === null || value === undefined) {
        return <Missing />;
    }

    if (!vital.score) {
        const formatted = formatDuration(value, t, formats);

        return formatted === null ? <Missing /> : formatted;
    }

    // Der Verschiebungswert ist eine Punktzahl ohne Einheit. Zwei
    // Nachkommastellen, weil die Schwellen bei 0,1 und 0,25 liegen: mit einer
    // fielen „gut" und „mäßig" auf dieselbe Anzeige.
    return formatNumber(value / 1_000_000, formats, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

// Dieselbe Zahl als reiner Text — für Titel-Hinweise (`title`), in denen kein
// Element stehen darf.
export function plainVitalValue(value, vital, t, formats) {
    if (value === null || value === undefined) {
        return '—';
    }

    if (!vital.score) {
        return formatDuration(value, t, formats) ?? '—';
    }

    return formatNumber(value / 1_000_000, formats, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

// Die drei Farben der Bewertung. Grün, Bernstein, Rot — dieselbe Reihenfolge,
// die die Web-Vitals-Spezifikation und jedes Messwerkzeug verwendet, damit
// niemand sie hier neu lernen muss.
//
// Die Farbe steht nie **allein** für die Bewertung: neben jedem farbigen Punkt
// steht sein Wort. Rot und Grün sind für einen erheblichen Teil der Leser nicht
// zu unterscheiden, und eine Seite, deren einzige Aussage in der Farbe steckt,
// hätte für sie gar keine.
const RATING_CLASSES = {
    good: 'bg-emerald-500 dark:bg-emerald-400',
    needs_improvement: 'bg-amber-500 dark:bg-amber-400',
    poor: 'bg-rose-500 dark:bg-rose-400',
};

const RATING_TEXT_CLASSES = {
    good: 'text-emerald-700 dark:text-emerald-300',
    needs_improvement: 'text-amber-700 dark:text-amber-300',
    poor: 'text-rose-700 dark:text-rose-300',
};

export function ratingColor(rating) {
    return RATING_CLASSES[rating] ?? 'bg-gray-300 dark:bg-gray-600';
}

// Ein Punkt samt Wort. Ohne Bewertung — also ohne Messung — bleibt der Strich
// aus `format.jsx`: „keine Daten" ist etwas anderes als „null".
export function RatingBadge({ rating, label }) {
    if (!rating) {
        return <Missing />;
    }

    return (
        <span
            className={`inline-flex items-center gap-1.5 text-xs font-medium ${
                RATING_TEXT_CLASSES[rating] ?? ''
            }`}
        >
            <span aria-hidden="true" className={`size-2 rounded-full ${ratingColor(rating)}`} />
            {label}
        </span>
    );
}

// Die Verteilung als ein Balken aus drei Abschnitten.
//
// Anteile und keine absoluten Zahlen: die Frage ist „wie vielen Besuchern geht
// es gut", und die beantwortet ein Verhältnis. Die Zahlen dahinter stehen im
// Titel-Hinweis, damit sie nicht verloren sind.
export function DistributionBar({ summary, t, formats }) {
    if (summary.count === 0) {
        return <Missing />;
    }

    const title = t('web_vitals.row.distribution', {
        good: percentLabel(summary.shares.good, formats),
        needs: percentLabel(summary.shares.needs_improvement, formats),
        poor: percentLabel(summary.shares.poor, formats),
    });

    return (
        <span
            title={title}
            aria-label={title}
            className="flex h-1.5 w-full min-w-16 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
        >
            {['good', 'needs_improvement', 'poor'].map((rating) => (
                <span
                    key={rating}
                    className={ratingColor(rating)}
                    style={{ width: `${(summary.shares[rating] ?? 0) * 100}%` }}
                />
            ))}
        </span>
    );
}

function percentLabel(share, formats) {
    return `${formatNumber((share ?? 0) * 100, formats, { maximumFractionDigits: 0 })} %`;
}
