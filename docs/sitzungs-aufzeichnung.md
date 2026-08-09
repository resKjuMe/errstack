# Sitzungs-Aufzeichnung (Replay)

Ein Stacktrace sagt, **wo** es geknallt hat. Die Breadcrumbs sagen, was die
Anwendung zuletzt gemeldet hat. Beide lassen dieselbe Frage offen:

> Wie ist der Nutzer überhaupt dorthin gekommen?

Bisher blieb dafür Raten oder Nachfragen bei jemandem, der sich an den vorletzten
Klick nicht erinnert. Diese Aufgabe macht daraus einen abspielbaren Film der
Sitzung — mit Zeitleiste, Klick-, Konsolen- und Netzwerkspur und Sprungmarken zu
den Fehlern, die dabei passiert sind.

```
Browser (SDK)                         Errstack
─────────────                         ────────
Sentry.replayIntegration()
  ├ replay_event      (Kopfdaten) ─┐
  └ replay_recording  (Bilddaten) ─┤
                                   └─▶ /envelope/ ──▶ ingest_payloads
                                                          │
                                                    ProcessIngestPayload
                                                          │
                                                     RecordReplay
                                                       ├ replays          (Zeile)
                                                       ├ replay_segments  (Zeilen)
                                                       ├ Platte           (Bilddaten, gzip)
                                                       └ replay_errors    (Verknüpfung)

Fehlermeldung mit contexts.replay ──▶ LinkEventReplay ──▶ replay_errors
```

## Was aufgezeichnet wird — und was nicht

Die Aufnahme selbst bauen wir nicht. Sie steckt im Browser-SDK (rrweb), und die
Wiedergabe übernimmt dieselbe Bibliothek (`rrweb-player`). Was hier entsteht, ist
alles dazwischen: Annehmen, Zusammensetzen, Ablegen, Abspielen, Verknüpfen.

Eine Aufzeichnung besteht aus zwei Arten von Envelope-Elementen:

| Element            | Inhalt                                              | Form              |
| ------------------ | --------------------------------------------------- | ----------------- |
| `replay_event`     | Kopfdaten: Nutzer, Seiten, Umgebung, Fehler-Nummern | JSON              |
| `replay_recording` | Der Film: rrweb-Ereignisse                          | Kopfzeile + gzip/zlib |

Beide tragen dieselbe Nummer (die `event_id` des Envelope) — darüber finden sie
zueinander. **Welches zuerst ankommt, ist offen**: sie werden als zwei
unabhängige Jobs verarbeitet. Wer zuerst da ist, legt die Zeile an; der andere
findet sie vor. Anders als beim Sample-Profil (M4) braucht es dafür keinen
Vorsprung — hier kann jede Hälfte für sich anfangen.

## Datenschutz

**Maskiert wird im Browser, nicht hier.** Das ist keine Bequemlichkeit, sondern
der einzige Ort, an dem es etwas nützt: was einmal gesendet wurde, ist gesendet,
und ein Server, der Eingaben nachträglich schwärzt, hat sie vorher
entgegengenommen. Die Maskierung des SDK ersetzt Texte und Eingabefelder, bevor
ein Abschnitt den Rechner des Nutzers verlässt.

Diese Anwendung tut daran dreierlei:

1. **Die Einrichtungs-Anleitung zeigt die Aufnahme nur mit Maskierung.** Wer den
   Ausschnitt aus dem Assistenten kopiert, bekommt den sicheren Fall und muss die
   Maskierung ausdrücklich abschalten, statt sie ausdrücklich einzuschalten:

   ```js
   Sentry.replayIntegration({
       maskAllText: true,
       maskAllInputs: true,
       blockAllMedia: true,
   });
   ```

2. **Der Zustand wird festgehalten und angezeigt.** Das Replay-SDK legt seine
   Einstellungen als eigenes Ereignis in den ersten Abschnitt; daraus liest
   `ReplayTimeline::maskingFrom()`, ob maskiert wurde. Eine unmaskierte
   Aufzeichnung trägt auf der Abspielseite einen deutlichen Hinweis, statt
   unbemerkt zu bleiben. Fehlt die Angabe (ältere SDKs), gilt „maskiert" — eine
   Warnung, die immer leuchtet, wird nicht gelesen.

3. **Der Betreiber kann darauf bestehen.** `REPLAYS_REQUIRE_MASKING=true` weist
   Abschnitte ab, die sich als unmaskiert ausweisen. Standardmäßig aus, weil
   ältere SDKs die Angabe nicht mitschicken und ihre Aufzeichnungen sonst wortlos
   verschwänden.

Der Schalter „Anhänge nicht speichern" greift **nicht** für Aufzeichnungen. Eine
Aufzeichnung ist kein Anhang zu einer Meldung, sondern ein eigener Bestand mit
eigenem Datenschutzweg; beides über einen Schalter zu entscheiden hieße, einem
Projekt wortlos die Aufzeichnungen abzuschalten und niemandem zu sagen, warum die
Abspielseite leer bleibt.

## Getrennt gespeichert, getrennt gelöscht

Das ist die Zusage aus der Aufgabe, und sie ist wörtlich gemeint.

