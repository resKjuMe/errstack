# Errstack überwacht Errstack

Die Anwendung meldet ihre eigenen Fehler, Antwortzeiten und Ausfälle an eine
Errstack-Installation — über **dieselben Wege**, die eine fremde Anwendung
nimmt. Es gibt keine Abkürzung ins eigene Datenmodell an der Datenaufnahme
vorbei, und das ist der Punkt der Übung: was hier ankommt, beweist, dass der
Weg funktioniert. Ein Fehler in der Aufnahme fällt damit an der eigenen Haut
auf und nicht erst bei einem fremden Nutzer.

```
Errstack (diese Installation)                     Errstack (Empfänger)
─────────────────────────────                     ────────────────────
PHP-Ausnahmen, Queue-Jobs      ─ sentry-laravel ─▶ /api/{projekt}/envelope
Antwortzeiten, Profile         ─ sentry-laravel ─▶ /api/{projekt}/envelope
Browserfehler, Web Vitals      ─ @sentry/react  ─▶ /api/{projekt}/envelope
CSP-Verstöße                   ─ Browser        ─▶ /api/{projekt}/security
Zeitplan (Lebenszeichen)       ─ HTTP           ─▶ /api/{projekt}/cron/…
```

Alles hängt an **einer** Angabe: `ERRSTACK_DSN`. Rechner, Projekt und
Schlüssel stecken darin, und alle vier Adressen werden daraus abgeleitet
(`App\Support\SelfMonitoring\Dsn`). Ohne DSN meldet nichts — das ist der
Auslieferungszustand und kein Fehler.

## Einrichten

Auf der **empfangenden** Installation:

1. Ein Projekt anlegen (etwa `errstack`) und dessen DSN aus der Projektansicht
   ablesen. Sinnvoll ist eine **zweite** Installation: zeigt der DSN auf
   dieselbe, kann sie ihren eigenen Ausfall nicht melden — genau dann fällt
   auch der Empfänger aus.
2. Einen überwachten Cronjob anlegen (Projekt → Cronjobs) mit der Kennung, die
   unten als `ERRSTACK_SCHEDULE_MONITOR` steht (Vorgabe `zeitplan`), Zeitplan
   „jede Minute". Fehlt er, wird das Lebenszeichen zwar angenommen, aber
   niemandem zugeordnet — und der Ausfall des Zeitplans fällt weiterhin nicht
   auf.

Auf der **meldenden** Installation in die `.env`:

```dotenv
ERRSTACK_DSN=https://<öffentlicher schlüssel>@errstack.example/<projekt>

# Antwortzeiten (Server) und Ladeerlebnis (Browser)
SENTRY_TRACES_SAMPLE_RATE=0.1
ERRSTACK_BROWSER_TRACES_SAMPLE_RATE=0.2

# Profile: welche Code-Stellen die Rechenzeit verbrauchen (braucht `excimer`)
SENTRY_PROFILES_SAMPLE_RATE=0.1

# Lebenszeichen des Zeitplans
ERRSTACK_SCHEDULE_MONITOR=zeitplan
```

Danach `php artisan config:clear` (bzw. `config:cache`), damit die Angaben
greifen.

## Die fünf Wege im Einzelnen

### Fehler und Antwortzeiten (Server)

Das PHP-SDK ist über `Integration::handles()` in `bootstrap/app.php`
eingehängt und meldet Ausnahmen aus Web-Aufrufen, Konsolen-Kommandos und
Queue-Jobs. Mit `SENTRY_TRACES_SAMPLE_RATE` kommen die Antwortzeiten dazu,
samt Einzelschritten für Datenbank, Cache, Views und ausgehende HTTP-Aufrufe.

Die Routen der Datenaufnahme sind davon ausgenommen (`ignore_transactions` in
`config/sentry.php`). Das ist keine Feinheit: zeigt der DSN auf die eigene
Installation, erzeugte sonst jede eingehende Meldung eine Transaktion, die als
Meldung wieder in der Aufnahme landet und die nächste erzeugt.

### Profile

