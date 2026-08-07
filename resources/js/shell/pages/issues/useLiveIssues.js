import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { subscribe } from '../../broadcasting.js';

// Neue Fehler ohne Neuladen.
//
// **Was ankommt, ist ein Klingelzeichen und keine Zeile.** Der Broadcast sagt,
// dass es einen neuen Eintrag gibt; die Liste holt sich daraufhin ihre Seite neu
// (`router.reload`, nur die Nutzlast `issues`). Die Meldung selbst
// einzusortieren wäre die naheliegende, aber falsche Abkürzung: Sortierung,
// Zeitraum, Zustand und Seitengrenze löst der Server auf, und eine Zeile, die
// der Browser dazwischenschiebt, kann jede dieser Regeln verletzen — sie stünde
// dann in einer Liste, in die sie nicht gehört, und beim nächsten Blättern wäre
// sie wieder weg.
//
// **Ein Abo, nicht eines je Projekt.** Der Kanal umfasst die Organisation; was
// nicht zu den gewählten Projekten gehört, wird hier verworfen. Andersherum
// wären es bei fünfzig Projekten fünfzig Abos samt Berechtigungsanfragen für
// einen Seitenaufruf.
//
// **Nachgeladen wird nur, wo es nicht stört.** Auf der ersten Seite mit der
// Voreinstellung „zuletzt aufgetreten" gehört ein neuer Fehler nach ganz oben —
// da darf die Liste von selbst nachziehen. Auf Seite sieben oder bei einer
// anderen Sortierung würde derselbe Vorgang die Zeile unter dem Mauszeiger
// wegziehen; dort erscheint stattdessen ein Hinweis, den man drückt, wenn man so
// weit ist.
//
// Das Nachladen ist **entprellt**: bei einem Ausfall kommen neue Einträge in
// Schüben, und jeder für sich wäre eine Anfrage.
const DEBOUNCE_MS = 2000;

// Nachgeholt wird die Liste — und die Gesamtzahl daneben. Sie steht in einer
// eigenen Nutzlast, und ohne sie hier stünde nach dem Nachladen eine Zeile mehr
// in der Liste, während darüber weiter die alte Zahl steht.
const RELOAD_PROPS = ['issues', 'totalLabel'];

export default function useLiveIssues(live, { auto, paused = false } = {}) {
    const { shell } = usePage().props;
    const config = shell.broadcast;

    const [pending, setPending] = useState(0);
    const timer = useRef(null);

    // In Refs, damit ein Wechsel von `auto` oder `paused` nicht das Abo löst und
    // neu aufbaut — der Kanal ist derselbe geblieben.
    const state = useRef({ auto, paused, projectIds: live.projectIds });
    state.current = { auto, paused, projectIds: live.projectIds };

    const channel = live.channel;

    useEffect(() => {
        if (!channel) {
            return undefined;
        }

        const unsubscribe = subscribe(config, channel, 'issue.created', (payload) => {
            // Der Kanal trägt die ganze Organisation; gemeint ist die Auswahl
            // der Filterleiste. Eine leere Liste gäbe es hier nicht — ohne
            // Projekt zeigt die Seite nichts an und meldet auch nichts.
            if (!state.current.projectIds.includes(payload.projectId)) {
                return;
            }

            if (!state.current.auto || state.current.paused) {
                setPending((count) => count + 1);

                return;
            }

            if (timer.current) {
                clearTimeout(timer.current);
            }

            timer.current = setTimeout(() => {
                timer.current = null;
                router.reload({ only: RELOAD_PROPS });
            }, DEBOUNCE_MS);
        });

        return () => {
            unsubscribe();

            if (timer.current) {
                clearTimeout(timer.current);
                timer.current = null;
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [config, channel]);

    return {
        pending,
        show: () => {
            setPending(0);
            router.reload({ only: RELOAD_PROPS });
        },
    };
}
