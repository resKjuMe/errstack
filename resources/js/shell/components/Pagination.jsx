import React from 'react';
import { Link } from '@inertiajs/react';

// Die Seitenzahlen eines Laravel-Paginators.
//
// Die Einträge kommen fertig vom Server (`links`): Ziel, Beschriftung und
// welche Seite die aktuelle ist. Einträge ohne Ziel sind die Auslassungspunkte
// und die Pfeile am jeweiligen Rand — sie werden angezeigt, aber nicht
// verlinkt.
//
// `preserveState`, damit die Seite ihren Zustand über einen Seitenwechsel
// behält (offene Hinweise, Eingaben in Filterfeldern); der Inhalt kommt ohnehin
// vom Server.
export default function Pagination({ links }) {
    // Drei Einträge sind „zurück, 1, weiter" — eine einzige Seite. Dafür braucht
    // niemand eine Blätterleiste.
    if (!links || links.length <= 3) {
        return null;
    }

    return (
        <div className="mt-4 flex flex-wrap gap-1">
            {links.map((link, index) =>
                link.url === null ? (
                    <span
                        key={index}
                        className="px-3 py-1 text-sm text-gray-400 dark:text-gray-600"
                    >
                        {label(link.label)}
                    </span>
                ) : (
                    <Link
                        key={index}
                        href={link.url}
                        preserveState
                        preserveScroll
                        className={`rounded-md px-3 py-1 text-sm ${
                            link.active
                                ? 'bg-indigo-600 text-white'
                                : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700'
                        }`}
                    >
                        {label(link.label)}
                    </Link>
                )
            )}
        </div>
    );
}

// Die Beschriftungen des Paginators enthalten die Pfeile als HTML-Entität. Statt
// sie als HTML einzusetzen, werden die beiden bekannten Zeichen ersetzt — die
// Beschriftung ist Text und soll Text bleiben.
function label(value) {
    return value.replaceAll('&laquo;', '«').replaceAll('&raquo;', '»');
}
