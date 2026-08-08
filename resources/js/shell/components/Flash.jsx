import React from 'react';
import { router } from '@inertiajs/react';

// Flash-Meldungen: Erfolg (status), Fehler (error) und Validierungsfehler
// (errors). Die Werte kommen als Shared-Props von Inertia (Session-Flash bzw.
// $page.props.errors).
//
// Benutzt eine Seite Fehler-Bags (mehrere Formulare, s. Profil), liefert Inertia
// `errors` nach Bag verschachtelt — deshalb eine Ebene auflösen, statt ein Objekt
// als Meldung zu rendern.
function messagesOf(errors) {
    return Object.values(errors ?? {}).flatMap((value) =>
        typeof value === 'string' ? [value] : Object.values(value ?? {})
    );
}

export default function Flash({ status, error, errors, undo, undoHref }) {
    const errorList = messagesOf(errors);

    return (
        <>
            {status && (
                <div className="mb-4 flex flex-wrap items-center gap-3 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">
                    <span>{status}</span>

                    {/* Der Rückweg zur letzten Aktion (S6). Die Seite kennt nur
                        eine Kennmarke; was dahintersteht, weiß allein der
                        Server — sonst könnte man beim Klick bestimmen, was
                        zurückgenommen wird. */}
                    {undo && undoHref && (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(
                                    undoHref,
                                    { token: undo.token },
                                    { preserveScroll: true }
                                )
                            }
                            className="font-semibold underline hover:text-green-900 dark:hover:text-green-100"
                        >
                            {undo.label}
                        </button>
                    )}
                </div>
            )}

            {error && (
                <div className="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                    {error}
                </div>
            )}

            {errorList.length > 0 && (
                <div className="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                    <ul className="list-disc space-y-1 ps-5">
                        {errorList.map((msg, i) => (
                            <li key={i}>{msg}</li>
                        ))}
                    </ul>
                </div>
            )}
        </>
    );
}
