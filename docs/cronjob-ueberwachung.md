# Cronjob-Überwachung

Ein nächtlicher Import, der nicht läuft, fällt normalerweise erst dann auf, wenn
jemand die fehlenden Daten sucht — oft Tage später. Die Cronjob-Überwachung
dreht das um: der Job meldet sich bei jedem Lauf, und **das Ausbleiben** dieser
Meldung ist das Ereignis.

Eingerichtet wird sie unter *Projekt › Cronjobs*.

## Was überwacht wird

| Zustand | Wann |
|---|---|
| **verpasst** | Zeitplan plus Toleranzfenster sind verstrichen, ohne dass sich der Job gemeldet hat. |
| **zu lange gelaufen** | Der Job hat begonnen und nach Ablauf der erlaubten Laufzeit kein Ende gemeldet. |
| **gescheitert** | Der Job hat sich selbst als gescheitert gemeldet (`status=error`). |
| **in Ordnung** | Der letzte Lauf ist durchgelaufen. |

Verpasste und zu lange laufende Ausführungen stellt ein Artisan-Befehl fest, der
im Zeitplan der Anwendung minütlich läuft:

```
php artisan crons:sweep
```

Er ist die einzige Stelle, an der ein *ausgebliebener* Job überhaupt auffallen
kann. Läuft der Zeitplan der Anwendung nicht (`schedule:work` in der Entwicklung
bzw. ein Minuten-Cron auf `schedule:run` auf dem Server), meldet die Überwachung
still nichts mehr.

## Zeitplan

Zwei Formen, die sich nicht ineinander überführen lassen:

* **Cron-Ausdruck** — `0 2 * * *`, fest im Kalender. Ein ausgefallener Lauf
  verschiebt den nächsten Termin nicht.
* **Abstand** — „alle 15 Minuten", gezählt ab dem letzten Lauf.

Dazu gehört immer eine **Zeitzone**: „täglich 02:00" ohne sie ist keine Angabe.
Gerechnet wird in der Zeitzone des Jobs, nicht in der des Servers — dadurch
verschiebt sich ein nächtlicher Lauf zur Zeitumstellung eben nicht.

## Check-in per HTTP

Der einfachste Weg, ohne SDK und ohne Rumpf — eine Zeile am Ende des Jobs:

```bash
curl -fsS "https://errstack.example/api/1/cron/nightly-import/<public_key>/"
```

Die vollständige Adresse steht auf der Cronjob-Seite zum Kopieren bereit (nur
für die, die auch die DSN sehen dürfen — sie enthält den öffentlichen
Schlüssel).

Mit Laufzeitmessung, wenn der Job Beginn und Ende melden soll:

```bash
CHECK_IN_ID=$(openssl rand -hex 16)
BASE="https://errstack.example/api/1/cron/nightly-import/<public_key>/"

curl -fsS "$BASE?status=in_progress&check_in_id=$CHECK_IN_ID"

if ./import.sh; then
  curl -fsS "$BASE?status=ok&check_in_id=$CHECK_IN_ID"
else
  curl -fsS "$BASE?status=error&check_in_id=$CHECK_IN_ID"
fi
```

| Parameter | Bedeutung |
|---|---|
| `status` | `in_progress`, `ok` oder `error`. Ohne Angabe gilt `ok`. |
| `check_in_id` | 32 Hex-Zeichen. Über sie findet die Abschluss-Meldung zu ihrem Beginn zurück — ohne sie wären es zwei getrennte Ausführungen. |
| `duration` | Laufzeit in Sekunden. Sie hat Vorrang vor der gerechneten: der Job weiß es besser als wir aus zwei Anfragen. |
| `environment` | Umgebung, etwa `production`. |

Die Antwort ist immer `202` — auch dann, wenn sich kein Monitor zuordnen ließ.
Ein Fehlercode aus der Überwachung darf den überwachten Job nicht aus dem Tritt
bringen; ob der Check-in angekommen ist, steht im Feld `accepted`.

## Check-in per SDK

Sentry-SDKs schicken Lebenszeichen als `check_in`-Element im Envelope. Das
funktioniert unverändert, sobald in der DSN dieser Host steht:

```php
\Sentry\captureCheckIn(
    slug: 'nightly-import',
    status: \Sentry\CheckInStatus::ok(),
    monitorConfig: new \Sentry\MonitorConfig(
        \Sentry\MonitorSchedule::crontab('0 2 * * *'),
        checkinMargin: 15,
        maxRuntime: 60,
        timezone: 'Europe/Berlin',
    ),
);
```

Bringt der Check-in ein `monitor_config` mit, **entsteht die Überwachung beim
ersten Lauf von selbst** — der Zeitplan steht ohnehin im Code, und ihn ein
zweites Mal in eine Oberfläche zu tippen hieße, dass beide Stellen
auseinanderlaufen. Bei jedem weiteren Lauf wird nachgezogen, was die
Konfiguration nennt; was sie nicht nennt, bleibt so, wie es in der Oberfläche
eingestellt wurde.

Ohne `monitor_config` muss der Monitor vorher angelegt sein. Ein Check-in auf
eine unbekannte Kennung wird protokolliert und verworfen — nicht abgewiesen: er
steckt meist in einem Envelope mit weiteren Elementen, und die sollen ankommen.

## Alarm und Entwarnung

Ein Ausfall geht über dieselben Kanäle raus wie jeder andere Alarm (siehe
*Organisation › Benachrichtigungen*). Zwei Schwellen steuern, wann:

* **Alarm nach … Fehlschlägen** — ein Job, der einmal in der Woche an einer
  trägen Gegenstelle scheitert, soll niemanden wecken. `1` heißt: sofort.
* **Entwarnung nach … Erfolgen** — ohne diese zweite Schwelle meldet ein Job,
  der zwischen Erfolg und Fehlschlag pendelt, im Wechsel Alarm und Entwarnung.

Solange eine Störung gemeldet ist, kommt kein zweiter Alarm; erst die Entwarnung
setzt das zurück.

## Kennung

Die Kennung (`slug`) entsteht beim Anlegen aus dem Namen und **bleibt danach
fest** — auch wenn der Monitor umbenannt wird. Sie steht im Code des Jobs; ließe
sie sich ändern, hörten die Check-ins von da an auf anzukommen, und zwar
unbemerkt: ein Monitor ohne Check-ins sieht genauso aus wie ein Job, der nicht
läuft.

## Wartung

Ein abgeschalteter Monitor bleibt samt Verlauf stehen, stellt aber nichts mehr
fest — der Weg für eine geplante Wartung. Beim Wiedereinschalten wird der Termin
neu gesetzt: der Zeitplan hat in der Zwischenzeit weitergezählt, und ohne das
käme sofort eine Reihe verpasster Läufe.
