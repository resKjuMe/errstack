import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import Pagination from '../../components/Pagination.jsx';
import { InputLabel, SelectInput } from '../../components/Form.jsx';
import useFilter from '../../filters/useFilter.js';
import { useT } from '../../i18n.js';
import HealthValue from './HealthValue.jsx';

// Die Versionsliste: welche Auslieferungen es gab, was mit ihnen dazugekommen
// ist und wie sie ausgegangen sind.
//
// Zwei Fragen stehen nebeneinander, und beide werden nach einer Auslieferung
// gestellt: „ist etwas dazugekommen?" (die neuen Fehler, R1) und „ist etwas
// kaputt?" (die Crash-Free-Rate, R7). Die zweite ist der Grund für die
// Sortierung: unter fünfzig Versionen die schlechteste zu suchen ist keine
// Antwort.
//
// Der Weg von hier aus führt über „neue Fehler" in die Fehlerliste, gefiltert
// auf genau diese Version, und über die Version selbst auf ihre Detailseite mit
// dem Vergleich zur Vorversion.
export default function Index({ releases, sort, sortOptions, totalLabel, environmentPartial }) {
    const { shell } = usePage().props;
    const filter = useFilter();
    const t = useT();

    const showProject = filter.value.projects.length !== 1;

    // Eine geänderte Sortierung ist eine andere Liste — und „Seite 7" darin ist
    // eine andere Seite 7. Deshalb fällt die Seitenzahl weg, wie bei der
    // Filterleiste.
    const go = (patch) => {
        const query = new URLSearchParams(window.location.search);

        Object.entries(patch).forEach(([key, value]) => query.set(key, value));
        query.delete('page');

        router.get(`${window.location.pathname}?${query.toString()}`, {}, { preserveState: true });
    };

    return (
        <>
            <PageHead
                title={t('releases.title')}
                appName={shell.appName}
                help={t('releases.help')}
            />

            {environmentPartial && (
                <p className="mb-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {t('releases.environment_partial')}
                </p>
            )}

            <Card className="mb-4">
                <div className="flex flex-wrap items-end gap-4">
                    <div>
                        <InputLabel htmlFor="release_sort" value={t('releases.sort.label')} />
                        <SelectInput
                            id="release_sort"
                            className="mt-1"
                            value={sort}
                            options={sortOptions}
                            onChange={(e) => go({ sort: e.target.value })}
                        />
                    </div>

                    <p className="ms-auto text-sm text-gray-500 dark:text-gray-400">
                        {t('releases.list.count', { count: totalLabel })}
                    </p>
                </div>
            </Card>

            <div className="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                {releases.data.length === 0 ? (
                    <div className="p-6 text-sm text-gray-500 dark:text-gray-400">
                        <p>{t('releases.list.empty')}</p>
                        <p className="mt-1 text-xs">{t('releases.list.empty_hint')}</p>
                    </div>
                ) : (
                    <>
                        <div className="flex items-center gap-4 border-b border-gray-100 px-4 py-2 text-xs font-medium uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <span className="min-w-0 flex-1">{t('releases.columns.version')}</span>
                            <span
                                className="w-24 text-right"
                                title={t('releases.columns.crash_free_hint')}
                            >
                                {t('releases.columns.crash_free')}
                            </span>
                            <span
                                className="hidden w-24 text-right sm:block"
                                title={t('releases.columns.adoption_hint')}
                            >
                                {t('releases.columns.adoption')}
                            </span>
                            <span className="w-20 text-right">{t('releases.columns.new')}</span>
                            <span className="w-20 text-right">
                                {t('releases.columns.resolved')}
                            </span>
                            <span className="hidden w-44 text-right md:block">
                                {t('releases.columns.last_event')}
                            </span>
                        </div>

                        <ul className="divide-y divide-gray-100 dark:divide-gray-700">
                            {releases.data.map((release) => (
                                <ReleaseRow
                                    key={release.id}
                                    release={release}
                                    showProject={showProject}
                                    t={t}
                                />
                            ))}
                        </ul>
                    </>
                )}
            </div>

            <Pagination links={releases.links} />
        </>
    );
}

