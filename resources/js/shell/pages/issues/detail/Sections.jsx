import React from 'react';

// Die Bausteine, aus denen die Detailseite ihre Abschnitte baut.
//
// Sie stehen zusammen in einer Datei, weil sie eine gemeinsame Entscheidung
// tragen: eine Meldung enthält beliebig geformte Daten, und die Anzeige muss
// jede Form vertragen, ohne vorher zu wissen, welche kommt. Ein Feld ist mal ein
// Wort, mal eine Tabelle, mal ein verschachtelter Baum — und alles davon soll
// lesbar dastehen, nicht als `[object Object]`.

// Ein Wert als Text. Objekte und Listen bleiben JSON, weil jede andere
// Darstellung raten müsste, was der Wert bedeutet.
function valueText(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'string') {
        return value;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    return JSON.stringify(value, null, 2);
}

// Ist der Wert mehrzeilig? Danach entscheidet sich, ob er neben seinen
// Schlüssel passt oder darunter gehört.
function isBlock(value) {
    return value !== null && typeof value === 'object';
}

// Ein Feld aus Schlüsseln und Werten — die Form, in der die meisten Abschnitte
// einer Meldung ankommen (Kopfzeilen, Merkmale, Gerätedaten).
export function KeyValues({ values }) {
    const entries = Array.isArray(values)
        ? values.map((pair) => [pair.key, pair.value])
        : Object.entries(values ?? {});

    if (entries.length === 0) {
        return null;
    }

    return (
        <dl className="divide-y divide-gray-100 text-sm dark:divide-gray-700">
            {entries.map(([key, value]) => (
                <div key={key} className="grid grid-cols-1 gap-1 py-2 sm:grid-cols-3 sm:gap-4">
                    <dt className="font-medium break-all text-gray-500 dark:text-gray-400">
                        {key}
                    </dt>
                    <dd className="sm:col-span-2">
                        {isBlock(value) ? (
                            <Json value={value} />
                        ) : (
                            <span className="break-all text-gray-900 dark:text-gray-100">
                                {valueText(value)}
                            </span>
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

// Freiform als eingerücktes JSON. Der Rahmen scrollt waagerecht statt umzubrechen
// — eine umgebrochene Zeile Code ist schwerer zu lesen als eine, die man schiebt.
export function Json({ value }) {
    return (
        <pre className="overflow-x-auto rounded-md bg-gray-50 p-3 font-mono text-xs whitespace-pre text-gray-800 dark:bg-gray-900/60 dark:text-gray-200">
            {typeof value === 'string' ? value : JSON.stringify(value, null, 2)}
        </pre>
    );
}

// Ein Abschnitt der Seite. Er erscheint nur, wenn er etwas zu sagen hat: eine
// Karte „Nutzer — nichts übermittelt" ist Platz ohne Auskunft.
export function Section({ title, description = null, children, when = true }) {
    if (!when) {
        return null;
    }

    return (
        <section className="rounded-lg bg-white p-6 shadow dark:bg-gray-800">
            <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">{title}</h2>
            {description && (
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{description}</p>
            )}
            <div className="mt-4">{children}</div>
        </section>
    );
}

// Hat ein Abschnitt Inhalt? Leere Objekte, leere Listen und `null` zählen nicht.
export function hasContent(value) {
    if (value === null || value === undefined) {
        return false;
    }

    if (Array.isArray(value)) {
        return value.length > 0;
    }

    if (typeof value === 'object') {
        return Object.keys(value).length > 0;
    }

    return true;
}
