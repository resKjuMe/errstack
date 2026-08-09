import React from 'react';
import { usePage } from '@inertiajs/react';
import PageHead from '../components/PageHead.jsx';
import Card from '../components/Card.jsx';
import Flash from '../components/Flash.jsx';
import LiveDemo from '../components/LiveDemo.jsx';
import { useToast } from '../components/Toast.jsx';
import {
    KpiTilesSkeleton,
    LinesCardSkeleton,
    CardsSkeleton,
    TableSkeleton,
} from '../components/Skeleton.jsx';
import { useT } from '../i18n.js';

// Musterseite: zeigt jeden wiederverwendbaren Baustein einmal, damit sich
// Aussehen und Verhalten in Hell und Dunkel prüfen lassen, ohne eine Fachseite
// zu brauchen.

const buttonClass =
    'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-900';

export default function Components() {
    const { shell } = usePage().props;
    const toast = useToast();
    const t = useT();

    return (
        <>
            <PageHead
                title={t('components.title')}
                appName={shell.appName}
                meta={
                    <span className="text-sm text-gray-500 dark:text-gray-400">
                        resources/js/shell/components
                    </span>
                }
                help={t('components.help')}
            />

            <div className="space-y-6">
                <Card
                    title={t('components.flash.title')}
                    description={t('components.flash.description')}
                >
                    <Flash
                        status={t('components.flash.example_status')}
                        error={t('components.flash.example_error')}
                        errors={{
                            name: t('components.flash.example_name_error'),
                            email: t('components.flash.example_email_error'),
                        }}
                    />
                </Card>

                <Card
                    title={t('components.toasts.title')}
                    description={t('components.toasts.description')}
                >
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => toast.success(t('components.toasts.example_success'))}
                            className={`${buttonClass} bg-green-600 hover:bg-green-700 focus:ring-green-500`}
                        >
                            {t('components.toasts.success')}
                        </button>
                        <button
                            type="button"
                            onClick={() => toast.error(t('components.toasts.example_error'))}
                            className={`${buttonClass} bg-rose-600 hover:bg-rose-700 focus:ring-rose-500`}
                        >
                            {t('components.toasts.error')}
                        </button>
                        <button
                            type="button"
                            onClick={() => toast.info(t('components.toasts.example_info'))}
                            className={`${buttonClass} bg-gray-700 hover:bg-gray-800 focus:ring-gray-500`}
                        >
                            {t('components.toasts.info')}
                        </button>
                    </div>
                </Card>

                {/* Die Platzhalter bringen ihre eigenen Karten mit und stehen
                    deshalb direkt auf dem Seitenhintergrund — so wie später auch
                    im Einsatz. */}
                <section className="space-y-4">
                    <div>
                        <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                            {t('components.skeleton.title')}
                        </h2>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {t('components.skeleton.description')}
                        </p>
                    </div>
                    <KpiTilesSkeleton />
                    <CardsSkeleton />
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <LinesCardSkeleton rows={2} />
                        <TableSkeleton rows={3} />
                    </div>
                </section>

                {/* Die Warteschlangen- und Broadcast-Probe. Sie stand bis D5 auf
                    der Übersicht; dort steht jetzt eine Fachseite, und eine
                    Probe gehört ohnehin auf die Musterseite. */}
                <LiveDemo />
            </div>
        </>
    );
}
