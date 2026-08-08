import React, { useEffect, useState } from 'react';
import { SecondaryButton, TextInput } from '../../components/Form.jsx';

// Die Auswahl des Zuständigen: wer sich um diesen Fehler kümmern soll.
//
// **Ein Suchfeld und keine Liste aller Mitglieder.** Die Vorschläge kommen vom
// Server (`suggestHref`) und nicht aus der Seite: eine Organisation mit
// zweihundert Konten in jede Fehlerliste zu schreiben wäre ein Vielfaches der
// Seite selbst — für ein Feld, das die meisten Aufrufe nie anfassen. Dieselbe
// Entscheidung wie beim `@` in den Kommentaren.
//
// **Was hier gewählt wird, ist ein Text.** `me`, eine E-Mail-Adresse oder
// `#Team` — genau die Schreibweise, die auch ins Suchfeld passt. Der Server löst
// sie an einer Stelle auf (App\Support\Issues\IssueAssignee); eine Kennung zu
// schicken wäre der zweite Weg, denselben Zuständigen zu benennen.
export default function AssigneePicker({ suggestHref, current = null, onApply, onCancel, t }) {
    const [term, setTerm] = useState('');
    const [suggestions, setSuggestions] = useState([]);

    // Nachgeladen wird bei jeder Änderung, aber verzögert: eine Abfrage je
    // Tastenanschlag wäre bei einer großen Organisation eine Last ohne Nutzen.
    useEffect(() => {
        const controller = new AbortController();

        const timer = setTimeout(() => {
            const url = new URL(suggestHref, window.location.origin);
            url.searchParams.set('q', term);

            fetch(url, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            })
                .then((response) => (response.ok ? response.json() : { suggestions: [] }))
                .then((data) => setSuggestions(data.suggestions ?? []))
                .catch(() => {
                    /* Abgebrochen oder fehlgeschlagen: das Feld bleibt benutzbar,
                       denn es nimmt auch eine getippte Adresse. */
                });
        }, 200);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [suggestHref, term]);

    return (
        <div className="w-full rounded-md bg-gray-50 p-3 dark:bg-gray-900/50">
            <TextInput
                type="search"
                value={term}
                autoFocus
                onChange={(e) => setTerm(e.target.value)}
                placeholder={t('issues.assignment.search')}
                aria-label={t('issues.assignment.search')}
                className="w-full"
            />

            <ul className="mt-2 max-h-56 overflow-y-auto">
                {/* „Niemand" steht immer zur Wahl und immer oben: die
                    Zuständigkeit aufzuheben ist keine Suche nach einer Person,
                    und wer sie sucht, soll sie nicht erst finden müssen. */}
                <Option
                    label={t('issues.assignment.nobody')}
                    active={current === null}
                    onSelect={() => onApply(null)}
                />

                {suggestions.map((suggestion) => (
                    <Option
                        key={`${suggestion.kind}:${suggestion.value}`}
                        label={suggestion.label}
                        // Der Verdächtige bringt seine Begründung selbst mit
                        // (R4) — ein Vorschlag, der ganz oben steht und nicht
                        // sagt, warum, sieht aus wie eine willkürliche
                        // Sortierung.
                        hint={
                            suggestion.hint ??
                            (suggestion.kind === 'team' ? t('issues.assignment.team') : null)
                        }
                        active={current === suggestion.value}
                        onSelect={() => onApply(suggestion.value)}
                    />
                ))}

                {suggestions.length === 0 && term !== '' && (
                    <li className="px-2 py-1.5 text-sm text-gray-500 dark:text-gray-400">
                        {t('issues.assignment.no_match')}
                    </li>
                )}
            </ul>

            <div className="mt-2">
                <SecondaryButton type="button" onClick={onCancel}>
                    {t('issues.actions.cancel')}
                </SecondaryButton>
            </div>
        </div>
    );
}

function Option({ label, hint = null, active, onSelect }) {
    return (
        <li>
            <button
                type="button"
                onClick={onSelect}
                aria-current={active ? 'true' : undefined}
                className={`flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700 ${
                    active
                        ? 'font-semibold text-indigo-700 dark:text-indigo-300'
                        : 'text-gray-700 dark:text-gray-200'
                }`}
            >
                <span className="truncate">{label}</span>
                {hint && (
                    <span className="shrink-0 rounded bg-gray-200 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                        {hint}
                    </span>
                )}
            </button>
        </li>
    );
}
