import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { QuestionIcon } from '../icons.jsx';

// Einheitlicher Seitenkopf: H1 links, optional Meta-Inhalt und ein „?"-Button
// rechts, dessen Infobox darunter aufklappt. Setzt zugleich den Titel des
// Browser-Tabs („<Titel> · <App>"), sofern nicht per documentTitle abgeschaltet.
export default function PageHead({ title, documentTitle = true, help = null, meta = null, appName = null }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="mb-6">
            {documentTitle && <Head title={appName ? `${title} · ${appName}` : title} />}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">{title}</h1>
                <div className="flex items-center gap-3">
                    {meta}
                    {help && (
                        <button
                            type="button"
                            onClick={() => setOpen((v) => !v)}
                            aria-expanded={open}
                            title="Hilfe anzeigen"
                            className="text-gray-400 hover:text-rose-600 dark:text-gray-500 dark:hover:text-rose-400"
                        >
                            <QuestionIcon className="h-5 w-5" />
                        </button>
                    )}
                </div>
            </div>

            {help && open && (
                <div className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm leading-relaxed text-gray-600 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-400">
                    {help}
                </div>
            )}
        </div>
    );
}
