import React from 'react';
import { Link } from '@inertiajs/react';
import { Checkbox } from '../../components/Form.jsx';
import Sparkline from './Sparkline.jsx';

// Eine Zeile der Fehlerliste. Die Spaltenbreiten stimmen mit der Kopfzeile in
// Index.jsx überein — sie stehen an beiden Stellen, weil dies eine Liste aus
// Flex-Zeilen ist und keine Tabelle: eine echte Tabelle bekäme die Zeile auf
// schmalen Geräten nicht umgebrochen.
//
// Die Zahlen kommen fertig geschrieben vom Server (`timesSeenLabel`), die rohen
// Werte daneben sind für die Grafik da. Formatiert wird nicht zweimal — wie eine
// Zahl aussieht, hängt an der Sprache, und die kennt der Server.
export default function IssueRow({
    issue,
    selected,
    onToggle,
    showProject,
    trendLabel,
    deploys,
    t,
}) {
    return (
        <li className="flex items-center gap-4 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
            <Checkbox
                checked={selected}
                onChange={() => onToggle(issue.id)}
                aria-label={`${t('issues.selection.row')}: ${issue.title}`}
            />

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <LevelBadge level={issue.level} label={issue.levelLabel} />
                    <Link
                        href={issue.href}
                        className="truncate font-medium text-gray-900 hover:underline dark:text-gray-100"
                    >
                        {issue.title}
                    </Link>

                    {/* Von Hand zusammengeführt (S9): die Marke erklärt die
                        Zahlen dieser Zeile — sie gelten für mehrere
                        Fingerabdrücke. */}
                    {issue.mergedCount > 0 && (
                        <span
                            title={t('issues.merge.badge.hint')}
                            className="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                        >
                            {t('issues.merge.badge.label', { count: issue.mergedCount + 1 })}
                        </span>
                    )}
                </div>

                <p className="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400">
                    {issue.culprit}
                    {showProject && issue.project && (
                        <>
                            {issue.culprit && <span className="mx-2">·</span>}
                            <Link
                                href={issue.project.href}
                                className="underline hover:text-gray-700 dark:hover:text-gray-200"
                            >
                                {issue.project.name}
                            </Link>
                        </>
                    )}
                    {/* Der Weg zu den Merkmalen: welche Browser, Fassungen und
                        Server diesen Fehler betreffen. Er steht an jeder Zeile,
                        weil die Frage „trifft das alle oder nur einen?" an jedem
                        Fehler dieselbe ist. */}
                    <span className="mx-2">·</span>
                    <Link
                        href={issue.tagsHref}
                        className="underline hover:text-gray-700 dark:hover:text-gray-200"
                    >
                        {t('tags.link.issue')}
                    </Link>
                </p>

                <ReleaseTrail issue={issue} t={t} />
            </div>

            <div className="hidden w-28 sm:block">
                <Sparkline values={issue.series} label={trendLabel} markers={deploys} />
            </div>

            <div className="w-16 text-right font-medium text-gray-900 dark:text-gray-100">
                {issue.timesSeenLabel}
            </div>

            <div className="w-16 text-right font-medium text-gray-900 dark:text-gray-100">
                {issue.usersSeenLabel}
            </div>

            <div className="hidden w-44 text-right text-sm md:block">
                <p className="text-gray-700 dark:text-gray-300">{issue.lastSeenLabel}</p>
                <p className="text-xs text-gray-500 dark:text-gray-400">
                    {t('issues.list.first_seen', { value: issue.firstSeenLabel })}
                </p>
            </div>
        </li>
    );
}

// Die betroffenen Versionen: „zuerst gesehen in" und „zuletzt aufgetreten in".
//
// Sie stehen unter der Fehlerstelle und nicht in einer eigenen Spalte, weil es
// sie oft gar nicht gibt: ein SDK ohne `release`-Angabe liefert nichts davon,
// und eine leere Spalte in jeder Zeile wäre der Preis für eine Auskunft, die
// nur manche Projekte haben.
//
// Ist beides dieselbe Version, steht sie einmal da — „zuerst 1.0.0, zuletzt
// 1.0.0" sagt nichts, was „in 1.0.0" nicht schon sagt.
function ReleaseTrail({ issue, t }) {
    if (!issue.firstRelease && !issue.lastRelease) {
        return null;
    }

    const same = issue.firstRelease === issue.lastRelease;

    return (
        <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
            {same ? (
                <ReleaseTag label={t('issues.release.only')} version={issue.firstRelease} />
            ) : (
                <>
                    {issue.firstRelease && (
                        <ReleaseTag
                            label={t('issues.release.first')}
                            version={issue.firstRelease}
                        />
                    )}
                    {issue.lastRelease && (
                        <ReleaseTag label={t('issues.release.last')} version={issue.lastRelease} />
                    )}
                </>
            )}
        </p>
    );
}

function ReleaseTag({ label, version }) {
    return (
        <span>
            {label}{' '}
            <span className="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                {version}
            </span>
        </span>
    );
}

// Der Schweregrad als farbige Marke: er unterscheidet in der Liste die Meldung,
// die jemanden wecken soll, von der, die nur mitläuft.
function LevelBadge({ level, label }) {
    const tone =
        {
            fatal: 'bg-rose-600 text-white',
            error: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
            warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
            info: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
            debug: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        }[level] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';

    return (
        <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold ${tone}`}>
            {label}
        </span>
    );
}