function ReleaseRow({ release, showProject, t }) {
    return (
        <li className="flex items-center gap-4 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    {/* Der Weg zum Inhalt der Auslieferung (R2): welche Commits
                        stecken drin und von wem. */}
                    <Link
                        href={release.href}
                        className="truncate font-mono font-medium text-gray-900 underline hover:text-indigo-600 dark:text-gray-100 dark:hover:text-indigo-400"
                    >
                        {release.version}
                    </Link>

                    {/* Eine Angabe ohne Rangfolge — ein Commit-Hash, ein
                        Zählerstand — steht nicht an der Stelle, an der man sie
                        nach der Nummer erwarten würde. Ohne diesen Hinweis sähe
                        die Sortierung kaputt aus. */}
                    {!release.isOrdered && (
                        <span
                            title={t('releases.unordered_hint')}
                            className="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                        >
                            {t('releases.unordered')}
                        </span>
                    )}
                </div>

                <p className="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400">
                    {release.firstEventAtLabel
                        ? t('releases.list.first_event', { value: release.firstEventAtLabel })
                        : t('releases.list.no_events')}

                    {release.releasedAtLabel && (
                        <>
                            <span className="mx-2">·</span>
                            {t('releases.list.released_at', { value: release.releasedAtLabel })}
                        </>
                    )}

                    {showProject && release.project && (
                        <>
                            <span className="mx-2">·</span>
                            <Link
                                href={release.project.href}
                                className="underline hover:text-gray-700 dark:hover:text-gray-200"
                            >
                                {release.project.name}
                            </Link>
                        </>
                    )}

                    {/* Ob zu dieser Version Quellkarten hochgeladen sind (R5). Die
                        Angabe steht hier, weil ihr Fehlen sonst erst auf einer
                        Fehlerseite auffällt — also dann, wenn der Bauvorgang längst
                        vorbei ist. */}
                    <span className="mx-2">·</span>
                    {release.artifacts > 0
                        ? t('releases.list.artifacts', { count: release.artifacts })
                        : t('releases.list.no_artifacts')}
                </p>
            </div>

            {/* Die Gesundheit (R7). Sie steht vor den Fehlerzahlen, weil sie die
                Frage beantwortet, die nach einer Auslieferung zuerst gestellt
                wird — und weil eine Version mit einer eingebrochenen
                Crash-Free-Rate keine Zeile ist, die man überliest. */}
            <div className="w-24 text-right">
                <HealthValue value={release.health?.crashFreeSessions} tone="crash_free" t={t} />
            </div>

            <div className="hidden w-24 text-right sm:block">
                <HealthValue value={release.health?.adoptionSessions} t={t} />
            </div>

            {/* Die neuen Fehler sind die Zahl, wegen der diese Liste besteht —
                deshalb führen sie weiter, und zwar in die Fehlerliste,
                gefiltert auf genau diese Version. */}
            <div className="w-20 text-right font-medium">
                {release.newIssues > 0 ? (
                    <Link
                        href={release.issuesHref}
                        className="text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                    >
                        {release.newIssuesLabel}
                    </Link>
                ) : (
                    <span className="text-gray-900 dark:text-gray-100">
                        {release.newIssuesLabel}
                    </span>
                )}
            </div>

            <div
                className="w-20 text-right font-medium text-gray-900 dark:text-gray-100"
                title={t('releases.columns.resolved_hint')}
            >
                {release.resolvedIssuesLabel}
            </div>

            <div className="hidden w-44 text-right text-sm text-gray-700 md:block dark:text-gray-300">
                {release.lastEventAtLabel ?? '—'}
            </div>
        </li>
    );
}
