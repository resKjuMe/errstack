import React from 'react';
import { KeyValues } from './Sections.jsx';

// Die letzten Schritte vor dem Fehler als Zeitleiste.
//
// Sie steht bewusst nicht als Tabelle da: die Schritte sind eine Abfolge, und
// eine Abfolge liest man an einer Linie entlang. Kategorie und Schweregrad
// stehen als Marke davor, weil beide sagen, ob ein Schritt beiläufig war
// („navigation") oder der Anfang vom Ende („error").
export default function Breadcrumbs({ breadcrumbs, t }) {
    return (
        <ol className="relative space-y-4 border-s border-gray-200 ps-6 dark:border-gray-700">
            {breadcrumbs.map((breadcrumb, index) => (
                <li key={index} className="relative">
                    <span
                        className={`absolute -start-[1.9rem] top-1.5 h-2.5 w-2.5 rounded-full ${dot(
                            breadcrumb.level
                        )}`}
                        aria-hidden="true"
                    />

                    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        {breadcrumb.category && (
                            <span className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                                {breadcrumb.category}
                            </span>
                        )}
                        {breadcrumb.level && (
                            <span
                                className={`text-xs font-semibold ${levelText(breadcrumb.level)}`}
                            >
                                {breadcrumb.level}
                            </span>
                        )}
                        {breadcrumb.timestampLabel && (
                            <span className="ms-auto text-xs whitespace-nowrap text-gray-500 dark:text-gray-400">
                                {breadcrumb.timestampLabel}
                            </span>
                        )}
                    </div>

                    {breadcrumb.message && (
                        <p className="mt-1 text-sm break-words text-gray-800 dark:text-gray-200">
                            {breadcrumb.message}
                        </p>
                    )}

                    {breadcrumb.data && (
                        <details className="mt-1">
                            <summary className="cursor-pointer text-xs text-gray-500 dark:text-gray-400">
                                {t('issues.detail.breadcrumbs.data')}
                            </summary>
                            <div className="mt-1">
                                <KeyValues values={breadcrumb.data} />
                            </div>
                        </details>
                    )}
                </li>
            ))}
        </ol>
    );
}

function dot(level) {
    return (
        {
            fatal: 'bg-rose-600',
            error: 'bg-rose-500',
            warning: 'bg-amber-500',
            info: 'bg-sky-500',
            debug: 'bg-gray-400',
        }[level] ?? 'bg-gray-400'
    );
}

function levelText(level) {
    return (
        {
            fatal: 'text-rose-700 dark:text-rose-300',
            error: 'text-rose-600 dark:text-rose-400',
            warning: 'text-amber-600 dark:text-amber-400',
            info: 'text-sky-600 dark:text-sky-400',
            debug: 'text-gray-500 dark:text-gray-400',
        }[level] ?? 'text-gray-500 dark:text-gray-400'
    );
}
