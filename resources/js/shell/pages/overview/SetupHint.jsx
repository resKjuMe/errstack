import React from 'react';
import { Link } from '@inertiajs/react';

// Der Einrichtungs-Hinweis: „von hier liegt noch nichts vor" statt einer
// Nulllinie.
//
// Er nennt die Projekte mit Namen und Weg. „Ein Projekt wartet noch" ohne zu
// sagen, welches, wäre auf einer Übersicht mit zwölf Projekten keine Hilfe,
// sondern eine Suchaufgabe.
export default function SetupHint({ setup, t, className = '' }) {
    return (
        <div
            className={`rounded-md bg-amber-50 p-3 text-sm dark:bg-amber-900/20 ${className}`}
            role="status"
        >
            <p className="font-medium text-amber-900 dark:text-amber-200">
                {t('overview.setup.title')}
            </p>
            <p className="mt-1 text-xs text-amber-800 dark:text-amber-300">
                {t('overview.setup.description')}
            </p>

            <ul className="mt-2 flex flex-wrap gap-x-3 gap-y-1">
                {setup.projects.map((project) => (
                    <li key={project.slug}>
                        <Link
                            href={project.href}
                            className="text-xs font-medium text-amber-900 underline hover:no-underline dark:text-amber-200"
                        >
                            {project.name} · {t('overview.setup.action')}
                        </Link>
                    </li>
                ))}
            </ul>
        </div>
    );
}
