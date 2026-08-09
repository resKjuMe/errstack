import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import FilterBar from '../../components/FilterBar.jsx';
import { useT } from '../../i18n.js';
import AdoptionChart from './AdoptionChart.jsx';
import HealthValue from './HealthValue.jsx';

// Die Detailseite einer Auslieferung: was in ihr steckt und wie sie ausgegangen
// ist.
//
// Die Liste beantwortet „ist etwas dazugekommen?", diese Seite die beiden
// Fragen dahinter: **was** wurde ausgeliefert und von wem (R2/R3) — und **wie
// ist es gelaufen** (R7). Die Gesundheit steht dabei oben und der Vergleich zur
// Vorversion daneben: „99,2 % absturzfrei" allein sagt niemandem, ob die
// Auslieferung gut war.
//
// Zeitraum und Umgebung kommen aus der Filterleiste (F7) und gelten für jede
// Zahl auf dieser Seite. Die Projektauswahl fehlt in der Leiste: welches Projekt
// gemeint ist, sagt die Version.
export default function Show({
    filter,
    release,
    health,
    comparison,
    previousHref,
    adoption,
    issues,
    deploys,
    commits,
    commitsLabel,
    commitsTruncated,
    commitsShownLabel,
    artifacts,
    artifactsLabel,
    artifactsTruncated,
    artifactsShownLabel,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('releases.detail.title', { version: release.version })}
                appName={shell.appName}
                help={t('releases.detail.help')}
                meta={
                    <Link
                        href={release.indexHref}
                        className="text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        {t('releases.detail.back')}
                    </Link>
                }
            />

            <FilterBar filter={filter} />

            <Card className="mb-4">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                    {release.project.href && (
                        <Link
                            href={release.project.href}
                            className="underline hover:text-gray-700 dark:hover:text-gray-200"
                        >
                            {release.project.name}
                        </Link>
                    )}

                    {release.releasedAtLabel && (
                        <span>
                            {t('releases.detail.released_at', { value: release.releasedAtLabel })}
                        </span>
                    )}

                    {release.firstEventAtLabel && (
                        <span>
                            {t('releases.detail.first_event', { value: release.firstEventAtLabel })}
                        </span>
                    )}

                    {release.lastEventAtLabel && (
                        <span>
                            {t('releases.detail.last_event', { value: release.lastEventAtLabel })}
                        </span>
                    )}

                    {release.ref && (
                        <span className="font-mono">
                            {t('releases.detail.ref', { value: release.ref })}
                        </span>
                    )}

                    <Link
                        href={release.issuesHref}
                        className="text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                    >
                        {t('releases.detail.new_issues')}
                    </Link>

                    {release.url && (
                        <a
                            href={release.url}
                            target="_blank"
                            rel="noreferrer"
                            className="underline hover:text-gray-700 dark:hover:text-gray-200"
                        >
                            {release.url}
                        </a>
                    )}
                </div>
            </Card>

            <Health health={health} comparison={comparison} previousHref={previousHref} t={t} />

            <Card
                className="mb-4"
                title={t('releases.adoption.title')}
                description={t('releases.adoption.help')}
            >
                <AdoptionChart adoption={adoption} t={t} />
            </Card>

            <Issues issues={issues} t={t} />

            <Deploys deploys={deploys} t={t} />

            <Artifacts
                artifacts={artifacts}
                countLabel={artifactsLabel}
                shownLabel={artifactsShownLabel}
                truncated={artifactsTruncated}
                t={t}
            />

            <div className="overflow-hidden rounded-lg bg-white shadow dark:bg-gray-800">
                <div className="border-b border-gray-100 px-4 py-2 text-xs font-medium uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    {t('releases.detail.commit_count', { count: commitsLabel })}

                    {/* Eine gekürzte Liste sähe sonst aus wie die ganze — und
                        wer einen bestimmten Commit sucht, hielte ihn für nicht
                        ausgeliefert. */}
                    {commitsTruncated && (
                        <span className="ml-2 normal-case">
                            {t('releases.detail.truncated', {
                                shown: commitsShownLabel,
                                total: commitsLabel,
                            })}
                        </span>
                    )}
                </div>

                {commits.length === 0 ? (
                    <div className="p-6 text-sm text-gray-500 dark:text-gray-400">
                        <p>{t('releases.detail.empty')}</p>
                        <p className="mt-1 text-xs">{t('releases.detail.empty_hint')}</p>
                    </div>
                ) : (
                    <ul className="divide-y divide-gray-100 dark:divide-gray-700">
                        {commits.map((commit) => (
                            <CommitRow key={commit.id} commit={commit} t={t} />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

// Wie die Auslieferung ausgegangen ist — und ob das besser oder schlechter ist
// als beim letzten Mal.
//
// Die vier Quoten stehen nebeneinander und nicht untereinander: sie beantworten
// dieselbe Frage aus zwei Blickwinkeln (Sitzungen, Menschen) in zwei Hinsichten
// (Stabilität, Verbreitung), und wer eine liest, liest die daneben mit.
function Health({ health, comparison, previousHref, t }) {
    return (
        <Card
            className="mb-4"
            title={t('releases.health.title')}
            description={t('releases.health.help')}
        >
            {!health.hasData && (
                <div className="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    <p>{t('releases.health.empty')}</p>
                    <p className="mt-1 text-xs">{t('releases.health.empty_hint')}</p>
                </div>
            )}

            <dl className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Metric
                    label={t('releases.health.crash_free_sessions')}
                    value={health.crashFreeSessions}
                    delta={comparison?.crashFreeSessions}
                    tone="crash_free"
                    comparison={comparison}
                    t={t}
                />
                <Metric
                    label={t('releases.health.crash_free_users')}
                    value={health.crashFreeUsers}
                    delta={comparison?.crashFreeUsers}
                    tone="crash_free"
                    hint={t('releases.health.no_users')}
                    comparison={comparison}
                    t={t}
                />
                <Metric
                    label={t('releases.health.adoption_sessions')}
                    value={health.adoptionSessions}
                    delta={comparison?.adoptionSessions}
                    comparison={comparison}
                    t={t}
                />
                <Metric
                    label={t('releases.health.adoption_users')}
                    value={health.adoptionUsers}
                    delta={comparison?.adoptionUsers}
                    hint={t('releases.health.no_users')}
                    comparison={comparison}
                    t={t}
                />
            </dl>

            <dl className="mt-4 flex flex-wrap gap-x-6 gap-y-1 border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                <Count label={t('releases.health.sessions')} value={health.sessions} />
                <Count
                    label={t('releases.health.crashed_sessions')}
                    value={health.crashedSessions}
                />
                <Count
                    label={t('releases.health.errored_sessions')}
                    value={health.erroredSessions}
                />
                <Count
                    label={t('releases.health.abnormal_sessions')}
                    value={health.abnormalSessions}
                />
                <Count label={t('releases.health.users')} value={health.users} />
                <Count label={t('releases.health.crashed_users')} value={health.crashedUsers} />
            </dl>

            <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">
                {comparison === null ? (
                    <>
                        {t('releases.comparison.none')}{' '}
                        <span className="text-xs">{t('releases.comparison.none_hint')}</span>
                    </>
                ) : (
                    <>
                        {comparison.hasData
                            ? t('releases.comparison.help')
                            : t('releases.comparison.no_data')}
                        {previousHref && (
                            <>
                                {' '}
                                <Link
                                    href={previousHref}
                                    className="text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    {t('releases.comparison.link', {
                                        version: comparison.version,
                                    })}
                                </Link>
                            </>
                        )}
                    </>
                )}
            </p>
        </Card>
    );
}

// Eine Quote samt Vergleich zur Vorversion.
//
// Der Pfeil trägt die Aussage und die Farbe verstärkt sie nur: wer sie nicht
// unterscheiden kann, liest „↑ +0,30" und weiß dasselbe. Eine Anzeige, in der
// die Richtung ausschließlich in der Farbe steckt, wäre für einen Teil der
// Betrachter gar keine.
function Metric({ label, value, delta, comparison, tone = null, hint = null, t }) {
    return (
        <div>
            <dt
                className="text-xs uppercase text-gray-500 dark:text-gray-400"
                title={hint ?? undefined}
            >
                {label}
            </dt>
            <dd className="mt-1 text-2xl">
                <HealthValue value={value} tone={tone} t={t} />
            </dd>
            <dd className="mt-1 text-xs">
                {delta ? (
                    <span
                        className={deltaClass(delta.direction)}
                        title={t(`releases.comparison.${delta.direction}`, {
                            version: comparison.version,
                        })}
                    >
                        {arrow(delta.direction)}{' '}
                        {t('releases.comparison.points', { value: delta.label })}
                    </span>
                ) : (
                    <span className="text-gray-400 dark:text-gray-500">—</span>
                )}
            </dd>
        </div>
    );
}

function Count({ label, value }) {
    return (
        <div className="flex items-baseline gap-2">
            <dt className="text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="font-medium text-gray-900 dark:text-gray-100">{value.label}</dd>
        </div>
    );
}

function arrow(direction) {
    if (direction === 'up') {
        return '↑';
    }

    return direction === 'down' ? '↓' : '→';
}

function deltaClass(direction) {
    if (direction === 'up') {
        return 'text-emerald-700 dark:text-emerald-400';
    }

    return direction === 'down'
        ? 'text-rose-600 dark:text-rose-400'
        : 'text-gray-500 dark:text-gray-400';
}

// Was diese Auslieferung an Fehlern gebracht, erledigt und zurückgeholt hat.
//
// Jede der drei Zahlen führt in die Fehlerliste, gefiltert auf genau diese
// Menge — eine Zahl auf einer Übersichtsseite, hinter der man nicht nachsehen
// kann, ist eine Behauptung.
function Issues({ issues, t }) {
    const groups = [
        { key: 'new', data: issues.new },
        { key: 'resolved', data: issues.resolved },
        { key: 'regressed', data: issues.regressed },
    ];

    return (
        <Card className="mb-4" title={t('releases.issues.title')}>
            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                {groups.map(({ key, data }) => (
                    <div key={key}>
                        <dt
                            className="text-xs uppercase text-gray-500 dark:text-gray-400"
                            title={t(`releases.issues.${key}_hint`)}
                        >
                            {t(`releases.issues.${key}`)}
                        </dt>
                        <dd className="mt-1 text-2xl font-medium">
                            {data.count > 0 ? (
                                <Link
                                    href={data.href}
                                    className="text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    {data.label}
                                </Link>
                            ) : (
                                <span className="text-gray-900 dark:text-gray-100">
                                    {data.label}
                                </span>
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

// Die hochgeladenen Bundles und Quellkarten (R5).
//
// Sie stehen auf dieser Seite, weil „für diese Version wurde nichts
// hochgeladen" sonst erst dann auffällt, wenn jemand vor einem unlesbaren
// Stacktrace sitzt — und der Bauvorgang längst vorbei ist.
function Artifacts({ artifacts, countLabel, shownLabel, truncated, t }) {
    return (
        <Card
            className="mb-4"
            title={t('releases.artifacts.title')}
            description={t('releases.artifacts.help')}
        >
            {artifacts.length === 0 ? (
                <div className="text-sm text-gray-500 dark:text-gray-400">
                    <p>{t('releases.artifacts.empty')}</p>
                    <p className="mt-1 text-xs">{t('releases.artifacts.empty_hint')}</p>
                </div>
            ) : (
                <>
                    <p className="text-xs uppercase text-gray-500 dark:text-gray-400">
                        {t('releases.artifacts.count', { count: countLabel })}

                        {/* Wie bei den Commits: eine stillschweigend gekürzte
                            Liste sähe aus wie die ganze. */}
                        {truncated && (
                            <span className="ml-2 normal-case">
                                {t('releases.artifacts.truncated', {
                                    shown: shownLabel,
                                    total: countLabel,
                                })}
                            </span>
                        )}
                    </p>

                    <ul className="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                        {artifacts.map((artifact) => (
                            <li
                                key={artifact.id}
                                className="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2 first:pt-0 last:pb-0"
                            >
                                <span className="min-w-0 flex-1 truncate font-mono text-gray-900 dark:text-gray-100">
                                    {artifact.name}
                                </span>

                                <span className="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {artifact.kindLabel}
                                </span>

                                {artifact.hasDebugId && (
                                    <span
                                        title={t('releases.artifacts.debug_id_hint')}
                                        className="rounded bg-sky-50 px-2 py-0.5 text-xs text-sky-700 dark:bg-sky-900/40 dark:text-sky-300"
                                    >
                                        {t('releases.artifacts.debug_id')}
                                    </span>
                                )}

                                {artifact.sourceMap && (
                                    <span className="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {t('releases.artifacts.source_map', {
                                            value: artifact.sourceMap,
                                        })}
                                    </span>
                                )}

                                <span className="text-gray-500 dark:text-gray-400">
                                    {artifact.sizeLabel}
                                </span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Card>
    );
}

// Wann diese Version wohin ausgeliefert wurde.
//
// Über den Commits und nicht darunter: „seit wann ist das draußen?" ist die
// Frage, mit der jemand hierherkommt, und „was steckt drin?" die, die danach
// kommt. Eine Zeile je Auslieferung, neueste zuerst — dieselbe Version geht
// nacheinander nach staging und nach production, und nach einem Rollback ein
// zweites Mal.
function Deploys({ deploys, t }) {
    return (
        <Card className="mb-4" title={t('releases.deploys.title')}>
            {deploys.length === 0 ? (
                <div className="text-sm text-gray-500 dark:text-gray-400">
                    <p>{t('releases.deploys.empty')}</p>
                    <p className="mt-1 text-xs">{t('releases.deploys.empty_hint')}</p>
                </div>
            ) : (
                <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    {deploys.map((deploy) => (
                        <li
                            key={deploy.id}
                            className="flex flex-wrap items-baseline gap-x-3 gap-y-1 py-2 first:pt-0 last:pb-0"
                        >
                            <span className="rounded bg-sky-50 px-2 py-0.5 font-mono text-xs text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">
                                {deploy.environment}
                            </span>

                            <span className="text-gray-900 dark:text-gray-100">
                                {deploy.atLabel}
                            </span>

                            {deploy.label !== deploy.environment && (
                                <span className="text-gray-500 dark:text-gray-400">
                                    {deploy.label}
                                </span>
                            )}

                            {deploy.url && (
                                <a
                                    href={deploy.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    {t('releases.deploys.link')}
                                </a>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function CommitRow({ commit, t }) {
    return (
        <li className="px-4 py-3">
            <div className="flex items-baseline gap-2">
                {/* Der kurze Hash führt zum Commit beim Anbieter — sofern eine
                    Adresse hinterlegt ist. Ohne sie bleibt er Text: ein Link,
                    der ins Leere führt, wäre schlechter als keiner. */}
                {commit.href ? (
                    <a
                        href={commit.href}
                        target="_blank"
                        rel="noreferrer"
                        className="shrink-0 font-mono text-sm text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                    >
                        {commit.shortSha}
                    </a>
                ) : (
                    <span
                        title={commit.sha}
                        className="shrink-0 font-mono text-sm text-gray-500 dark:text-gray-400"
                    >
                        {commit.shortSha}
                    </span>
                )}

                {/* Eine Übergabe darf die Nachricht weglassen — dann bleibt der
                    Hash die ganze Auskunft, und der steht bereits daneben. */}
                <p className="min-w-0 flex-1 truncate font-medium text-gray-900 dark:text-gray-100">
                    {commit.title || '—'}
                </p>
            </div>

            <p className="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                <Author author={commit.author} t={t} />

                {commit.repository && (
                    <>
                        <span className="mx-2">·</span>
                        <span className="font-mono">{commit.repository.name}</span>
                    </>
                )}

                {commit.committedAtLabel && (
                    <>
                        <span className="mx-2">·</span>
                        {commit.committedAtLabel}
                    </>
                )}
            </p>

            {commit.body && (
                <p className="mt-2 whitespace-pre-wrap text-sm text-gray-600 dark:text-gray-300">
                    {commit.body}
                </p>
            )}

            <Files files={commit.files} t={t} />
        </li>
    );
}

// Der Autor: der Name aus dem Konto, wenn sich die Adresse zuordnen ließ, sonst
// der aus dem Repository. Der Unterschied ist sichtbar — sonst sähe eine
// beliebige Git-Einstellung eines fremden Rechners aus wie ein Mitglied.
function Author({ author, t }) {
    if (!author.name && !author.email) {
        return <span>{t('releases.detail.author_unknown')}</span>;
    }

    return (
        <span
            title={author.isMember ? t('releases.detail.author_member_hint') : (author.email ?? '')}
            className={author.isMember ? 'text-gray-700 dark:text-gray-200' : undefined}
        >
            {author.name ?? author.email}
        </span>
    );
}

function Files({ files, t }) {
    if (files.length === 0) {
        return (
            <p className="mt-2 text-xs text-gray-400 dark:text-gray-500">
                {t('releases.detail.no_files')}
            </p>
        );
    }

    return (
        <ul className="mt-2 space-y-0.5">
            {files.map((file) => (
                <li key={file.id} className="flex items-baseline gap-2 text-xs">
                    <span
                        title={file.changeLabel}
                        className={`w-4 shrink-0 text-center font-mono font-medium ${changeClass(file.change)}`}
                    >
                        {file.change}
                    </span>
                    <span className="min-w-0 truncate font-mono text-gray-600 dark:text-gray-300">
                        {file.path}
                    </span>
                </li>
            ))}
        </ul>
    );
}

function changeClass(change) {
    if (change === 'A') {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (change === 'D') {
        return 'text-rose-600 dark:text-rose-400';
    }

    return 'text-amber-600 dark:text-amber-400';
}
