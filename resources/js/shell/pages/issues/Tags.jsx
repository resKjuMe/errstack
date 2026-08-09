import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import { useT } from '../../i18n.js';
import { TagDetail, TagFacets, TagsEmpty } from '../tags/TagBreakdown.jsx';

// Die Merkmale **eines** Fehlers: welche Browser, Fassungen und Server ihn
// betreffen — mit Anteil, denn erst der macht aus einer Zahl eine Aussage.
//
// Dieselbe Darstellung wie auf der Projekt-Ebene (../tags/TagBreakdown.jsx);
// verschieden ist nur der Kopf und worüber gezählt wurde.
export default function Tags({ issue, facets, detail, issuesHref, valueLimit }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={detail ? detail.label : t('tags.issue.title')}
                appName={shell.appName}
                help={t('tags.help')}
                meta={
                    <span className="text-sm text-gray-500 dark:text-gray-400">
                        {t('tags.issue.times_seen', { count: issue.timesSeenLabel })}
                    </span>
                }
            />

            <div className="mb-4">
                <p className="truncate text-lg font-medium text-gray-900 dark:text-gray-100">
                    {issue.title}
                </p>
                {issue.culprit && (
                    <p className="truncate text-sm text-gray-500 dark:text-gray-400">
                        {issue.culprit}
                    </p>
                )}
                <Link
                    href={issuesHref}
                    className="mt-1 inline-block text-sm font-medium text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                >
                    {t('tags.issue.back')}
                </Link>
            </div>

            <p className="mb-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                {t('tags.period_ignored')}
            </p>

            {detail ? (
                <TagDetail
                    detail={detail}
                    backHref={issue.href}
                    backLabel={t('tags.detail.back')}
                    capLimit={valueLimit}
                    t={t}
                />
            ) : facets.length === 0 ? (
                <TagsEmpty t={t} />
            ) : (
                <TagFacets facets={facets} t={t} />
            )}
        </>
    );
}