- Die Bilddaten liegen **nicht** in der Datenbank, sondern auf einem Laufwerk
  (`config('replays.disk')`), gepackt, unter
  `replays/<projekt>/<aufzeichnung>/<abschnitt>.json.gz`. Die Aufzeichnung ist ein
  eigener Ordner, weil genau sie die Einheit des Löschens ist.
- Die Verknüpfung zu einem Fehler führt eine **Ereignis-Nummer** und keinen
  Fremdschlüssel. Damit hängt kein Bestand am anderen: Ereignisse dürfen früher
  weggeräumt werden, Aufzeichnungen dürfen es auch — beides in beliebiger
  Reihenfolge.
- Die Aufbewahrungsfrist ist **eigenständig** und steht je Projekt in den
  Datenschutz-Einstellungen (`projects.replay_retention_days`). Leer heißt „die
  Vorgabe des Betreibers" (`REPLAYS_RETENTION_DAYS`, 30 Tage), **`0` heißt „gar
  nicht aufzeichnen"** — ankommende Abschnitte werden dann verworfen und gezählt,
  statt abgelegt und später weggeräumt.

Durchgesetzt wird die Frist stündlich von `replays:sweep`. Derselbe Durchlauf
räumt Ordner von Projekten weg, die es nicht mehr gibt: eine Kaskade in der
Datenbank erreicht kein Laufwerk.

```
php artisan replays:sweep --dry-run    # zeigt, was wegfiele
php artisan replays:sweep              # räumt weg
```

## Grenzen

Eine Aufzeichnung kann nicht unbegrenzt wachsen. Drei Grenzen, die verschiedene
Fälle abfangen (alle in `config/replays.php`):

| Einstellung              | Vorgabe | Wogegen                                       |
| ------------------------ | ------- | --------------------------------------------- |
| `max_segments`           | 1200    | Dauer — eine über Nacht offene Registerkarte   |
| `max_total_bytes`        | 100 MB  | Menge — eine Seite mit ständiger Bewegung      |
| `max_events_per_segment` | 20000   | Dichte — eine Animation, die Mausbewegungen meldet |

**Verworfen wird der Abschnitt, nicht die Sitzung.** Der interessante Teil einer
Aufzeichnung ist ihr Anfang; eine Sitzung wegzuwerfen, weil sie zu lange lief,
hieße genau den Teil zu verlieren, dessentwegen jemand hinsieht. Der Film endet
dann eben früher.

## Wege zur Aufzeichnung

Der Regelweg führt **von einem Fehler**, nicht über die Liste:

- Die Fehlerdetailseite zeigt die Aufzeichnungen, in denen genau diese Meldung
  passiert ist.
- `replays.event` (`…/fehler/{issue}/ereignisse/{event}/aufzeichnung`) springt
  direkt hin und landet in der Liste, wenn es keine gibt — ein Link soll nicht
  davon abhängen, ob die Aufnahme gerade lief.
- Die Übersicht `…/aufzeichnungen` ist für den seltenen Fall ohne konkreten
  Anlass; ihr einziger Filter ist „nur Sitzungen mit Fehlern".

Die Verknüpfung entsteht **von beiden Seiten**, weil beide Lücken haben: die
Kopfdaten einer laufenden Sitzung kennen den Fehler von eben noch nicht, und
nicht jedes SDK trägt jeden Fehler in seine Aufzeichnung ein. Kommt ein Fehler
vor seiner Aufzeichnung an, wird eine Zeile ohne Abschnitte angelegt — ein Anker
für die Verknüpfung. Sie taucht in keiner Liste auf (`Replay::scopePlayable()`)
und füllt sich, sobald die Aufnahme nachkommt.

## Abspielen

Die Seite steht sofort: Kopfdaten und Sprungmarken sind klein und kommen mit der
Antwort. Die Bilddaten holt der Browser danach von `replays.data` — als
Datenstrom, der die Abschnitte zu einer Liste zusammenschiebt, ohne dass
irgendwo eine Zeichenkette in Sitzungsgröße entsteht. Am Ende derselben Antwort
stehen die ausgelesenen Spuren; sie sind erst hinter dem letzten Abschnitt
fertig.

Die Spuren werden **serverseitig** gedeutet (`ReplayTimeline`). Welcher Eintrag
eine Netzwerkanfrage ist und was als Konsolenzeile gilt, ist Auslegung — und
Auslegung gehört auf die Seite, die sich prüfen lässt.

## Wenn nichts ankommt

Aufgezeichnet wird nur ein Teil der Sitzungen; das SDK hat dafür eine eigene
Quote (`replaysSessionSampleRate`). Dass es zu einem Fehler keine Aufzeichnung
gibt, ist deshalb der Normalfall und kein Mangel. Bleibt die Liste dauerhaft
leer, lohnt der Blick auf:

1. Ist `Sentry.replayIntegration()` in der überwachten Anwendung eingebunden?
2. Steht `replaysOnErrorSampleRate` über null?
3. Steht die Aufbewahrungsfrist des Projekts auf `0`? Dann wird bewusst nichts
   abgelegt.
4. `php artisan ingest:status` zeigt, ob Elemente vom Typ `replay_recording`
   ankommen und ob sie verworfen werden.