`SENTRY_PROFILES_SAMPLE_RATE` zeichnet innerhalb der gemessenen Aufrufe auf,
welche Funktionen die Rechenzeit verbrauchen (M4). Der Anteil zählt **innerhalb
der Messungen** — ohne Tracing passiert hier nichts. Voraussetzung ist die
PHP-Erweiterung `excimer`; fehlt sie, bleibt es still aus.

### Browser

`resources/js/selfmonitoring.js` richtet `@sentry/react` ein. Die Angaben
kommen als geteilte Inertia-Eigenschaft vom Server (`selfMonitoring`) und nicht
aus einer eingebauten `VITE_…`-Variable: ein Wechsel der Installation ist damit
eine Zeile in der `.env` und kein neuer Build.

Das SDK wird **nachgeladen** und nicht mitgebündelt — ohne eingerichtete
Selbstüberwachung kostet es gar nichts. Mit `ERRSTACK_BROWSER_TRACES_SAMPLE_RATE`
kommt das Ladeerlebnis (Web Vitals) dazu.

Dass die DSN dabei im Quelltext der Seite steht, ist kein Versehen: sie enthält
den **öffentlichen** Schlüssel, der genau dafür gemacht ist. Er erlaubt das
Einliefern von Meldungen und sonst nichts.

### Sicherheitsberichte (CSP)

`ReportSecurityViolations` hängt an jede HTML-Antwort eine
`Content-Security-Policy-Report-Only` mit `report-uri` auf die eigene Aufnahme
(M7). **Report-Only** heißt: die Regel meldet, sie blockiert nicht. Eine
schärfende Richtlinie gehört zur Härtung der Anwendung und nicht zur
Überwachung — der Unterschied ist im Ernstfall der zwischen einer gemeldeten
und einer weißen Seite.

Es ist der einzige Meldeweg ohne SDK: der Browser berichtet von sich aus, auch
wenn von der Seite sonst nichts mehr läuft.

### Zeitplan

Das Lebenszeichen ist die einzige Auskunft, die nicht von selbst entsteht.
Alles andere wird gemeldet, *weil* etwas passiert ist; ein Zeitplan, der nicht
mehr läuft, meldet dagegen gar nichts — und das sieht von außen aus wie „alles
ruhig". Bei dieser Anwendung ist das der schwerwiegendste Fall: an
`schedule:run` hängt `crons:sweep` und damit die Überwachung **aller** fremden
Cronjobs.

Gemeldet wird dreimal je Durchlauf — `in_progress` beim Start, danach `ok` oder
`error`. Erst das Paar aus Start und Ende macht einen *hängenden* Lauf sichtbar.

Fehler beim Melden bleiben beim Melden: ein Zeitplan, der abbricht, weil die
Überwachung nicht erreichbar war, ist schlechter dran als einer, der
unbeobachtet läuft. Sie landen im Log und sonst nirgends.

## Versionen

`SENTRY_RELEASE` sagt, welche Auslieferung läuft — die Angabe, an der die
Versionen (R1) hängen. Ohne sie zählt eine Datei `VERSION` im
Wurzelverzeichnis; im Deploy-Skript genügt:

```bash
git rev-parse --short HEAD > VERSION
```

Die Datei wandert damit mit dem ausgelieferten Stand, statt in einer
Konfiguration zu veralten. Sie wird nicht eingecheckt — eingecheckt wäre sie ab
dem nächsten Commit falsch.

## Nachsehen, ob es ankommt

```bash
# Ein Fehler, der gemeldet werden soll
php artisan tinker --execute="throw new RuntimeException('Selbsttest');"

# Ein Lebenszeichen von Hand
curl -i "https://errstack.example/api/7/cron/zeitplan/<schlüssel>?status=ok"
```

Danach auf der empfangenden Installation in die Fehlerliste bzw. unter
Projekt → Cronjobs sehen. Kommt nichts an, sind die üblichen Ursachen: die
Konfiguration ist noch zwischengespeichert (`config:clear`), der DSN zeigt auf
ein Projekt, dessen Schlüssel gezogen wurde, oder die Queue des Empfängers
läuft nicht — die Aufnahme antwortet mit `202` und wertet im Hintergrund aus.
