# Release-Gesundheit

Fehlerzahlen sagen, **dass** etwas kaputt ist. Sie sagen nicht, **wie viele
Menschen davon überhaupt etwas merken**. Zweihundert Abstürze sind eine
Katastrophe, wenn zweihundert Leute die Anwendung benutzen, und ein Randfall,
wenn es zweihunderttausend sind.

Diese Aufgabe liefert die Zahl, die diese Frage beantwortet: **wie viele
Sitzungen und wie viele Nutzer einer Version absturzfrei geblieben sind** — und
wie schnell sich die neue Version überhaupt verbreitet.

```
ProcessIngestPayload
  └ ProcessingPipeline
      ├ … (Entpacken, Filter, Scrubbing)
      ├ Sitzungen zählen  ◀── hier
      └ Version erfassen  (R1)
```

## Was eine Sitzung ist

Eine Sitzung ist ein Lauf der überwachten Anwendung: vom Start bis zum Ende, vom
Öffnen der App bis zum Schließen, von der ersten bis zur letzten Anfrage eines
Aufrufs. Das SDK meldet sie mit — nicht als Fehler, sondern als Buchhaltung: „es
läuft" und später „es ist gut gegangen" oder „es ist abgestürzt".

Sie kommt auf zwei Wegen an, und beide sind hier zu bedienen:

**Einzeln** (`session`), wie es Anwendungen auf Endgeräten tun. Eine Sitzung, eine
Nummer (`sid`), mehrere Meldungen im Lauf ihres Lebens.

**Gebündelt** (`sessions`), wie es Server-SDKs tun. Keine Nummern, sondern
fertige Zahlen je Minute: „41 beendet, 2 mit Fehlern, 1 abgestürzt". Ein
Webserver hat pro Sekunde mehr Anfragen als eine App am Tag Starts — jede davon
einzeln zu melden wäre teurer als das, was gemessen werden soll.

## Die Zusage: keine Sitzung wird doppelt gezählt

Das ist der Kern und der Grund für den größten Teil des Aufwands. Ein SDK meldet
dieselbe Sitzung mehrfach:

```
{"sid":"a1","init":true,"status":"ok","seq":0}      ← beim Start
{"sid":"a1","status":"crashed","seq":7}             ← beim Absturz
```

Wer beide Meldungen addiert, hat zwei Sitzungen, von denen eine noch läuft und
eine abgestürzt ist — die Crash-Free-Rate fiele damit auf 50 %, obwohl **eine**
Sitzung abgestürzt ist. Deshalb gibt es zu jeder einzeln gemeldeten Sitzung eine
Zeile (`release_sessions`), die sich merkt, mit welchem Ausgang sie zuletzt
gezählt wurde. Die zweite Meldung addiert nicht, sondern **verrechnet**: minus
eine laufende, plus eine abgestürzte.

Und weil Meldungen sich unterwegs überholen, entscheidet nicht die Ankunft,
sondern die Folgenummer (`seq`) des SDK: eine ältere Meldung, die später
eintrifft, wird verworfen. Ohne das machte ein verspätetes „läuft" einen bereits
gezählten Absturz wieder rückgängig.

Für Bündel entfällt beides — dort gibt es keine Nummern und damit auch keine
Zwischenstände. Wer bündelt, hat sie schon selbst verrechnet.

## Wo die Zahlen liegen

| Tabelle | Eine Zeile je … | Beantwortet |
|---|---|---|
| `release_sessions` | einzeln gemeldeter Sitzung | „Was war ihr letzter Ausgang?" |
| `release_session_counts` | Version, Umgebung, Minute | „Wie viele Sitzungen, wie viele Abstürze?" |
| `release_session_users` | zusätzlich je Nutzer | „Wie viele **Menschen** stecken dahinter?" |

Die dritte Tabelle ist nicht bequem, sondern nötig: **aus Sitzungssummen lässt
sich die Zahl der Betroffenen nicht herleiten.** Fünfhundert abgestürzte
Sitzungen können fünfhundert verärgerte Menschen sein oder einer in einer
Neustart-Schleife. Gezählt wird deshalb über den Schlüssel
(`count(distinct user_key)`), und die Kennung ist gehasht — hier wird gezählt und
nie angezeigt.

Die Rasterung ist die Minute, dieselbe wie bei den Antwortzeiten. Gröber ginge
nicht: die Schwellwert-Alarme rechnen über Fenster von wenigen Minuten, und bei
stundenweiser Ablage träfe ein solches Fenster meist gar keine Zeile.

## Die Kennzahlen

**Crash-Free-Rate (Sitzungen)** — Anteil der Sitzungen ohne Absturz. Gezählt
werden nur Abstürze, nicht Fehler und nicht Abbrüche: „absturzfrei" soll dasselbe
heißen wie überall sonst.

**Crash-Free-Rate (Nutzer)** — dieselbe Frage über Menschen. Die wichtigere der
beiden und die, die seltener dasteht: sie braucht eine Nutzerkennung in den
Meldungen.

**Verbreitung (Adoption)** — der Anteil der Menschen auf dieser Version an allen,
die im Zeitraum unterwegs waren. Wer in einem Zeitraum zwei Versionen benutzt —
vor und nach dem Update —, zählt in beiden; die Anteile addieren sich deshalb
nicht zwingend auf hundert.

**Vergleich zur Vorversion** — „99,2 % absturzfrei" allein sagt niemandem, ob die
Auslieferung gut war. Erst „vorher waren es 99,8 %" macht daraus eine Aussage.
Welche Version die vorherige ist, entscheidet **dieselbe Rangfolge wie die
Versionsliste** ([versionen.md](versionen.md)) — eine zweite Vorstellung von
„davor" ergäbe einen Vergleich, den niemand nachvollziehen kann.

Alle vier gibt es nur, wenn etwas dahintersteht. Ohne Sitzungen ist die Rate
**unbekannt** und nicht „100 %" — eine Version, aus der nichts mehr kommt, ist
nicht die gesündeste.

## Alarme

Die beiden Crash-Free-Raten stehen als Kennzahl für Schwellwert-Alarme bereit
(A3, siehe [benachrichtigungs-einstellungen.md](benachrichtigungs-einstellungen.md)).
Sie werden **über alle Versionen** gerechnet und nicht je Auslieferung: „stürzt
die Anwendung gerade häufiger ab als sonst" ist eine Frage an die Anwendung.
Nach einer schlechten Auslieferung schlägt der Alarm trotzdem an — deren
Sitzungen sind genau die, die den Gesamtwert nach unten ziehen.

Ein solcher Alarm wird auf **fallende** Werte gestellt (`unter 99 %`) und nicht
wie die übrigen auf steigende.

## Was hier bewusst nicht passiert

**Keine Sitzung ohne Version.** Eine Sitzung, deren Meldung keine Versionsangabe
trägt, wird verworfen und gezählt. Sie einer Ersatzversion zuzuschlagen hieße,
eine Auslieferung zu erfinden, die es nie gab — und die sich später von einer
echten nicht mehr unterscheiden ließe.

**Keine Umbuchung.** Version, Umgebung und Zeitfenster einer Sitzung stehen mit
ihrer ersten Meldung fest; spätere Meldungen ändern nur noch den Ausgang. Eine
Sitzung gehört zu der Version, in der sie **begonnen** hat — sonst stünde in
einem Zeitfenster ein Absturz mehr, als es dort Sitzungen gibt.

**Keine Darstellung.** Die Übersicht und die Detailseite mit Verlauf und
Vergleich sind R8; hier stehen die Zahlen, aus denen sie gebaut wird.
