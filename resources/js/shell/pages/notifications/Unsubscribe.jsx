import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import GuestShell from '../../GuestShell.jsx';
import Flash from '../../components/Flash.jsx';
import { DangerButton, PrimaryButton, formLinkClass } from '../../components/Form.jsx';

// Abmelden über den Link aus einer Mail. Die Seite ist ohne Anmeldung
// erreichbar (der Empfänger sitzt oft an einem anderen Gerät), deshalb der
// Gast-Rahmen — und deshalb steht die Adresse des Kontos hier: nur so sieht
// man, für wen man gerade abbestellt.
export default function Unsubscribe({ recipient, event, applyHref, settingsHref, state }) {
    const { flash } = usePage().props;
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
                Benachrichtigungen abbestellen
            </h1>

            <p className="text-sm text-gray-600 dark:text-gray-400">
                Für <span className="font-medium">{recipient.email}</span>.
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
                                Keine E-Mails mehr zu „{event.label}“
                            </PrimaryButton>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {state.eventOff
                                    ? 'Ist bereits abgeschaltet.'
                                    : 'Wirkt sofort — auch für Mails, die schon in der Warteschlange stehen.'}
                            </p>
                        </div>

                        <div>
                            <DangerButton
                                type="button"
                                disabled={processing || state.allOff}
                                onClick={() => apply('all')}
                            >
                                Alles abbestellen
                            </DangerButton>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {state.allOff
                                    ? 'Ist bereits pauschal abbestellt.'
                                    : 'Schaltet alle nicht-kritischen Benachrichtigungen ab. Kritische Alarme kommen weiterhin an.'}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            <p className="text-sm">
                <a href={settingsHref} className={formLinkClass}>
                    Alle Einstellungen öffnen
                </a>
            </p>
        </div>
    );
}

// Ein kritischer Alarm lässt sich hier nicht mit einem Klick stillschalten:
// wer die Bereitschaft abschaltet, soll das angemeldet und mit der Warnung vor
// Augen tun, nicht aus einer Mail heraus.
function CriticalNotice({ event, settingsHref }) {
    return (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            <p className="font-semibold">„{event.label}“ ist ein kritischer Alarm.</p>
            <p className="mt-2">
                Er erreicht dich auch in der Ruhezeit und nach einer pauschalen Abmeldung.
                Abschalten lässt er sich nur ausdrücklich in den{' '}
                <a href={settingsHref} className={formLinkClass}>
                    Benachrichtigungs-Einstellungen
                </a>
                .
            </p>
        </div>
    );
}

Unsubscribe.layout = (page) => <GuestShell title="Abbestellen">{page}</GuestShell>;
