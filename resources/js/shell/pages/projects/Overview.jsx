import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import PanelCard from '../overview/PanelCard.jsx';
import { useT } from '../../i18n.js';

// Die Übersicht eines Projekts: Verlauf, was zuletzt kaputtging, wie die letzte
// Auslieferung läuft — und wer zuständig ist.
//
// **Sie ist nicht die Einstellungsseite des Projekts.** Dort wird eingerichtet,
// hier wird nachgesehen. Die Wege dorthin stehen oben, damit niemand danach
// sucht.
export default function Overview({ project, scope, panels }) {
    const { shell } = usePage().props;
    const t = useT();
    const panel = (key) => panels.find((entry) => entry.key === key)?.href;

    return (
        <>
            <PageHead
                title={t('overview.project.title', { name: project.name })}
                appName={shell.appName}
                help={<p>{t('overview.project.description')}</p>}
            />

            <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                <Link
                    href={project.issuesHref}
                    className="font-medium text-rose-600 hover:text-rose-500 dark:text-rose-400"
                >
                    {t('overview.project.issues_link')}
                </Link>
                <Link
                    href={project.alertsHref}
                    className="font-medium text-rose-600 hover:text-rose-500 dark:text-rose-400"
                >
                    {t('overview.project.alerts')}
                </Link>
                <Link
                    href={project.settingsHref}
                    className="font-medium text-rose-600 hover:text-rose-500 dark:text-rose-400"
                >
                    {t('overview.project.settings')}
                </Link>
                <span className="text-gray-500 dark:text-gray-400">{project.platformLabel}</span>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <PanelCard
                    href={panel('errors')}
                    title={t('overview.project.errors.title')}
                    description={t('overview.project.errors.description')}
                    className="lg:col-span-2"
                />
                <PanelCard
                    href={panel('issues')}
                    title={t('overview.project.issues_panel.title')}
                    description={t('overview.project.issues_panel.description')}
                />
                <PanelCard
                    href={panel('releases')}
                    title={t('overview.project.releases.title')}
                    description={t('overview.project.releases.description')}
                />
                <PanelCard
                    href={panel('ownership')}
                    title={t('overview.project.ownership.title')}
                    description={t('overview.project.ownership.description')}
                    emptyText={t('overview.project.ownership.empty')}
                    className="lg:col-span-2"
                />
            </div>

            <p className="mt-4 text-xs text-gray-500 dark:text-gray-400">
                {scope.rangeLabel}
                {scope.environment && ` · ${scope.environment}`}
            </p>
        </>
    );
}
