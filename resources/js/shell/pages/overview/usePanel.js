import { useCallback, useEffect, useRef, useState } from 'react';

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
//
// **Es läuft immer nur ein Abruf.** Auch der von Hand ausgelöste hängt am
// Abbruch-Signal — sonst könnte eine Antwort auf die alte Frage nach einer
// Antwort auf die neue eintreffen und die Kachel mit Zahlen füllen, die zu
// einem Zeitraum gehören, der oben längst nicht mehr eingestellt ist. Genau
// diese Verwechslung ist von außen nicht zu erkennen.
export function usePanel(href) {
    const [state, setState] = useState({ status: 'loading', panel: null });
    const running = useRef(null);

    const load = useCallback(() => {
        running.current?.abort();

        const controller = new AbortController();

        running.current = controller;

        setState((current) => ({ status: 'loading', panel: current.panel }));

        return fetch(href, {
            signal: controller.signal,
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
                if (error.name === 'AbortError') {
                    return;
                }

                // Die zuletzt geholten Zahlen bleiben stehen — mit dem Hinweis
                // daneben. Sie zu löschen wäre der schlechtere Tausch: eine
                // Kachel, die wegen eines misslungenen Nachladens leer wird,
                // hat den Bildschirm ärmer gemacht, ohne etwas zu erklären.
                setState((current) => ({ status: 'failed', panel: current.panel }));
            });
    }, [href]);

    useEffect(() => {
        load();

        return () => running.current?.abort();
    }, [load]);

    return { ...state, reload: load };
}
