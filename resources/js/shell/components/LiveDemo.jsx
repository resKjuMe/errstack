import React from 'react';
import { router } from '@inertiajs/react';
import Card from './Card.jsx';
import { useToast } from './Toast.jsx';
import { useLiveEvents } from '../useLiveEvents.js';

// Vorführung der Hintergrund-Verarbeitung: Der Knopf legt einen Ingest-Job in
// die Warteschlange, der Worker verarbeitet ihn und meldet das Ergebnis per
// Broadcast — die Liste unten füllt sich ohne Neuladen, auch in einem zweiten
// offenen Fenster.

const buttonClass =
    'inline-flex items-center rounded-md px-3 py-2 text-sm font-medium text-white shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 dark:focus:ring-offset-gray-800';

export default function LiveDemo() {
    const { events, enabled } = useLiveEvents('demo.ingest.processed');
    const toast = useToast();

    const dispatch = (fail) => {
        router.post(
            '/demo/ingest',
            { fail },
            {
                preserveScroll: true,
                onError: () => toast.error('Der Job konnte nicht eingereiht werden.'),
            },
        );
    };

    return (
        <Card
            title="Hintergrund-Verarbeitung"
            description="Der Job läuft in der Warteschlange „ingest“; sein Ergebnis kommt per Broadcast zurück."
        >
            <div className="flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={() => dispatch(false)}
                    className={`${buttonClass} bg-rose-600 hover:bg-rose-700 focus:ring-rose-500`}
                >
                    Ingest einreihen
                </button>
                <button
                    type="button"
                    onClick={() => dispatch(true)}
                    className={`${buttonClass} bg-gray-700 hover:bg-gray-800 focus:ring-gray-500`}
                >
                    Fehlschlag erzwingen
                </button>
            </div>

            {!enabled && (
                <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    Live-Aktualisierung ist aus: <code>BROADCAST_CONNECTION</code> und die Verbindungsdaten sind nicht
                    gesetzt. Der Job läuft trotzdem — sichtbar in der Worker-Ausgabe.
                </p>
            )}

            {enabled && (
                <ul className="mt-4 space-y-2 text-sm">
                    {events.length === 0 && (
                        <li className="text-gray-500 dark:text-gray-400">
                            Noch nichts eingegangen — Worker läuft? <code>php artisan queue:work</code>
                        </li>
                    )}
                    {events.map((event) => (
                        <li
                            key={`${event.reference}-${event.processedAt}`}
                            className="flex items-center justify-between gap-3 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-900/50"
                        >
                            <span className="text-gray-700 dark:text-gray-300">{event.message}</span>
                            <span className="shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">
                                {event.processedAt}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
