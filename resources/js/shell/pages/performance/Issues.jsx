import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import Pagination from '../../components/Pagination.jsx';
import { InputLabel, SelectInput } from '../../components/Form.jsx';
import useFilter from '../../filters/useFilter.js';
import { useTranslations } from '../../i18n.js';
import { duration } from './format.jsx';
import Sparkline from '../issues/Sparkline.jsx';

// Die Leistungsprobleme: was die Erkennung in gespeicherten Abläufen gefunden
// hat, geordnet nach dem, was es kostet.
//
// **Getrennt von der Fehlerliste**, und die auffälligste Folge davon ist die
// erste Spalte rechts: dort steht die verlorene Zeit, und danach wird auch
// vorsortiert. Bei einem Fehler ist die erste Frage „wann war das zuletzt", bei
// einem Leistungsproblem „was bringt es, das zu beheben" — dieselbe Liste für
// beides hätte in jeder Zeile Spalten, die für die Hälfte der Einträge nichts
// aussagen.
//
// Der Zustand steht wie überall vollständig in der Adresszeile.
export default function Issues({
    issues,
    list,
    sortOptions,
    statusOptions,
    problemOptions,
    series,
    environmentIgnored,
    totalLabel,
}) {
    const { shell } = usePage().props;
    const filter = useFilter();
    const { t, formats } = useTranslations();

    // Sortierung, Zustand und Muster sind Felder dieser Seite; die übrigen
    // Parameter der Adresszeile bleiben stehen. Eine neue Auswahl beginnt auf
    // Seite 1 — „Seite 7" einer anderen Reihenfolge ist eine andere Seite 7.
    const go = (patch) => {
        const query = new URLSearchParams(window.location.search);

        Object.entries(patch).forEach(([key, value]) => query.set(key, value));
        query.delete('page');

        router.get(`${window.location.pathname}?${query.toString()}`, {}, { preserveState: true });
    };

    const showProject = filter.value.projects.length !== 1;
    const trendLabel = t('issues.trend.label', { period: series.periodLabel });
    const deploys = series.markers ?? [];

    return (
        <>
            <PageHead
                title={t('performance_issues.title')}
                appName={shell.appName}
                help={t('performance_issues.help')}
            />

            {environmentIgnored && (
                <p className="mb-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {t('performance_issues.filter.environment_ignored')}
                </p>
            )}

            <Card className="mb-4">
                <div className="flex flex-wrap items-end gap-4">
                    <div>
                        <InputLabel
                            htmlFor="performance_issue_sort"
                            value={t('performance_issues.filter.sort')}
                        />
                        <SelectInput
                            id="performance_issue_sort"
                            className="mt-1"
                            value={list.sort}
                            options={sortOptions}
                            onChange={(e) => go({ sort: e.target.value })}
                        />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="performance_issue_status"
                            value={t('performance_issues.filter.status')}
                        />
                        <SelectInput
                            id="performance_issue_status"
                            className="mt-1"
                            value={list.status}
                            options={statusOptions}
                            onChange={(e) => go({ status: e.target.value })}
                        />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="performance_issue_problem"
                            value={t('performance_issues.filter.problem')}
                        />
                        <SelectInput
                            id="performance_issue_problem"
                            className="mt-1"
                            value={list.problem}
                            options={problemOptions}
                            onChange={(e) => go({ problem: e.target.value })}
                        />
                    </div>

                    <div className="ms-auto text-sm text-gray-500 dark:text-gray-400">
                        {t('performance_issues.list.count', { count: totalLabel })}
                    </div>
                </div>
            </Card>

            <div className="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                {issues.data.length === 0 ? (
                    <div className="p-6 text-sm text-gray-500 dark:text-gray-400">
                        <p>{t('performance_issues.list.empty')}</p>
                        <p className="mt-1 text-xs">{t('performance_issues.list.empty_hint')}</p>
                    </div>
                ) : (
                    <>
                        <div className="flex items-center gap-4 border-b border-gray-100 bg-gray-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                            <div className="min-w-0 flex-1">
                                {t('performance_issues.columns.problem')}
                            </div>
                            <div className="hidden w-28 sm:block">
                                {t('performance_issues.columns.trend')}
                            </div>
                            <div className="w-28 text-right">
                                {t('performance_issues.columns.time_lost')}
                            </div>
                            <div className="w-16 text-right">
                                {t('performance_issues.columns.events')}
                            </div>
                            <div className="w-16 text-right">
                                {t('performance_issues.columns.users')}
                            </div>
                            <div className="hidden w-44 text-right md:block">
                                {t('performance_issues.columns.last_seen')}
                            </div>
                        </div>

                        <ul className="divide-y divide-gray-100 dark:divide-gray-700">
                            {issues.data.map((issue) => (
                                <Row
                                    key={issue.id}
                                    issue={issue}
                                    showProject={showProject}
                                    trendLabel={trendLabel}
                                    deploys={deploys}
                                    t={t}
                                    formats={formats}
                                />
                            ))}
                        </ul>
                    </>
                )}
            </div>

            <Pagination links={issues.links} />
        </>
    );
}

// Eine Zeile. Die Spaltenbreiten stimmen mit der Kopfzeile überein — sie stehen
// an beiden Stellen, weil dies eine Liste aus Flex-Zeilen ist und keine Tabelle:
// eine echte Tabelle bekäme die Zeile auf schmalen Geräten nicht umgebrochen.
function Row({ issue, showProject, trendLabel, deploys, t, formats }) {
    return (
        <li className="flex items-center gap-4 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <ProblemBadge label={issue.problemLabel} />
                    <Link
                        href={issue.href}
                        className="truncate font-medium text-gray-900 hover:underline dark:text-gray-100"
                    >
                        {issue.title}
                    </Link>
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
                </p>
            </div>

            <div className="hidden w-28 sm:block">
                <Sparkline values={issue.series} label={trendLabel} markers={deploys} />
            </div>

            {/* Die Summe groß, der Mittelwert je Vorfall klein darunter: erst
                beide zusammen sagen, ob die Zeit aus vielen kleinen Verlusten
                stammt oder aus wenigen großen — und davon hängt ab, ob sich das
                Beheben lohnt. */}
            <div className="w-28 text-right">
                <p className="font-medium text-gray-900 dark:text-gray-100">
                    {duration(issue.timeLostUs, t, formats)}
                </p>
                {issue.timeLostPerEventUs !== null && (
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {t('performance_issues.list.per_event', {
                            value: durationText(issue.timeLostPerEventUs, t, formats),
                        })}
                    </p>
                )}
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
                    {t('performance_issues.list.first_seen', { value: issue.firstSeenLabel })}
                </p>
            </div>
        </li>
    );
}

// Das Muster als Marke — sie unterscheidet auf einen Blick eine langsame
// Abfrage von einer blockierenden Ressource, ohne dass man die Überschrift
// lesen muss.
export function ProblemBadge({ label }) {
    if (!label) {
        return null;
    }

    return (
        <span className="shrink-0 rounded bg-violet-100 px-1.5 py-0.5 text-xs font-semibold text-violet-800 dark:bg-violet-900/40 dark:text-violet-200">
            {label}
        </span>
    );
}

// Dieselbe Dauer als reiner Text: sie steckt hier in einem übersetzten Satz
// („120 ms je Vorfall") und kann deshalb kein Element sein.
function durationText(microseconds, t, formats) {
    const value = duration(microseconds, t, formats);

    return typeof value === 'string' ? value : '';
}
