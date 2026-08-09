import React from 'react';
import { usePage } from '@inertiajs/react';
import PageHead from '../components/PageHead.jsx';
import Card from '../components/Card.jsx';
import LiveDemo from '../components/LiveDemo.jsx';
import { useT } from '../i18n.js';

// Beispielseite des Grundgerüsts: zeigt, dass eine Seite nichts weiter tun muss,
// als ihren Inhalt zu liefern — Rahmen, Navigation, Theme und die globale
// Filterleiste kommen von der AppShell. Wird von den Fachseiten der nächsten
// Phasen abgelöst.
//
// Sie zeigt hier nur, worauf der Filter gerade zeigt: die Auswahl kommt vom
// Server, die Leiste selbst steht im Rahmen.
export default function Dashboard({ selection }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('dashboard.title')}
                appName={shell.appName}
                help={
                    <ul className="list-disc space-y-1 ps-4">
                        <li>{t('dashboard.help.sample')}</li>
                        <li>{t('dashboard.help.frame')}</li>
                        <li>{t('dashboard.help.filter')}</li>
                    </ul>
                }
            />

            <Card
                title={t('dashboard.selection.title')}
                description={t('dashboard.selection.description')}
                className="mb-4"
            >
                <dl className="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <Row
                        label={t('dashboard.selection.projects')}
                        value={
                            selection.projects.length === 0
                                ? t('dashboard.selection.no_projects')
                                : selection.projects.join(', ')
                        }
                    />
                    <Row
                        label={t('dashboard.selection.environment')}
                        value={selection.environment ?? t('dashboard.selection.all_environments')}
                    />
                    <Row label={t('dashboard.selection.period')} value={selection.rangeLabel} />
                </dl>
            </Card>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card
                    title={t('dashboard.cards.frame_title')}
                    description={t('dashboard.cards.frame_description')}
                />
                <Card
                    title={t('dashboard.cards.components_title')}
                    description={t('dashboard.cards.components_description')}
                />
                <Card
                    title={t('dashboard.cards.next_title')}
                    description={t('dashboard.cards.next_description')}
                />
            </div>

            <div className="mt-4">
                <LiveDemo />
            </div>
        </>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex justify-between gap-3 py-2">
            <dt className="text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="text-gray-900 dark:text-gray-100">{value}</dd>
        </div>
    );
}
