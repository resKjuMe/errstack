import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

// Der gemeinsame Zugang zur globalen Filterleiste. Jede Auswertungsseite bekommt
// vom Server die Nutzlast `filter` (App\Support\FilterData::bar) und ruft damit
// diesen Hook auf; die Leiste selbst zeichnet FilterBar.jsx.
//
// Der Zustand lebt in der Adresszeile, nicht in React: eine Änderung ist ein
// Seitenaufruf mit neuen Parametern. Damit übersteht die Auswahl das Neuladen,
// funktioniert im Verlauf vor und zurück und lässt sich als Link teilen — und der
// Server rechnet den Zeitraum genau einmal aus, für Ansicht wie Export.
export default function useGlobalFilter(filter) {
    const [form, setForm] = useState(filter.value);
    const pinnedTimezone = useRef(false);

    // Kommt der Server mit anderen Werten zurück (eigene Eingabe, Verlauf,
    // geteilter Link), gelten seine — er hat den Zeitraum aufgelöst.
    useEffect(() => {
        setForm(filter.value);
    }, [filter.value]);

    // Die Zeitzone kennt nur der Browser. Steht sie noch nicht in der Adresszeile
    // und weicht von der des Servers ab, wird sie einmal nachgetragen — danach
    // steckt sie im Link und die Bedingung greift nicht mehr.
    useEffect(() => {
        if (pinnedTimezone.current) {
            return;
        }

        pinnedTimezone.current = true;
        const browser = browserTimezone();

        if (browser && browser !== filter.timezone && !currentQuery().has('tz')) {
            visit({ ...filter.value, tz: browser }, { replace: true });
        }
    }, [filter.timezone, filter.value]);

    // Eine Änderung übernimmt sofort: wer den Zeitraum umstellt, will das
    // Ergebnis sehen und nicht erst „Filtern" drücken.
    const apply = (patch) => {
        const next = { ...form, ...patch, tz: browserTimezone() || form.tz };

        setForm(next);
        visit(next);
    };

    // Ohne Filter-Parameter setzt der Server seine Voreinstellungen ein; die
    // Felder ziehen mit seiner Antwort nach. Zurückgesetzt wird die **Leiste**
    // und nicht die Seite — Sortierung und Suche bleiben stehen.
    const reset = () => go(carryOver(new URLSearchParams()));

    return {
        form,
        apply,
        reset,
        // Bequemer Zugriff für die Leiste — ein Projekt an- oder abwählen.
        toggleProject: (slug) =>
            apply({
                projects: form.projects.includes(slug)
                    ? form.projects.filter((current) => current !== slug)
                    : [...form.projects, slug],
            }),
    };
}

// Ein Filterzustand als Adresszeile. Leere Felder bleiben weg, damit dort nicht
// `?environment=&from=` steht; die Datumsfelder nur beim eigenen Zeitraum, sonst
// löst der Server den relativen selbst auf.
export function filterQuery(form) {
    const query = new URLSearchParams();

    form.projects.forEach((slug) => query.append('projects[]', slug));

    if (form.environment) {
        query.set('environment', form.environment);
    }

    if (form.period) {
        query.set('period', form.period);
    }

    if (form.period === 'custom') {
        query.set('from', form.from);
        query.set('to', form.to);
    }

    if (form.tz) {
        query.set('tz', form.tz);
    }

    return query;
}

// Die Parameter, die der Leiste gehören. Alles andere in der Adresszeile gehört
// der Seite.
const OWNED = new Set(['projects[]', 'projects', 'environment', 'period', 'from', 'to', 'tz']);

// Übernimmt die Parameter der Seite in eine neue Adresszeile.
//
// Sortierung und Suche einer Auswertungsseite sollen das Umstellen des Zeitraums
// überleben — wer nach p95 sortiert hat und auf „letzte 7 Tage" wechselt, will
// dieselbe Frage über einen anderen Zeitraum und nicht von vorn anfangen. Die
// Seitenzahl allerdings nicht: ein anderer Zeitraum hat andere Zeilen, und
// „Seite 7" von etwas anderem gibt es nicht.
function carryOver(query) {
    currentQuery().forEach((value, key) => {
        if (!OWNED.has(key) && key !== 'page') {
            query.append(key, value);
        }
    });

    return query;
}

function visit(form, options = {}) {
    go(carryOver(filterQuery(form)), options);
}

function go(query, options = {}) {
    const search = query.toString();

    router.get(
        search ? `${window.location.pathname}?${search}` : window.location.pathname,
        {},
        {
            preserveState: true,
            preserveScroll: true,
            ...options,
        }
    );
}

function currentQuery() {
    return new URLSearchParams(window.location.search);
}

function browserTimezone() {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
    } catch {
        return '';
    }
}
