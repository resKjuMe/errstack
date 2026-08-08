// Die Oberfläche meldet ihre eigenen Fehler an dieselbe Installation, an die
// auch der Server meldet.
//
// Ein Teil dieser Anwendung läuft im Browser, und was dort bricht, sieht der
// Server nicht: eine Ansicht, die beim Zeichnen aussteigt, eine Inertia-Antwort,
// mit der die Seite nichts anfangen kann, ein Websocket, der nicht zustande
// kommt. Ohne diesen Weg wäre die Selbstüberwachung auf die halbe Anwendung
// blind — und zwar auf die Hälfte, die der Nutzer tatsächlich sieht.
//
// Die Angaben kommen als geteilte Inertia-Eigenschaft vom Server
// (`selfMonitoring`, siehe App\Support\SelfMonitoring\BrowserConfig) und nicht
// aus einer eingebauten Umgebungsvariable: ein Wechsel der Installation ist
// damit eine Zeile in der `.env` und kein neuer Build.

// Das SDK wird **nachgeladen** und nicht mitgebündelt. Ohne eingerichtete
// Selbstüberwachung — der Auslieferungszustand — kostet es dann gar nichts, und
// wer es einschaltet, bezahlt es mit einer zusätzlichen Anfrage statt mit
// dreißig Kilobyte in jedem ersten Seitenaufruf.
export async function startSelfMonitoring(config) {
    if (!config || !config.dsn) {
        return;
    }

    let Sentry;

    try {
        Sentry = await import('@sentry/react');
    } catch {
        // Ein fehlgeschlagener Nachladeversuch darf die Oberfläche nicht
        // aufhalten: die Überwachung ist der Beobachter und nicht das Werk.
        return;
    }

    const integrations = [];

    // Das Ladeerlebnis (Web Vitals) hängt an dieser Integration — sie misst,
    // wie lange bis zum ersten sichtbaren Inhalt vergeht, und nicht nur, ob
    // etwas kaputtgegangen ist. Ohne Stichprobenrate bleibt sie weg: eine
    // Messung, die niemand einsammelt, ist Arbeit ohne Ergebnis.
    if (config.tracesSampleRate !== null && config.tracesSampleRate !== undefined) {
        integrations.push(Sentry.browserTracingIntegration());
    }

    Sentry.init({
        dsn: config.dsn,
        environment: config.environment,
        release: config.release ?? undefined,
        integrations,
        tracesSampleRate: config.tracesSampleRate ?? 0,
        // Die eigene Datenaufnahme steht nicht in den Brotkrumen. Sie ist der
        // Empfänger dieser Meldungen, und jede ihrer Anfragen an jeder
        // folgenden Meldung wäre Rauschen über den eigenen Meldeweg.
        beforeBreadcrumb: (breadcrumb) => {
            const url = breadcrumb?.data?.url;

            if (typeof url === 'string' && /\/api\/\d+\/(store|envelope|security)\/?/.test(url)) {
                return null;
            }

            return breadcrumb;
        },
    });
}
