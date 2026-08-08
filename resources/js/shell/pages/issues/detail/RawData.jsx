import React, { useState } from 'react';
import { Json } from './Sections.jsx';

// Die Rohansicht: die Meldung als JSON.
//
// Sie wird **erst auf Klick geholt**, nicht mit der Seite mitgeliefert. Eine
// Meldung darf hunderte Kilobyte groß sein; sie in jede Antwort zu legen, würde
// die Seite für alle langsamer machen, die nur den Stacktrace lesen wollen — und
// das sind fast alle.
//
// Daneben steht der Weg, sie direkt zu öffnen: ein gewöhnlicher Link auf
// dieselbe Adresse, bewusst kein Inertia-Link. Wer JSON weiterreichen will,
// braucht die Adresse und nicht die Seite drumherum.
export default function RawData({ href, t }) {
    const [state, setState] = useState({ status: 'idle', json: null });

    const toggle = async () => {
        if (state.status === 'ready') {
            setState({ status: 'idle', json: null });

            return;
        }

        setState({ status: 'loading', json: null });

        try {
            const response = await fetch(href, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setState({ status: 'ready', json: await response.json() });
        } catch {
            setState({ status: 'failed', json: null });
        }
    };

    return (
        <div>
            <div className="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    onClick={toggle}
                    aria-expanded={state.status === 'ready'}
                    className="rounded-md border border-gray-300 px-2 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    {state.status === 'ready'
                        ? t('issues.detail.raw.hide')
                        : t('issues.detail.raw.show')}
                </button>

                <a
                    href={href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-xs font-medium text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                >
                    {t('issues.detail.raw.open')}
                </a>
            </div>

            {state.status === 'loading' && (
                <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
                    {t('issues.detail.raw.loading')}
                </p>
            )}

            {state.status === 'failed' && (
                <p className="mt-3 text-sm text-rose-600 dark:text-rose-400">
                    {t('issues.detail.raw.failed')}
                </p>
            )}

            {state.status === 'ready' && (
                <div className="mt-3">
                    <Json value={state.json} />
                </div>
            )}
        </div>
    );
}
