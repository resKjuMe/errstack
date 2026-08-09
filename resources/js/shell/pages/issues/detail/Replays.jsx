import React from 'react';
import { Link } from '@inertiajs/react';

// Die Sitzungs-Aufzeichnungen zu dieser Meldung (M3).
//
// Der kurze Weg vom Fehler zum Film. Bewusst nur eine Handvoll Angaben je Zeile:
// wer hier ist, hat den Stacktrace vor sich und will wissen, ob es etwas zu
// sehen gibt — nicht, in welchem Browser die Sitzung lief. Alles Weitere steht
// auf der Abspielseite.
export default function Replays({ replays, t }) {
    return (
        <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-700">
            {replays.map((replay) => (
                <li key={replay.id} className="flex flex-wrap items-center gap-3 py-2">
                    <Link
                        href={replay.href}
                        className="font-medium text-rose-600 hover:underline dark:text-rose-400"
                    >
                        {t('replays.issue.open')}
                    </Link>

                    <span className="text-xs text-gray-600 dark:text-gray-300">
                        {replay.user?.label ?? t('replays.list.anonymous')}
                    </span>

                    {replay.url && (
                        <span
                            className="font-mono text-xs break-all text-gray-500 dark:text-gray-400"
                            title={replay.url}
                        >
                            {replay.url}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}
