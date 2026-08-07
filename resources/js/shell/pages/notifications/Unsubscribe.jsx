import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import GuestShell from '../../GuestShell.jsx';
import Flash from '../../components/Flash.jsx';
import { DangerButton, PrimaryButton, formLinkClass } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Abmelden über den Link aus einer Mail. Die Seite ist ohne Anmeldung
// erreichbar (der Empfänger sitzt oft an einem anderen Gerät), deshalb der
// Gast-Rahmen — und deshalb steht die Adresse des Kontos hier: nur so sieht
// man, für wen man gerade abbestellt.
export default function Unsubscribe({ recipient, event, applyHref, settingsHref, state }) {
    const { flash } = usePage().props;
    const t = useT();
    const [processing, setProcessing] = useState(false);

    const apply = (mode) => {
        setProcessing(true);

        router.post(
            applyHref,
            { mode },
            { preserveScroll: true, onFinish: () => setProcessing(false) }
        );
    };

    return (
        <div className="space-y-4">
            <Flash status={flash?.status} error={flash?.error} />

            <h1 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                {t('notifications.unsubscribe.heading')}
            </h1>

            <p className="text-sm text-gray-600 dark:text-gray-400">
                {t('notifications.unsubscribe.recipient', { email: recipient.email })}
            </p>

            {event.critical ? (
                <CriticalNotice event={event} settingsHref={settingsHref} />
            ) : (
                <div className="space-y-4">
                    <p className="text-sm text-gray-600 dark:text-gray-400">{event.description}</p>

                    <div className="space-y-3">
                        <div>
                            <PrimaryButton
                                type="button"
                                disabled={processing || state.eventOff}
                                onClick={() => apply('event')}
                            >
                                {t('notifications.unsubscribe.event_off', {
                                    event: event.label,
                                })}
                            </PrimaryButton>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {t(
                                    state.eventOff
                                        ? 'notifications.unsubscribe.event_off_done'
                                        : 'notifications.unsubscribe.event_off_hint'
                                )}
                            </p>
                        </div>

                        <div>
                            <DangerButton
                                type="button"
                                disabled={processing || state.allOff}
                                onClick={() => apply('all')}
                            >
                                {t('notifications.unsubscribe.all_off')}
                            </DangerButton>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {t(
                                    state.allOff
                                        ? 'notifications.unsubscribe.all_off_done'
                                        : 'notifications.unsubscribe.all_off_hint'
                                )}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            <p className="text-sm">
                <a href={settingsHref} className={formLinkClass}>
                    {t('notifications.unsubscribe.settings_link')}
                </a>
            </p>
        </div>
    );
}

// Ein kritischer Alarm lässt sich hier nicht mit einem Klick stillschalten:
// wer die Bereitschaft abschaltet, soll das angemeldet und mit der Warnung vor
// Augen tun, nicht aus einer Mail heraus.
function CriticalNotice({ event, settingsHref }) {
    const t = useT();

    return (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            <p className="font-semibold">
                {t('notifications.unsubscribe.critical_title', { event: event.label })}
            </p>
            <p className="mt-2">
                {t('notifications.unsubscribe.critical_body_before')}{' '}
                <a href={settingsHref} className={formLinkClass}>
                    {t('notifications.unsubscribe.critical_link')}
                </a>
                .
            </p>
        </div>
    );
}

Unsubscribe.layout = (page) => (
    <GuestShell titleKey="notifications.unsubscribe.title">{page}</GuestShell>
);
