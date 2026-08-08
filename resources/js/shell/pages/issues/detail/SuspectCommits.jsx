import React from 'react';

// Welche Änderung diesen Fehler verursacht haben könnte (R4).
//
// **Die Begründung steht neben jedem Eintrag und nicht in einer Fußnote.** Eine
// Liste, die drei Commits „verdächtig" nennt, ohne zu sagen warum, ist ein
// Orakelspruch — wer ihr nicht glaubt, kann sie nicht prüfen und sieht am Ende
// doch wieder selbst ins Git-Log. Mit `app/Kernel.php, Zeile 42 wurde geändert`
// daneben ist die Behauptung in zehn Sekunden bestätigt oder verworfen.
//
// Zwei Stärken von Verdacht werden unterschieden: der Commit hat **die Zeile**
// angefasst, an der es knallt, oder nur **die Datei**. Das ist der Unterschied
// zwischen einem Hinweis und einer Vermutung, und er gehört sichtbar gemacht.
export default function SuspectCommits({ suspects, t }) {
    return (
        <ul className="divide-y divide-gray-200 dark:divide-gray-700">
            {suspects.map((suspect) => (
                <li key={suspect.id} className="py-3 first:pt-0 last:pb-0">
                    <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <Sha suspect={suspect} />

                        <span className="min-w-0 flex-1 truncate text-sm text-gray-900 dark:text-gray-100">
                            {suspect.title || '—'}
                        </span>

                        {suspect.reason.matchedLine && (
                            <span className="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                {t('issues.suspects.line_badge')}
                            </span>
                        )}
                    </div>

                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {reason(suspect, t)}
                    </p>

                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {suspect.author.name
                            ? t('issues.suspects.author', { name: suspect.author.name })
                            : t('issues.suspects.author_unknown')}
                        {suspect.author.name && !suspect.author.isMember && (
                            <span className="ms-1">{t('issues.suspects.author_no_account')}</span>
                        )}
                        {suspect.committedAtLabel && (
                            <span className="ms-2">{suspect.committedAtLabel}</span>
                        )}
                        {suspect.repository && (
                            <span className="ms-2">{suspect.repository.name}</span>
                        )}
                    </p>
                </li>
            ))}
        </ul>
    );
}

// Der Hash ist nur dann ein Link, wenn das Repository eine Adresse hat — ohne
// sie bliebe eine Schaltfläche übrig, die ins Leere führt.
function Sha({ suspect }) {
    const className = 'shrink-0 font-mono text-xs';

    return suspect.href ? (
        <a
            href={suspect.href}
            target="_blank"
            rel="noreferrer"
            title={suspect.sha}
            className={`${className} text-indigo-600 hover:underline dark:text-indigo-400`}
        >
            {suspect.shortSha}
        </a>
    ) : (
        <span title={suspect.sha} className={`${className} text-gray-500 dark:text-gray-400`}>
            {suspect.shortSha}
        </span>
    );
}

function reason({ reason: why }, t) {
    if (why.matchedLine && why.line !== null) {
        return t('issues.suspects.reason_line', { path: why.path, line: why.line });
    }

    return t('issues.suspects.reason_file', { path: why.path });
}
