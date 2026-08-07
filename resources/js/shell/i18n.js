import { usePage } from '@inertiajs/react';

// Zugriff auf die Übersetzungen in der Oberfläche. Übersetzt wird serverseitig
// (lang/<sprache>/*.php); hierher kommt nur die fertige Tabelle als Inertia-
// Shared-Prop `translations`, dazu die aktive Sprache in `locale`.
//
// Die Vorlagen tragen Laravel-Platzhalter (`:name`), die hier eingesetzt werden
// — so bleibt der Text im Sprachverzeichnis vollständig und lesbar, statt in
// der Oberfläche aus Fragmenten zusammengesetzt zu werden.

function interpolate(template, replacements) {
    let out = template;

    for (const [key, value] of Object.entries(replacements)) {
        out = out.replaceAll(`:${key}`, String(value));
    }

    return out;
}

export function makeT(strings) {
    return function t(key, replacements = {}) {
        const template = strings?.[key];

        if (typeof template !== 'string') {
            // Kein Schlüssel gefunden: der Schlüssel selbst ist das ehrlichste
            // Ergebnis — er fällt in der Oberfläche auf und benennt die Lücke.
            return key;
        }

        return interpolate(template, replacements);
    };
}

/**
 * `t` für die laufende Seite. Ohne Argumente in jeder Komponente benutzbar, weil
 * die Tabelle als Shared-Prop an jeder Inertia-Antwort hängt.
 */
export function useTranslations() {
    const { translations, locale, formats } = usePage().props;

    return {
        t: makeT(translations),
        locale,
        formats,
    };
}

/** Kurzform für Komponenten, die nur Texte brauchen. */
export function useT() {
    return useTranslations().t;
}

/**
 * Zahl in der Schreibweise der gewählten Sprache. `formats.intl` kommt aus
 * lang/<sprache>/formats.php, damit Server und Oberfläche dieselbe Quelle haben.
 */
export function formatNumber(value, formats, options = {}) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return '';
    }

    return new Intl.NumberFormat(formats?.intl ?? 'de-DE', options).format(Number(value));
}

/**
 * Zeitpunkt mit Datum und Uhrzeit. Erwartet einen ISO-Zeitstempel; alles, was
 * bereits serverseitig formatiert ankommt, geht unverändert durch die Oberfläche
 * und braucht diese Funktion nicht.
 */
export function formatDateTime(value, formats, options = {}) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return String(value);
    }

    return date.toLocaleString(formats?.intl ?? 'de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
        ...options,
    });
}
