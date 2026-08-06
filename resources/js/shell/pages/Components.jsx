import React from 'react';
import { usePage } from '@inertiajs/react';
import PageHead from '../components/PageHead.jsx';
import Card from '../components/Card.jsx';
import Flash from '../components/Flash.jsx';
import { useToast } from '../components/Toast.jsx';
import {
    KpiTilesSkeleton,
    LinesCardSkeleton,
    CardsSkeleton,
    TableSkeleton,
} from '../components/Skeleton.jsx';

// Musterseite: zeigt jeden wiederverwendbaren Baustein einmal, damit sich
// Aussehen und Verhalten in Hell und Dunkel prüfen lassen, ohne eine Fachseite
// zu brauchen.

const buttonClass =
    'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-900';

export default function Components() {
    const { shell } = usePage().props;
    const toast = useToast();

    return (
        <>
            <PageHead
                title="Bausteine"
                appName={shell.appName}
                meta={
                    <span className="text-sm text-gray-500 dark:text-gray-400">
                        resources/js/shell/components
                    </span>
                }
                help="Jeder Baustein einmal in Aktion — zum Prüfen von Hell-/Dunkelmodus und Verhalten."
            />

            <div className="space-y-6">
                <Card
                    title="Meldungen (Flash)"
                    description="Werden aus der Session gelesen und oben im Inhalt gezeigt."
                >
                    <Flash
                        status="Die Änderungen wurden gespeichert."
                        error="Der Vorgang konnte nicht abgeschlossen werden."
                        errors={{
                            name: 'Der Name ist erforderlich.',
                            email: 'Die E-Mail-Adresse ist ungültig.',
                        }}
                    />
                </Card>

                <Card
                    title="Toasts"
                    description="Kurzlebige Rückmeldungen rechts unten, unabhängig vom Seiteninhalt."
                >
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => toast.success('Die Änderungen wurden gespeichert.')}
                            className={`${buttonClass} bg-green-600 hover:bg-green-700 focus:ring-green-500`}
                        >
                            Erfolg
                        </button>
                        <button
                            type="button"
                            onClick={() => toast.error('Das hat leider nicht geklappt.')}
                            className={`${buttonClass} bg-rose-600 hover:bg-rose-700 focus:ring-rose-500`}
                        >
                            Fehler
                        </button>
                        <button
                            type="button"
                            onClick={() => toast.info('Zur Kenntnis genommen.')}
                            className={`${buttonClass} bg-gray-700 hover:bg-gray-800 focus:ring-gray-500`}
                        >
                            Hinweis
                        </button>
                    </div>
                </Card>

                {/* Die Platzhalter bringen ihre eigenen Karten mit und stehen
                    deshalb direkt auf dem Seitenhintergrund — so wie später auch
                    im Einsatz. */}
                <section className="space-y-4">
                    <div>
                        <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                            Ladeplatzhalter (Skeleton)
                        </h2>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Bis die Daten da sind — gleiche Grautöne wie das restliche UI.
                        </p>
                    </div>
                    <KpiTilesSkeleton />
                    <CardsSkeleton />
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <LinesCardSkeleton rows={2} />
                        <TableSkeleton rows={3} />
                    </div>
                </section>
            </div>
        </>
    );
}
