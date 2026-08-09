import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../components/PageHead.jsx';
import Card from '../components/Card.jsx';
import PanelCard from './overview/PanelCard.jsx';
import { useT } from '../i18n.js';

// Die Übersicht der Organisation — der Einstieg nach dem Anmelden.
//
// **Sie zeigt Zustand und offene Punkte auf einen Blick** und beantwortet keine
// Frage zu Ende: jede Zahl führt in die Ansicht, aus der sie stammt, mit
// demselben Zeitraum.
//
// **Die Seite liefert nur das Raster.** Jede Kachel holt ihre Zahlen über eine
// eigene Adresse; fünf Kacheln sind fünf Anfragen, die der Browser nebeneinander
// stellt. Die Seite steht deshalb sofort und füllt sich, statt auf die Summe
// aller Auswertungen zu warten.
export default function Dashboard({ scope, panels, projectsHref, hasProjects }) {
    const { shell } = usePage().props;
    const t = useT();
    const panel = (key) => panels.find((entry) => entry.key === key)?.href;

    return (
        <>
            <PageHead
                title={t('overview.organization.title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('overview.organization.help.panels')}</li>
                        <li>{t('overview.organization.help.filter')}</li>
                        <li>{t('overview.organization.help.links')}</li>
                    </ul>
                }
            />

            {/* Ohne ein einziges Projekt ist die Übersicht keine leere
                Auswertung, sondern eine Organisation ohne Projekte. Fünf leere
                Kacheln wären hier die falsche Antwort — die Seite zeigt den
                nächsten Schritt. */}
            {!hasProjects ? (
                <Card
                    title={t('overview.organization.no_projects.title')}
                    description={t('overview.organization.no_projects.description')}
                >
                    <Link
                        href={projectsHref}
                        className="text-sm font-medium text-rose-600 hover:text-rose-500 dark:text-rose-400"
                    >
                        {t('overview.organization.no_projects.action')}
                    </Link>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <PanelCard
                        href={panel('errors')}
                        title={t('overview.organization.errors.title')}
                        description={t('overview.organization.errors.description')}
                    />
                    <PanelCard
                        href={panel('transactions')}
                        title={t('overview.organization.transactions.title')}
                        description={t('overview.organization.transactions.description')}
                    />
                    <PanelCard
                        href={panel('projects')}
                        title={t('overview.organization.projects.title')}
                        description={t('overview.organization.projects.description')}
                    />
                    <PanelCard
                        href={panel('alerts')}
                        title={t('overview.organization.alerts.title')}
                        description={t('overview.organization.alerts.description')}
                        emptyText={t('overview.organization.alerts.empty')}
                    />
                    <PanelCard
                        href={panel('quota')}
                        title={t('overview.organization.quota.title')}
                        description={t('overview.organization.quota.description')}
                        className="lg:col-span-2"
                    />
                </div>
            )}

            <p className="mt-4 text-xs text-gray-500 dark:text-gray-400">
                {scope.rangeLabel}
                {scope.environment && ` · ${scope.environment}`}
            </p>
        </>
    );
}
