import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { useT } from '../../i18n.js';

// Die Detailseite einer Auslieferung: was in ihr steckt.
//
// Die Liste beantwortet „ist etwas dazugekommen?", diese Seite die Frage
// dahinter — **was** wurde ausgeliefert und von wem. Der Vergleich zur
// Vorversion samt Übersichtszahlen ist R8, die Gesundheit R7; hier steht nur
// der Inhalt dieser einen Auslieferung.
export default function Show({
    release,
    commits,
    commitsLabel,
    commitsTruncated,
    commitsShownLabel,
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
