import Pusher from 'pusher-js';

// Websocket-Verbindung der Oberfläche (pusher-js). Lokal spricht sie mit dem
// selbst gehosteten Reverb, in der Produktion mit Pusher Cloud — dasselbe
// Protokoll, nur andere Verbindungsdaten aus `shell.broadcast`.
//
// Es gibt genau EINE Verbindung pro Tab: sie wird beim ersten Abonnenten
// aufgebaut und danach von allen Komponenten geteilt.

let client = null;
let clientConfig = null;

function connect(config) {
    // `cluster` verlangt pusher-js immer, auch wenn es — wie bei einem selbst
    // gehosteten Server mit eigenem Host — gar nicht benutzt wird; dann bleibt
    // es leer. Pusher Cloud braucht den echten Wert.
    const options = {
        cluster: config.cluster ?? '',
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    };

    if (config.host) {
        options.wsHost = config.host;
        options.wsPort = config.port;
        options.wssPort = config.port;
    }

    return new Pusher(config.key, options);
}

/**
 * Ein Ereignis eines Kanals abonnieren. Gibt eine Funktion zurück, die das
 * Abo wieder löst; ohne Konfiguration passiert nichts (und der Aufrufer merkt
 * es an `enabled`).
 */
export function subscribe(config, channelName, eventName, handler) {
    if (!config?.enabled) {
        return () => {};
    }

    if (!client) {
        try {
            client = connect(config);
            clientConfig = config;
        } catch (e) {
            // Eine kaputte Websocket-Konfiguration darf die Seite nicht mitreißen:
            // ohne Live-Aktualisierung ist alles andere weiter bedienbar.
            console.error('[broadcast] Verbindung nicht möglich:', e);

            return () => {};
        }
    } else if (clientConfig?.key !== config.key) {
        // Sollte nicht vorkommen (die Config kommt vom Server), wäre aber ein
        // stiller Fehler: die alte Verbindung würde weiterlaufen.
        console.warn(
            '[broadcast] Abweichende Verbindungsdaten — die bestehende Verbindung bleibt bestehen.'
        );
    }

    const channel = client.subscribe(channelName);
    channel.bind(eventName, handler);

    return () => {
        channel.unbind(eventName, handler);
    };
}
