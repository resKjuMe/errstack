import React, { useCallback, useEffect, useRef, useState } from 'react';
import { InputLabel, TextInput } from '../../components/Form.jsx';

// Das Suchfeld der Fehlerliste — mit Vorschlägen an der Stelle des
// Schreibmarkers.
//
// Ohne Vorschläge ist eine Suchsprache eine Sprache, die man auswendig können
// muss. Deshalb kommt beim Tippen eine Liste: erst die Felder (`is:`,
// `browser:`), und sobald eines steht, die Werte, die es in den gewählten
// Projekten wirklich gibt. Was ersetzt wird, sagt der Server mit (`from`/`to`) —
// die Oberfläche sucht die Wortgrenze nicht ein zweites Mal und kommt damit
// nicht zu einem anderen Ergebnis als die Stelle, die den Ausdruck zerlegt.
//
// Gesucht wird mit der Eingabetaste und nicht beim Tippen: eine Abfrage über die
// ganze Liste je Tastenanschlag wäre eine Last ohne Nutzen. Die Vorschläge sind
// die Ausnahme — sie sind klein, gehen an eine eigene Adresse und warten eine
// Tippause ab.
export default function SearchInput({ value, onChange, onSubmit, suggestHref, t }) {
    const [suggestions, setSuggestions] = useState([]);
    const [replace, setReplace] = useState(null);
    const [active, setActive] = useState(-1);
    const [open, setOpen] = useState(false);

    const field = useRef(null);
    // Welche Abfrage zuletzt losgeschickt wurde. Antworten sind nicht der Reihe
    // nach da; ohne diese Marke überschriebe eine langsame ältere Antwort die
    // schnellere neue, und in der Liste stünden Vorschläge zum vorletzten
    // Buchstaben.
    const latest = useRef(0);

    const load = useCallback(
        (input, cursor) => {
            const ticket = ++latest.current;
            const url = new URL(suggestHref, window.location.origin);

            url.searchParams.set('q', input);
            url.searchParams.set('cursor', String(cursor));

            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    if (ticket !== latest.current || data === null) {
                        return;
                    }

                    setSuggestions(data.suggestions ?? []);
                    // Die Eingabe, für die dieser Bereich gilt, kommt mit. Wer
                    // in der Tippause weiterschreibt, hätte sonst einen
                    // Vorschlag übernommen, der an der falschen Stelle ersetzt.
                    setReplace({ from: data.from, to: data.to, input });
                    setActive(-1);
                    setOpen((data.suggestions ?? []).length > 0);
                })
                .catch(() => {
                    // Vorschläge sind Beiwerk. Fällt der Aufruf aus, bleibt das
                    // Feld ein gewöhnliches Textfeld — eine Fehlermeldung dafür
                    // stünde in keinem Verhältnis.
                });
        },
        [suggestHref]
    );

    // Erst nach einer Tippause fragen: wer einen Feldnamen tippt, erzeugt sonst
    // ein halbes Dutzend Abfragen für ein Wort.
    const [pending, setPending] = useState(null);

    useEffect(() => {
        if (pending === null) {
            return undefined;
        }

        const timer = setTimeout(() => load(pending.input, pending.cursor), 150);

        return () => clearTimeout(timer);
    }, [pending, load]);

    const ask = (element) =>
        setPending({ input: element.value, cursor: element.selectionStart ?? 0 });

    const accept = (suggestion) => {
        const input = field.current;

        if (!input || !replace || replace.input !== value) {
            return;
        }

        const chars = [...value];
        const before = chars.slice(0, replace.from).join('');
        const after = chars.slice(replace.to).join('');
        // Hinter einem fertigen Wert ein Leerzeichen, hinter einem Feldnamen
        // nicht: dort geht es mit dem Wert weiter, und ein Leerzeichen wäre der
        // Abbruch des Begriffs.
        const tail = suggestion.value.endsWith(':') ? '' : ' ';
        const next = before + suggestion.value + tail + after;
        const caret = [...(before + suggestion.value + tail)].length;

        onChange(next);
        setOpen(false);

        // Nach dem Zustandswechsel, sonst setzt React den Schreibmarker beim
        // Neuzeichnen wieder ans Ende.
        window.requestAnimationFrame(() => {
            input.focus();
            input.setSelectionRange(caret, caret);
            setPending({ input: next, cursor: caret });
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

        // Die Eingabetaste hat zwei Bedeutungen, und die Auswahl entscheidet:
        // ist ein Vorschlag markiert, übernimmt sie ihn; sonst sucht sie. Ohne
        // die Unterscheidung wäre entweder die Auswahl nicht übernehmbar oder
        // das Absenden nur mit der Maus möglich.
        if ((event.key === 'Enter' || event.key === 'Tab') && active >= 0) {
            event.preventDefault();
            accept(suggestions[active]);
        }
    };

    return (
        <form
            className="relative min-w-64 flex-1"
            onSubmit={(event) => {
                event.preventDefault();
                setOpen(false);
                onSubmit();
            }}
        >
            <InputLabel htmlFor="issue_q" value={t('issues.filter.search')} />

            <TextInput
                id="issue_q"
                ref={field}
                type="text"
                autoComplete="off"
                role="combobox"
                aria-expanded={open}
                aria-controls="issue_q_suggestions"
                aria-autocomplete="list"
                className="mt-1 block w-full"
                value={value}
                placeholder={t('issues.filter.search_placeholder')}
                onChange={(event) => {
                    onChange(event.target.value);
                    ask(event.target);
                }}
                onClick={(event) => ask(event.target)}
                onFocus={(event) => ask(event.target)}
                onKeyDown={onKeyDown}
                onBlur={() => window.setTimeout(() => setOpen(false), 120)}
            />

            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('issues.filter.search_hint')}
            </p>

            {open && suggestions.length > 0 && (
                <ul
                    id="issue_q_suggestions"
                    role="listbox"
                    aria-label={t('issues.filter.search_suggestions')}
                    className="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-800"
                >
                    {suggestions.map((suggestion, at) => (
                        <li key={suggestion.value} role="option" aria-selected={at === active}>
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
                                        ? 'bg-indigo-50 dark:bg-indigo-900/40'
                                        : 'hover:bg-gray-50 dark:hover:bg-gray-700'
                                }`}
                            >
                                <span className="font-mono text-gray-800 dark:text-gray-100">
                                    {suggestion.label}
                                </span>
                                {suggestion.hint && (
                                    <span className="ms-auto truncate text-xs text-gray-500 dark:text-gray-400">
                                        {suggestion.hint}
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </form>
    );
}
