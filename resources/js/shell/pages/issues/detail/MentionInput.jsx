import React, { useCallback, useEffect, useRef, useState } from 'react';

// Ein Textfeld, das beim `@` Vorschläge anbietet.
//
// Genannt wird mit dem Namen und nicht mit einem Kürzel — die gibt es in dieser
// Anwendung nicht (siehe App\Support\Issues\Mentions). Ein Name mit Leerzeichen
// lässt sich aber nicht erraten, solange er halb getippt ist: „@Anna" könnte
// Anna Beck meinen oder das Team „Anna und Kollegen". Deshalb die Liste — sie
// ist hier nicht Bequemlichkeit, sondern das, was aus dem freien Text eine
// verlässliche Nennung macht.
//
// Gefragt wird der Server, und zwar erst nach einer Tippause: welche Konten und
// Teams es gibt, weiß nur er, und die Mitgliederliste in jede Fehlerseite zu
// schreiben wäre ein Vielfaches der Seite für ein Feld, das die meisten Aufrufe
// nie anfassen. Dieselbe Bauart wie das Suchfeld der Liste (SearchInput.jsx).

// Der angefangene Name vor dem Schreibmarker: ein `@` am Wortanfang, danach bis
// zu vier Wörter. Dieselbe Form, die der Server beim Auflösen sucht — beide
// Seiten müssen zum selben Ergebnis kommen.
const TOKEN = /(?:^|[^\p{L}\p{N}_@])@([\p{L}\p{N}_.\-&]*(?: [\p{L}\p{N}_.\-&]+){0,3})$/u;

export default function MentionInput({
    id,
    value,
    onChange,
    suggestHref,
    placeholder,
    rows = 3,
    maxLength,
    autoFocus = false,
    t,
}) {
    const [suggestions, setSuggestions] = useState([]);
    const [active, setActive] = useState(-1);
    const [open, setOpen] = useState(false);
    // Welcher Bereich ersetzt wird, wenn ein Vorschlag übernommen wird: vom `@`
    // bis zum Schreibmarker. Er wird beim Fragen festgehalten, damit ein
    // Vorschlag nicht an einer Stelle einsetzt, die inzwischen woanders liegt.
    const [replace, setReplace] = useState(null);
    const [pending, setPending] = useState(null);

    const field = useRef(null);
    // Antworten kommen nicht der Reihe nach. Ohne diese Marke überschriebe eine
    // langsame ältere Antwort die schnellere neue.
    const latest = useRef(0);

    const load = useCallback(
        (term, from, to) => {
            const ticket = ++latest.current;
            const url = new URL(suggestHref, window.location.origin);

            url.searchParams.set('q', term);

            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    if (ticket !== latest.current || data === null) {
                        return;
                    }

                    const found = data.suggestions ?? [];

                    setSuggestions(found);
                    setReplace({ from, to });
                    setActive(-1);
                    setOpen(found.length > 0);
                })
                .catch(() => {
                    // Vorschläge sind Beiwerk: fällt der Aufruf aus, bleibt das
                    // Feld ein gewöhnliches Textfeld. Eine Fehlermeldung dafür
                    // stünde in keinem Verhältnis — der Name lässt sich
                    // ausschreiben, und der Server erkennt ihn trotzdem.
                });
        },
        [suggestHref]
    );

    useEffect(() => {
        if (pending === null) {
            return undefined;
        }

        const timer = setTimeout(() => load(pending.term, pending.from, pending.to), 150);

        return () => clearTimeout(timer);
    }, [pending, load]);

    // Steht der Schreibmarker in einer angefangenen Nennung? Wenn nicht, ist die
    // Liste zu, und es wird auch nicht gefragt.
    const ask = (element) => {
        const caret = element.selectionStart ?? element.value.length;
        const before = element.value.slice(0, caret);
        const match = TOKEN.exec(before);

        if (match === null) {
            setOpen(false);
            setPending(null);

            return;
        }

        setPending({ term: match[1], from: caret - match[1].length - 1, to: caret });
    };

    const accept = (suggestion) => {
        const input = field.current;

        if (!input || !replace) {
            return;
        }

        const head = value.slice(0, replace.from) + '@' + suggestion.name + ' ';
        const next = head + value.slice(replace.to);

        onChange(next);
        setOpen(false);

        // Nach dem Zustandswechsel, sonst setzt React den Schreibmarker beim
        // Neuzeichnen wieder ans Ende.
        window.requestAnimationFrame(() => {
            input.focus();
            input.setSelectionRange(head.length, head.length);
        });
    };

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            setOpen(false);

            return;
        }

        if (!open || suggestions.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((at) => (at + 1) % suggestions.length);

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((at) => (at <= 0 ? suggestions.length - 1 : at - 1));

            return;
        }

        // Die Eingabetaste bedeutet in einem mehrzeiligen Feld „neue Zeile" und
        // soll das auch bleiben. Nur solange ein Vorschlag markiert ist,
        // übernimmt sie ihn.
        if ((event.key === 'Enter' || event.key === 'Tab') && active >= 0) {
            event.preventDefault();
            accept(suggestions[active]);
        }
    };

    return (
        <div className="relative">
            <textarea
                id={id}
                ref={field}
                rows={rows}
                autoFocus={autoFocus}
                maxLength={maxLength}
                value={value}
                placeholder={placeholder}
                role="combobox"
                aria-expanded={open}
                aria-controls={`${id}_suggestions`}
                aria-autocomplete="list"
                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500"
                onChange={(event) => {
                    onChange(event.target.value);
                    ask(event.target);
                }}
                onClick={(event) => ask(event.target)}
                onKeyUp={(event) => ask(event.target)}
                onKeyDown={onKeyDown}
                onBlur={() => window.setTimeout(() => setOpen(false), 120)}
            />

            {open && suggestions.length > 0 && (
                <ul
                    id={`${id}_suggestions`}
                    role="listbox"
                    className="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                >
                    {suggestions.map((suggestion, at) => (
                        <li
                            key={`${suggestion.kind}-${suggestion.name}`}
                            role="option"
                            aria-selected={at === active}
                        >
                            <button
                                type="button"
                                // `onMouseDown` und nicht `onClick`: der Klick
                                // käme nach dem Verlassen des Feldes, und das
                                // hat die Liste da schon geschlossen.
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    accept(suggestion);
                                }}
                                onMouseEnter={() => setActive(at)}
                                className={`flex w-full items-baseline gap-3 px-3 py-1.5 text-left text-sm ${
                                    at === active
                                        ? 'bg-indigo-50 dark:bg-gray-700'
                                        : 'text-gray-700 dark:text-gray-300'
                                }`}
                            >
                                <span className="text-gray-900 dark:text-gray-100">
                                    {suggestion.name}
                                </span>
                                <span className="ms-auto text-xs text-gray-500 dark:text-gray-400">
                                    {t(`issues.comments.kind.${suggestion.kind}`)}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
