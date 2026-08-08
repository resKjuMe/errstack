# Verträglichkeit mit den echten Sentry-SDKs

Der Klon ist nur dann etwas wert, wenn eine überwachte Anwendung nichts an sich
ändern muss, um hierher zu melden — **eine getauschte DSN, sonst nichts**. Kein
eigenes Paket, kein Patch, keine Zusatzeinstellung.

Dieser Ordner ist der Nachweis dafür und hat zwei Teile:

- **Beispiele** je SDK, die von Hand gegen eine laufende Installation gefahren
  werden können. Sie zeigen den Nachweis und dienen zugleich als Vorlage für
  jemanden, der eine eigene Anwendung anschließt.
- **Aufnahmen** dessen, was die SDKs dabei geschickt haben. Gegen sie prüft
  `Tests\Feature\Ingest\SdkCompatibilityTest` bei jedem Lauf, damit eine
  Änderung an der Datenaufnahme die Verträglichkeit nicht unbemerkt bricht.
  Der Unterschied ist wichtig: alle anderen Tests der Datenaufnahme schicken
  Meldungen, die wir selbst geschrieben haben — und prüfen damit auch unsere
  Vorstellung davon, wie ein SDK meldet.

## Die Beispiele

| Ordner            | SDK                     | Fassung | schickt                                 |
| ----------------- | ----------------------- | ------- | --------------------------------------- |
| `sentry-php/`     | `sentry/sentry`         | 4.30.0  | Nachricht, Fehler, Transaktion          |
| `sentry-node/`    | `@sentry/node`          | 10.69.0 | Nachricht, Fehler, Transaktion, Sitzung |
| `sentry-browser/` | `@sentry/browser`       | 10.69.0 | Nachricht, Fehler, Transaktion, Sitzung |
| `sentry-python/`  | `sentry-sdk`            | 2.66.1  | Nachricht, Fehler, Transaktion, Sitzung |
| — (siehe unten)   | `sentry/sentry-laravel` | 4.27.0  | Fehler, über die Selbstüberwachung      |

Alle vier Meldungsarten kommen zusammen vor, keine fehlt: Sitzungen liefern
Node, Browser und Python — das PHP-SDK kennt keine (Release Health ist in
`sentry/sentry` nicht umgesetzt, ein PHP-Prozess lebt für eine Anfrage).

Die Beispiele sind absichtlich alle gleich gebaut: dieselbe Ursachenkette,
dieselben Kennzeichen, dieselben Werte für `release` und `environment`. So sagt
ein Unterschied im Ergebnis etwas über das SDK und nicht über das Beispiel.

Festgeschrieben ist keine der Fassungen (kein `package-lock.json`, keine
gepinnte `requirements.txt`): `npm install` und `pip install` sollen hier die
**heutige** Fassung des SDK holen, denn das ist die Frage, die diese Beispiele
beantworten. Die Aufnahmen, gegen die der Test läuft, sind dagegen fest — sie
nennen die Fassung, aus der sie stammen.

## Nachweis fahren

Zuerst den Klon starten und eine DSN besorgen:

```bash
composer dev                       # Server auf http://localhost:8000
```

Die DSN steht in der Oberfläche beim Projekt (Format:
`http://<public_key>@localhost:8000/<projekt-nummer>`). Sie geht in jedes
Beispiel als `SENTRY_DSN` hinein — mehr wird an keiner Stelle eingestellt:

```bash
export SENTRY_DSN="http://<public_key>@localhost:8000/1"

php docs/compat/sentry-php/senden.php

cd docs/compat/sentry-node && npm install && npm run senden

cd docs/compat/sentry-python
python -m venv .venv && .venv/Scripts/pip install -r requirements.txt
.venv/Scripts/python senden.py      # Linux/macOS: .venv/bin/…
```

