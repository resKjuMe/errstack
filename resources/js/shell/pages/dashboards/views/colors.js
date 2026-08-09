// Die Farben der Reihen — dieselben zehn wie im Diagramm der freien Auswertung
// und in derselben Reihenfolge, damit dieselbe Abfrage hier und dort dieselbe
// Linie in derselben Farbe zeigt.
//
// **Ausgeschriebene Klassennamen, keine zusammengesetzten.** Eine Klasse wie
// `text-${color}-500` steht in keiner Datei und fehlt deshalb im fertigen
// Stylesheet — Tailwind findet nur, was wörtlich dasteht.
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

const BACKGROUNDS = {
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

// Für Flächen im SVG: die Farbe kommt über `currentColor` und nicht über
// `fill-*`, weil sich so dieselbe Klasse auch für eine abgeschwächte Fläche
// (`opacity-…`) benutzen lässt.
const TEXTS = {
    indigo: 'text-indigo-500',
    emerald: 'text-emerald-500',
    amber: 'text-amber-500',
    rose: 'text-rose-500',
    sky: 'text-sky-500',
    violet: 'text-violet-500',
    lime: 'text-lime-500',
    orange: 'text-orange-500',
    teal: 'text-teal-500',
    fuchsia: 'text-fuchsia-500',
};

function color(index) {
    return COLORS[index % COLORS.length];
}

export function strokeClass(index) {
    return STROKES[color(index)];
}

/** Das Farbfeld einer Legende. */
export function swatchClass(index) {
    return BACKGROUNDS[color(index)];
}

/** Die Füllfarbe einer SVG-Form (über `currentColor`). */
export function textClass(index) {
    return TEXTS[color(index)];
}
