import { useCallback, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { subscribe } from '../../broadcasting.js';

// Der Wartebildschirm des Einrichtungs-Assistenten: er erkennt die erste
// Meldung, ohne dass jemand neu lädt.
//
// **Zwei Wege, und beide werden gebraucht.** Der Broadcast ist das
// Klingelzeichen — er kommt in dem Moment, in dem der erste Fehler entsteht, und
// macht den Assistenten so schnell, wie er sich anfühlen soll. Verlassen darf
// sich der Bildschirm aber nicht auf ihn: der Websocket-Server ist optional
// (ohne Konfiguration meldet `shell.broadcast.enabled` false), und der
// Broadcast entsteht erst, wenn die Warteschlange die Meldung ausgewertet hat.
// Genau in der Einrichtung ist beides oft noch nicht in Betrieb. Deshalb fragt
// der Bildschirm zusätzlich nach — das ist der Boden, auf dem er steht, und der
// Broadcast zieht die Antwort nur vor.
//
// **Nachgefragt wird, bis der Fehler verlinkbar ist**, nicht nur bis die
// Meldung angekommen ist: der Datensatz in `ingest_payloads` entsteht beim
// Annehmen, der Fehlereintrag erst danach. Der Erfolg wird sofort gemeldet, der
// Verweis auf den Fehler kommt Sekunden später nach.
const POLL_MS = 3000;

// Nach dem Klingelzeichen kurz warten: der Broadcast läuft über die
// Warteschlange und kann dem Schreiben des Fehlereintrags um Millisekunden
// zuvorkommen. Ohne die Pause holte die Abfrage einen Stand, den es gerade noch
// nicht gibt — und der nächste reguläre Durchlauf wäre drei Sekunden später.
const SETTLE_MS = 300;

export default function useSetupWatch(initial, { statusHref, live }) {
    const { shell } = usePage().props;
    const config = shell.broadcast;

    const [state, setState] = useState(initial);

    // Ob noch etwas zu holen ist. In einem Ref, damit die laufenden Zeitgeber
    // den aktuellen Stand sehen, ohne dass ein neuer Effekt sie neu aufsetzt.
    const done = useRef(false);
    done.current = state.received && state.issue !== null;

    const inFlight = useRef(false);

    const check = useCallback(async () => {
        if (done.current || inFlight.current) {
            return;
        }

        inFlight.current = true;

        try {
            const response = await fetch(statusHref, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (response.ok) {
                setState(await response.json());
            }
        } catch {
            // Eine misslungene Abfrage ist kein Zustand: der Bildschirm behält
            // den letzten bekannten Stand und fragt beim nächsten Durchlauf
            // erneut. Ein Fehlertext an dieser Stelle würde eine kurze
            // Netzwerkstörung wie eine gescheiterte Einrichtung aussehen lassen.
        } finally {
            inFlight.current = false;
        }
    }, [statusHref]);

    // Regelmäßig nachfragen — aber nicht in einem Tab, den niemand ansieht. Ein
    // vergessener Assistent im Hintergrund soll nicht tagelang alle drei
    // Sekunden fragen; beim Zurückkommen wird sofort nachgeholt.
    useEffect(() => {
        if (done.current) {
            return undefined;
        }

        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                check();
            }
        }, POLL_MS);

        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                check();
            }
        };

        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [check, state.received, state.issue]);

    // Das Klingelzeichen. Der Kanal trägt die ganze Organisation — was nicht zu
    // diesem Projekt gehört, geht den Assistenten nichts an.
    useEffect(() => {
        if (!live.channel) {
            return undefined;
        }

        let timer = null;

        const unsubscribe = subscribe(config, live.channel, 'issue.created', (payload) => {
            if (payload.projectId !== live.projectId || done.current) {
                return;
            }

            timer = window.setTimeout(check, SETTLE_MS);
        });

        return () => {
            unsubscribe();

            if (timer) {
                window.clearTimeout(timer);
            }
        };
    }, [config, live.channel, live.projectId, check]);

    return state;
}
