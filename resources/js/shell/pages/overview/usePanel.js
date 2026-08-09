import { useCallback, useEffect, useState } from 'react';

// Die Zahlen einer Kachel der Übersichtsseiten — je Kachel ein eigener Abruf.
//
// **Das ist der Grund, warum die Seiten „schnell laden".** Fünf Kacheln sind
// fünf Anfragen, die der Browser nebeneinander stellt; das Raster steht sofort
// und füllt sich, statt auf die Summe aller Abfragen zu warten. Ein gemeinsamer
// Abruf wäre serverseitig eine Schleife — und der Bildschirm bliebe so lange
// leer, wie die langsamste Auswertung braucht. Dieselbe Entscheidung wie bei
// den Dashboard-Kacheln (D4).
//
// **Neu geholt wird, wenn sich die Adresse ändert.** In ihr steht der Zustand
// der Filterleiste; ein anderer Zeitraum ist eine andere Frage. Die Adresse
// kommt fertig vom Server und wird hier nicht zusammengesetzt: zwei Stellen,
// die eine Filteradresse bauen, sind zwei Stellen, an denen sie auseinander
// laufen kann.
export function usePanel(href) {
    const [state, setState] = useState({ status: 'loading', panel: null });

    const load = useCallback(
        (signal) => {
            setState((current) => ({ status: 'loading', panel: current.panel }));

            return fetch(href, {
                signal,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.json();
                })
                .then((payload) => setState({ status: 'ready', panel: payload.panel }))
                .catch((error) => {
                    // Ein abgebrochener Abruf ist kein Fehler: er passiert bei
                    // jedem Wechsel des Zeitraums, und eine Fehlermeldung dafür
                    // wäre eine Meldung über das eigene Aufräumen.
                    if (error.name !== 'AbortError') {
                        setState({ status: 'failed', panel: null });
                    }
                });
        },
        [href]
    );

    useEffect(() => {
        const controller = new AbortController();

        load(controller.signal);

        return () => controller.abort();
    }, [load]);

    return { ...state, reload: () => load() };
}
