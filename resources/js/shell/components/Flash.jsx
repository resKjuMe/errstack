import React from 'react';

// Flash-Meldungen: Erfolg (status), Fehler (error) und Validierungsfehler
// (errors). Die Werte kommen als Shared-Props von Inertia (Session-Flash bzw.
// $page.props.errors).
export default function Flash({ status, error, errors }) {
    const errorList = errors ? Object.values(errors) : [];

    return (
        <>
            {status && (
                <div className="mb-4 rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">
                    {status}
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
