import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import PanelCard from '../overview/PanelCard.jsx';
import { useT } from '../../i18n.js';

// Die Übersicht eines Teams: unsere Projekte, was auf uns wartet, wer woran
// sitzt.
//
// Sie beantwortet eine andere Frage als die Organisations-Übersicht: dort geht
// es darum, wo etwas los ist, hier darum, was **wir** zu tun haben. Deshalb
// stehen die ungeprüften Fehler vor den Zuweisungen — ein ungeprüfter Fehler
// hat noch niemanden, der ihn hält.
export default function Overview({ team, scope, panels }) {
    const { shell } = usePage().props;
    const t = useT();
    const panel = (key) => panels.find((entry) => entry.key === key)?.href;

    return (
        <>
            <PageHead
                title={t('overview.team.title', { name: team.name })}
                appName={shell.appName}
                help={<p>{t('overview.team.description')}</p>}
            />

            <div className="mb-4 text-xs">
                <Link
                    href={team.settingsHref}
                    className="font-medium text-rose-600 hover:text-rose-500 dark:text-rose-400"
                >
                    {t('overview.team.settings')}
                </Link>
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <PanelCard
                    href={panel('projects')}
                    title={t('overview.team.projects.title')}
                    description={t('overview.team.projects.description')}
                    emptyText={t('overview.team.projects.empty')}
                    className="lg:col-span-2"
                />
                <PanelCard
                    href={panel('review')}
                    title={t('overview.team.review.title')}
                    description={t('overview.team.review.description')}
                />
                <PanelCard
                    href={panel('assignments')}
                    title={t('overview.team.assignments.title')}
                    description={t('overview.team.assignments.description')}
                />
            </div>

            <p className="mt-4 text-xs text-gray-500 dark:text-gray-400">
                {scope.rangeLabel}
                {scope.environment && ` · ${scope.environment}`}
            </p>
        </>
    );
}
