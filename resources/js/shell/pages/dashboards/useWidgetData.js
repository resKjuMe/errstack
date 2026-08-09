import { useCallback, useEffect, useState } from 'react';

// Die Zahlen einer Kachel — je Kachel ein eigener Abruf.
//
// **Das ist die Parallelität, von der die Aufgabe spricht.** Zwanzig Kacheln
// sind zwanzig Anfragen, die der Browser nebeneinander stellt; das Raster steht
// sofort und füllt sich, statt auf die Summe aller Abfragen zu warten. Ein
// gemeinsamer Abruf für alle Kacheln wäre serverseitig eine Schleife — und die
// Seite bliebe so lange leer, wie die langsamste Kachel braucht.
//
// **Neu geholt wird, wenn sich die Adresse ändert.** In ihr steht der Zustand
// der Filterleiste; ein anderer Zeitraum ist eine andere Frage. Die eigene Sicht
// der Kachel steht am Server und nicht im Aufruf — sie ändert sich nur, wenn die
// Kachel geändert wird, und dann ist ohnehin alles neu geladen.
export function useWidgetData(widget, href) {
    const [state, setState] = useState({ status: 'loading', data: null });

    const load = useCallback(
        (signal) => {
            setState((current) => ({ status: 'loading', data: current.data }));

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
                .then((payload) => setState({ status: 'ready', data: payload.widget }))
                .catch((error) => {
                    // Ein abgebrochener Abruf ist kein Fehler: er passiert bei
                    // jedem Wechsel des Zeitraums, und eine Fehlermeldung dafür
                    // wäre eine Meldung über das eigene Aufräumen.
                    if (error.name !== 'AbortError') {
                        setState({ status: 'failed', data: null });
                    }
                });
        },
        [href]
    );

    useEffect(() => {
        const controller = new AbortController();

        load(controller.signal);

        return () => controller.abort();
    }, [load, widget.id]);

    return { ...state, reload: () => load() };
}
