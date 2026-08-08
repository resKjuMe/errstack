/*
|--------------------------------------------------------------------------
| Beispiel: @sentry/node
|--------------------------------------------------------------------------
|
| Schickt Nachricht, Fehler, Transaktion und Sitzung mit dem offiziellen
| Node-SDK. Getauscht wird nur die DSN:
|
|     npm install
|
|     # gegen den laufenden Klon
|     SENTRY_DSN="http://<public_key>@localhost:8000/1" npm run senden
|
|     # gegen den Mitschnitt-Server (Aufnahme neu erstellen)
|     npm run senden
|
| Die Sitzung kommt aus diesem Beispiel und nicht aus dem PHP-Beispiel: Release
| Health gibt es im PHP-SDK nicht.
*/

import * as Sentry from '@sentry/node';

const dsn = process.env.SENTRY_DSN || 'http://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@127.0.0.1:9911/1';

Sentry.init({
    dsn,
    release: 'compat@1.0.0',
    environment: 'compat',
    // Ohne das schickt das SDK die Transaktion nicht ab, sondern verwirft sie
    // als nicht gezogene Stichprobe.
    tracesSampleRate: 1.0,
    // Ein Beispielskript läuft und ist gleich wieder weg; ohne das würde jede
    // Meldung einzeln gebündelt und die Reihenfolge im Mitschnitt wäre Zufall.
    maxBreadcrumbs: 10,
});

Sentry.setUser({ id: '4711', username: 'kompatibilitaet' });
Sentry.setTag('beispiel', 'sentry-node');
Sentry.addBreadcrumb({ category: 'beispiel', message: 'Rechnung geladen', level: 'info' });

// 1. Eine Sitzung — sie umschließt alles Weitere, so wie eine Anwendung sie um
//    ihre Laufzeit legt.
Sentry.startSession();

// 2. Eine Nachricht ohne Fehler.
Sentry.captureMessage('Kompatibilitätsprobe: Nachricht aus @sentry/node', 'info');
await Sentry.flush(5000);

// 3. Ein echter Fehler mit Stacktrace und Ursache.
try {
    try {
        throw new TypeError('Rechnungsnummer 4711 ist unbekannt');
    } catch (ursache) {
        throw new Error('Rechnung konnte nicht erstellt werden', { cause: ursache });
    }
} catch (fehler) {
    Sentry.captureException(fehler);
}
await Sentry.flush(5000);

// 4. Eine Transaktion mit zwei Einzelschritten.
await Sentry.startSpan({ name: 'GET /rechnungen', op: 'http.server' }, async () => {
    for (const [op, beschreibung] of [
        ['db.sql.query', 'select * from invoices'],
        ['http.client', 'GET https://zahlungen.example/status'],
    ]) {
        await Sentry.startSpan({ op, name: beschreibung }, async () => {
            await new Promise((fertig) => setTimeout(fertig, 15));
        });
    }
});
await Sentry.flush(5000);

// 5. Die Sitzung beenden — das schickt sie ab.
Sentry.endSession();
await Sentry.flush(5000);

console.log(`@sentry/node: Nachricht, Fehler, Transaktion und Sitzung an ${dsn} geschickt`);
