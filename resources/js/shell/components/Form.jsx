import React from 'react';

// Formular-Bausteine: Beschriftung, Eingabefeld, Feldfehler und die drei
// Schaltflächen-Varianten. Absichtlich in einer Datei — es sind Kleinteile, die
// nur gemeinsam ein Formular ergeben, und jede Seite braucht sie im Bündel.
// Aussehen wie die entsprechenden Bausteine in Planstack (Indigo als Akzent der
// Formulare, grauer Rahmen, weiche Schatten).

export function InputLabel({ htmlFor, value, className = '', children }) {
    return (
        <label
            htmlFor={htmlFor}
            className={`block text-sm font-medium text-gray-700 dark:text-gray-300 ${className}`}
        >
            {value ?? children}
        </label>
    );
}

export function TextInput({ className = '', ...props }) {
    return (
        <input
            {...props}
            className={`block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500 ${className}`}
        />
    );
}

export function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={`rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 ${className}`}
        />
    );
}

// Fehler zu einem einzelnen Feld. `message` kommt aus dem `errors`-Objekt von
// useForm; ohne Fehler wird nichts gerendert.
export function InputError({ message, className = '' }) {
    if (!message) {
        return null;
    }

    return <p className={`text-sm text-red-600 dark:text-red-400 ${className}`}>{message}</p>;
}

const buttonBase =
    'inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold disabled:opacity-50';

export function PrimaryButton({ className = '', children, ...props }) {
    return (
        <button
            {...props}
            className={`${buttonBase} bg-indigo-600 text-white hover:bg-indigo-500 ${className}`}
        >
            {children}
        </button>
    );
}

export function SecondaryButton({ className = '', children, ...props }) {
    return (
        <button
            {...props}
            className={`${buttonBase} border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 ${className}`}
        >
            {children}
        </button>
    );
}

export function DangerButton({ className = '', children, ...props }) {
    return (
        <button
            {...props}
            className={`${buttonBase} bg-red-600 text-white hover:bg-red-500 ${className}`}
        >
            {children}
        </button>
    );
}

// Textlink innerhalb eines Formulars (Nebenwege wie „Passwort vergessen?").
export const formLinkClass =
    'text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100';
