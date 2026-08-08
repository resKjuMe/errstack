import React from 'react';
import { usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import FilterBar from '../../components/FilterBar.jsx';
import { useT } from '../../i18n.js';
import { TagDetail, TagFacets, TagsEmpty } from './TagBreakdown.jsx';

// Die Merkmale der gewählten Projekte — welche Browser, Betriebssysteme,
// Fassungen und Server überhaupt vorkommen.
//
// Eine Seite für beide Tiefen: ohne `detail` die Übersicht über alle Merkmale,
// mit `detail` alle Werte eines einzelnen. Zwei Seiten wären derselbe Rahmen
// zweimal — der Unterschied ist eine Liste, nicht ein Bildschirm.
export default function Index({ filter, facets, detail, overviewHref, valueLimit }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={detail ? detail.label : t('tags.project.title')}
                appName={shell.appName}
                help={t('tags.help')}
            />

            <FilterBar filter={filter} />

            {/* Der Zeitraum steht in der Leiste und wirkt hier nicht — das wird
                gesagt und nicht stillschweigend übergangen. */}
            <p className="mb-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                {t('tags.period_ignored')}
            </p>

            {detail ? (
                <TagDetail
                    detail={detail}
                    backHref={overviewHref}
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