Das Browser-Beispiel ist eine Seite: `docs/compat/sentry-browser/index.html`
über einen beliebigen Webserver öffnen und die DSN als `?dsn=…` anhängen (ohne
Angabe meldet sie an den Mitschnitt-Server, siehe unten). `?auto=1` schickt
alles ohne Klicken.

`sentry-laravel` braucht kein eigenes Beispiel: der Klon meldet mit diesem SDK
seine eigenen Fehler. `ERRSTACK_DSN` in der `.env` setzen (im Betrieb auf eine
**zweite** Installation, für die Probe genügt dieselbe) und einen Fehler
auslösen:

```bash
ERRSTACK_DSN="$SENTRY_DSN" php artisan tinker \
    --execute="report(new RuntimeException('Probe')); \Sentry\flush();"
```

### Was dabei herauskam

Lauf vom 2026-08-07 gegen den Klon auf `127.0.0.1:8010`, fünf SDKs, jedes mit
unveränderter Voreinstellung und nur getauschter DSN:

```
8 Ereignisse   8 Issues   5 Transaktionen   9 Sitzungen   0 verworfen
```

- Jeder Fehler kam mit **vollständiger Ursachenkette** an (zwei Glieder), beide
  Glieder mit Stacktrace, dazu `release`, `environment`, Kennzeichen, Nutzer und
  Verlauf. Der Titel nennt Art und Meldung der Ausnahme in der Schreibweise der
  jeweiligen Sprache (`RuntimeException: …`, `RuntimeError: …`, `Error: …`), der
  Ort steht daneben.
- Jede Transaktion kam mit Namen, Dauer und ihren Einzelschritten an — beim
  Seitenaufruf im Browser mit neun, beim Skript in Node mit zwei.
- Sitzungen kamen als Sitzungen an, mit Kennung, Zustand und Release.
- **Nichts wurde verworfen**: kein unbekannter Element-Typ, keine unlesbare
  Meldung, kein abgeschnittener Ablauf.

Wiederholt wird der Lauf nicht bei jeder Änderung — dafür sind die Aufnahmen da.

## Aufnahmen

Unter `tests/Fixtures/Compat/` liegt je SDK und Art der Envelope, den das SDK
wirklich geschickt hat:

```
tests/Fixtures/Compat/aufnahmen.json          Verzeichnis: Pfad, Abfrageteil, Kopfzeilen
tests/Fixtures/Compat/<sdk>/<art>.envelope    der Envelope im Klartext
```

Der Envelope liegt **im Klartext**, auch wenn das SDK ihn gepackt geschickt hat;
gepackt wird er im Test wieder, wenn die Kopfzeile es sagt. Der Grund ist die
Nachvollziehbarkeit: ein Envelope ist zeilenweises JSON und lässt sich so lesen
und vergleichen, ein gzip-Block nicht. Der Weg über die Verpackung bleibt trotzdem
Teil des Tests.

Der öffentliche Schlüssel in den Aufnahmen ist ein Platzhalter
(`aaaa…aaaa`) — eine Aufnahme enthält niemals einen echten Schlüssel. Der Test
setzt ihn und die Projektnummer auf das Projekt um, das er selbst anlegt; der
Rumpf bleibt Byte für Byte, wie er kam.

### Neu aufnehmen

Nötig, wenn ein SDK eine neue Fassung hat oder eine Art dazukommt. Der
Mitschnitt-Server nimmt dabei die Stelle des Klons ein und schreibt jede Anfrage
unverändert weg — vor jeder Auswertung:

```bash
php -S 127.0.0.1:9911 docs/compat/aufnahme/server.php   # Fenster 1

# Fenster 2: alle Beispiele OHNE SENTRY_DSN, dann meldet jedes an den Mitschnitt
php docs/compat/sentry-php/senden.php
(cd docs/compat/sentry-node && npm run senden)
(cd docs/compat/sentry-python && .venv/Scripts/python senden.py)
# Browser: http://127.0.0.1:9911/sentry-browser/index.html?auto=1
#   — die Seite liefert derselbe Server aus, damit Seite und Empfänger
#     dieselbe Herkunft haben. Der Seitenaufruf als Transaktion braucht ein
#     paar Sekunden, bevor er abgeht.

php docs/compat/aufnahme/sortieren.php                  # → tests/Fixtures/Compat/
php artisan test --filter=SdkCompatibilityTest
```

