import React, { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
// Die Stilangaben stehen als gewöhnlicher Import oben und werden nicht wie der
// Abspieler selbst nachgeladen: sie sind ein paar Kilobyte, und ein
// nachgeladenes Stylesheet zeigt für einen Augenblick einen unfertigen
// Abspieler. Das Gewicht, um dessentwillen der Rest lazy geladen wird, steckt
// im JavaScript.
import 'rrweb-player/dist/style.css';
import { useTranslations } from '../../i18n.js';
import { clock } from './format.js';

// Der Abspieler.
//
// **Die Wiedergabe selbst bauen wir nicht.** Eine Aufzeichnung ist ein
// rrweb-Strom, und ihn abzuspielen heißt, ein DOM aus Schnappschüssen und
// Änderungslisten wiederherzustellen — mitsamt Stilen, Bildern, Eingabeständen
// und dem Mauszeiger. Das ist die Bibliothek, die ihn erzeugt hat, und keine
// Aufgabe, die man daneben noch einmal löst.
//
// Was wir bauen, sind die Bedienelemente daneben: Abspielen, Pause, Tempo,
// Pausen überspringen — und vor allem der Sprung zu einem Zeitpunkt, den die
// Zeitleiste braucht. Deshalb läuft die Bibliothek **ohne** ihre eigene
// Steuerleiste (`showController: false`): zwei Leisten übereinander wären zwei
// Wahrheiten über denselben Zustand.
//
// Geladen wird sie erst, wenn diese Seite aufgeschlagen wird. Sie wiegt ein
// Mehrfaches der übrigen Oberfläche, und sie wird auf genau einer Seite
// gebraucht — im Hauptbündel wäre sie ein Aufschlag auf jeden Seitenaufruf
// dieser Anwendung für eine Seite, die die meisten nie öffnen.
const Player = forwardRef(function Player({ events, onTime, onState }, ref) {
    const { t } = useTranslations();
    const containerRef = useRef(null);
    const playerRef = useRef(null);
    const [failed, setFailed] = useState(false);

    // Die Rückrufe wandern über eine Referenz in den Abspieler und nicht über
    // die Abhängigkeitsliste: sie ändern sich bei jedem Rendern der Seite, und
    // der Abspieler dürfte deswegen nicht neu gebaut werden — das wäre bei jedem
    // Zeitstempel ein Neustart des Films.
    const handlers = useRef({ onTime, onState });
    handlers.current = { onTime, onState };

    useEffect(() => {
        // rrweb braucht mindestens zwei Ereignisse, um überhaupt eine Spanne zu
        // haben. Ein einzelner Schnappschuss ist kein Film — und der Aufrufer
        // zeigt für diesen Fall ohnehin einen Hinweis.
        if (!containerRef.current || !Array.isArray(events) || events.length < 2) {
            return undefined;
        }

        let disposed = false;
        let instance = null;

        (async () => {
            try {
                const { default: RrwebPlayer } = await import('rrweb-player');

                if (disposed || !containerRef.current) {
                    return;
                }

                instance = new RrwebPlayer({
                    target: containerRef.current,
                    props: {
                        events,
                        autoPlay: false,
                        showController: false,
                        // Die Mausspur ist hübsch und kostet bei einer langen
                        // Sitzung spürbar Rechenzeit im Browser dessen, der
                        // gerade einen Fehler untersucht.
                        mouseTail: false,
                        width: containerRef.current.clientWidth,
                        height: Math.round(containerRef.current.clientWidth * 0.5625),
                    },
                });

                instance.addEventListener('ui-update-current-time', (event) => {
                    handlers.current.onTime?.(event.payload);
                });

                instance.addEventListener('ui-update-player-state', (event) => {
                    handlers.current.onState?.(event.payload);
                });

                playerRef.current = instance;
            } catch {
                // Das Nachladen kann scheitern — ein abgebrochener Netzzugang,
                // ein Bündel, das nach einer Auslieferung nicht mehr da ist. Die
                // Seite bleibt stehen und sagt es; eine leere Fläche ohne
                // Erklärung wäre die schlechtere Antwort.
                if (!disposed) {
                    setFailed(true);
                }
            }
        })();

        return () => {
            disposed = true;
            playerRef.current = null;

            // Der Abspieler hängt einen eigenen Baum in den Behälter. Ihn beim
            // Verlassen der Seite stehen zu lassen hieße, einen zweiten daneben
            // zu bauen, sobald jemand zurückkommt.
            instance?.$destroy?.();

            if (containerRef.current) {
                containerRef.current.innerHTML = '';
            }
        };
    }, [events]);

    useImperativeHandle(
        ref,
        () => ({
            play: () => playerRef.current?.play(),
            pause: () => playerRef.current?.pause(),
            // `goto` mit ausdrücklichem zweiten Argument: ohne es startet der
            // Abspieler nach dem Sprung von selbst, und ein Klick auf die
            // Zeitleiste einer pausierten Aufzeichnung ließe sie loslaufen.
            goto: (offsetMs, play = false) => playerRef.current?.goto(Math.max(0, offsetMs), play),
            setSpeed: (speed) => playerRef.current?.setSpeed(speed),
            toggleSkipInactive: () => playerRef.current?.toggleSkipInactive(),
        }),
        []
    );

    if (failed) {
        return (
            <div className="rounded border border-dashed border-gray-300 p-8 text-center dark:border-gray-600">
                <p className="text-sm text-gray-700 dark:text-gray-200">
                    {t('replays.player.failed')}
                </p>
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('replays.player.failed_hint')}
                </p>
            </div>
        );
    }

    return (
        <div
            ref={containerRef}
            className="overflow-hidden rounded border border-gray-200 bg-gray-950 dark:border-gray-700"
        />
    );
});

export default Player;

// Die Zeitanzeige „1:23 von 4:56".
//
// Sie steht hier und nicht in der Steuerleiste, weil sie dasselbe Format
// braucht wie die Zeitleiste — und weil sie damit an genau einer Stelle
// definiert ist.
export function Position({ currentMs, durationMs }) {
    const { t } = useTranslations();

    return (
        <span className="tabular-nums text-xs text-gray-600 dark:text-gray-300">
            {t('replays.player.position', {
                position: clock(currentMs),
                duration: clock(durationMs),
            })}
        </span>
    );
}
