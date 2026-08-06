# Webhooks

Der Kanal **Webhook** schickt jede Meldung als JSON an eine eigene Adresse —
unterschrieben, damit der Empfänger sicher sein kann, dass sie wirklich von
dieser Errstack-Installation stammt.

Eingerichtet wird er unter *Organisation › Benachrichtigungen*: Ziel-URL und ein
Geheimnis (mindestens 16 Zeichen). Das Geheimnis wird verschlüsselt gespeichert
und danach nicht mehr angezeigt.

## Anfrage

```
POST <Ziel-URL>
Content-Type: application/json
X-Errstack-Event: notification
X-Errstack-Delivery: 4711
X-Errstack-Timestamp: 1785062400
X-Errstack-Signature: v1=6f1c…
```

| Kopfzeile | Bedeutung |
|---|---|
| `X-Errstack-Event` | Art der Meldung. Zurzeit immer `notification`. |
| `X-Errstack-Delivery` | Kennung des Zustellversuchs. Ein Wiederholungsversuch trägt **dieselbe** Kennung — daran erkennt man ihn. |
| `X-Errstack-Timestamp` | Zeitpunkt der Zustellung (Unix-Sekunden). Teil der Unterschrift. |
| `X-Errstack-Signature` | Die Unterschrift, siehe unten. |

Rumpf:

```json
{
  "event": "notification",
  "organization": { "slug": "acme", "name": "Acme" },
  "channel": { "id": 12, "name": "Bereitschaft" },
  "delivery": { "id": 4711, "test": false },
  "message": {
    "title": "TypeError in Kasse",
    "body": "Cannot read properties of undefined",
    "level": "error",
    "url": "https://errstack.example.com/issues/1234",
    "context": { "Projekt": "Kasse", "Umgebung": "produktiv" },
    "reference": "KASSE-1234",
    "occurredAt": "2026-08-06T21:00:00+00:00"
  }
}
```

`level` ist `info`, `warning` oder `error`. `url`, `reference` und `occurredAt`
können fehlen (`null`).

## Unterschrift prüfen

Unterschrieben wird die Zeichenkette `<Zeitstempel>.<Rumpf>` mit HMAC-SHA256 und
dem Geheimnis des Kanals; das Ergebnis steht hexadezimal hinter dem Präfix `v1=`.

Der **rohe** Rumpf muss geprüft werden — nicht ein wieder eingelesenes und neu
serialisiertes JSON, sonst geht die Rechnung nicht auf.

PHP:

```php
$timestamp = (int) $request->header('X-Errstack-Timestamp');
$expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

// Zeitkonstanter Vergleich, damit die Unterschrift nicht Zeichen für Zeichen
// erraten werden kann.
abort_unless(hash_equals($expected, (string) $request->header('X-Errstack-Signature')), 403);

// Alte Zustellungen abweisen: der Zeitstempel gehört zur Unterschrift, ein
// abgefangener Aufruf lässt sich damit nicht beliebig lange wiederholen.
abort_if(abs(time() - $timestamp) > 300, 403);
```

Node:

```js
const crypto = require('node:crypto');

const timestamp = req.headers['x-errstack-timestamp'];
const digest = crypto
    .createHmac('sha256', secret)
    .update(`${timestamp}.${rawBody}`)
    .digest('hex');

const ok = crypto.timingSafeEqual(
    Buffer.from(`v1=${digest}`),
    Buffer.from(req.headers['x-errstack-signature']),
);
```

## Antworten und Wiederholungen

Alles im Bereich 2xx gilt als angenommen. Bei jeder anderen Antwort — und bei
einer Zeitüberschreitung (10 Sekunden) — versucht Errstack es erneut: fünf
Versuche mit wachsendem Abstand (10 s, 1 min, 5 min, 15 min). Danach steht die
Zustellung im Protokoll als fehlgeschlagen und lässt sich dort von Hand
wiederholen.

Der Empfänger sollte deshalb schnell antworten und die eigentliche Arbeit
nachgelagert erledigen — und `X-Errstack-Delivery` benutzen, um eine doppelt
eintreffende Zustellung zu erkennen.