`sortieren.php` sortiert nach dem, was in der Anfrage steht (`sentry_client` und
die Kopfzeilen der Elemente), nicht nach dem, was beim Aufnehmen gedacht war —
ein später hinzugekommenes SDK braucht dort keine Zeile. Von mehreren Aufnahmen
derselben Art gewinnt die letzte: SDKs schicken dieselbe Sitzung mehrfach, und
erst die letzte trägt den Abschluss.

Beim Anheben einer SDK-Fassung gehören die Fassungsangaben in der Tabelle oben,
in `package.json`, `requirements.txt` und im `<script src>` der Browser-Seite mit
nachgezogen.

## Abweichungen zur Sentry-Spezifikation

Alles hier Genannte betrifft den Klon; kein Punkt verlangt eine Änderung an einem
SDK.

**Endpunkte.** Angenommen werden `POST /api/{projekt}/store/`,
`POST /api/{projekt}/envelope/` und die Lebenszeichen unter
`/api/{projekt}/cron/{monitor}/{key}`. Sentry hat darüber hinaus `/security/`
(CSP- und Expect-CT-Berichte des Browsers), `/minidump/`, `/unreal/` und
`/nel/` — diese Wege fehlen und antworten mit `404`. Betroffen ist, wer Berichte
ohne SDK direkt vom Browser schickt (`report-uri` in der Content-Security-Policy)
oder Abstürze nativer Anwendungen meldet.

**Element-Typen ohne Auswertung.** Angenommen und unverändert abgelegt, aber
noch nicht ausgewertet werden `session`, `sessions` (Release Health),
`attachment`, `replay_event`, `replay_recording`, `profile` und `user_report`.
Für ein SDK sieht das aus wie Erfolg — es meldet weiter und wiederholt nichts —,
in der Oberfläche ist davon aber nichts zu sehen. Ausgewertet werden `event`,
`transaction`, `check_in` und `client_report`.

**Keine Ratenbegrenzung.** Der Klon schickt weder `429` noch
`X-Sentry-Rate-Limits`. SDKs drosseln deshalb nie von selbst: sie richten ihr
Verhalten nach genau dieser Kopfzeile. Bis Kontingente je Projekt und Schlüssel
umgesetzt sind (O1), nimmt eine Installation an, was ihr geschickt wird.

**Nur gzip, deflate und Base64.** `Content-Encoding: br` (Brotli) wird nicht
geöffnet; ein so gepackter Envelope gilt als unlesbar. Keines der hier
geprüften SDKs packt von sich aus mit Brotli, wer es einschaltet, wird aber
abgewiesen.

**Der Envelope-Kopf wird nicht als Zugangsdaten gelesen.** Steht darin eine
`dsn`, bleibt sie unbeachtet; wer meldet, entscheidet allein die Anfrage
(Kopfzeile, Abfrageteil oder Adresse). Für die geprüften SDKs ist das
gleichbedeutend — sie schicken beides passend. Wer einen eigenen Weiterleiter
(„Tunnel") vor den Klon stellt, muss die Zieladresse deshalb selbst richtig
wählen und kann sich nicht darauf verlassen, dass die DSN im Envelope den Weg
weist.

**Sonstiges**, was bei der Aufnahme auffiel und ausdrücklich in Ordnung ist: das
Browser-SDK meldet mit `Content-Type: text/plain;charset=UTF-8` und ohne
`X-Sentry-Auth` (der Schlüssel steht im Abfrageteil), damit der Browser keine
Vorab-Anfrage stellen muss. Beides nimmt der Klon an — siehe
`App\Support\Ingest\IngestAuth`.
