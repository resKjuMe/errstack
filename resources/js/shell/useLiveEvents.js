import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { subscribe } from './broadcasting.js';

/**
 * Sammelt eingehende Broadcast-Ereignisse eines Kanals und gibt sie — neueste
 * zuerst — an die Ansicht zurück. `limit` begrenzt die Liste, damit ein lange
 * offener Tab nicht unbegrenzt wächst.
 *
 *     const { events, enabled } = useLiveEvents('demo.ingest.processed');
 */
export function useLiveEvents(eventName, { limit = 10 } = {}) {
    const { shell } = usePage().props;
    const config = shell.broadcast;
    const [events, setEvents] = useState([]);

    useEffect(() => {
        return subscribe(config, config.channel, eventName, (payload) => {
            setEvents((current) => [payload, ...current].slice(0, limit));
        });
    }, [config, eventName, limit]);

    return { events, enabled: config.enabled };
}
