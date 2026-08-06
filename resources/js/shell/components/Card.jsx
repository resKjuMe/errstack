import React from 'react';

// Weiße Karte auf grauem Grund — der Grundbaustein für Seiteninhalte.
// `title` und `description` sind optional; ohne sie ist die Karte ein reiner
// Rahmen um children.
export default function Card({ title = null, description = null, className = '', children }) {
    return (
        <div className={`rounded-lg bg-white p-6 shadow dark:bg-gray-800 ${className}`}>
            {title && (
                <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                    {title}
                </h2>
            )}
            {description && (
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{description}</p>
            )}
            {children && <div className={title || description ? 'mt-4' : ''}>{children}</div>}
        </div>
    );
}
