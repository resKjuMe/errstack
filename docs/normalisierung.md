# Normalisierung eingehender Meldungen

Was ein SDK schickt, ist ein loses Versprechen. Das Sentry-Schema lässt für
fast jedes Feld mehrere Schreibweisen zu, die SDKs nutzen unterschiedliche
davon, ältere Fassungen schicken Felder, die es nicht mehr gibt — und eine
Anwendung, die gerade abstürzt, füllt manches gar nicht.

Die Normalisierung ist die Stelle, an der das endet. Danach steht in jedem Feld
ein geprüfter Wert oder `null`, und alles Weitere — Gruppierung (I5),
Fortschreibung (I6), Suche, Anzeige, Benachrichtigungen — muss nie wieder
wissen, wie ein bestimmtes SDK etwas schreibt.

```
ingest_payloads (Rohdaten, unverändert)
        │
        ▼  Verarbeitungskette, Schritt „Normalisierung"
   EventNormalizer
        │
        ▼
   events (einheitliches Modell)
```

Der Schritt ist `App\Support\Ingest\Processing\Steps\NormalizeEvent`. Er
behandelt **nur** Fehler und Nachrichten (`IngestType::Event`); Transaktionen
kommen in P5 mit eigenem Schritt, alles andere wird durchgereicht.

## Drei Zusagen

**Nichts geht verloren, was zu retten war.** Felder, für die es kein Fach gibt,
landen unter `unknown` statt im Papierkorb. Sentry erweitert sein Schema
laufend; ein heute weggeworfenes Feld fehlt rückwirkend, sobald wir es morgen
auswerten wollen. Die Rohdaten lägen zwar noch da — aber ein erneuter Durchlauf
über Monate alter Meldungen ist eine ganz andere Aufgabe, als eine neue Spalte
zu lesen.

**Ein kaputter Abschnitt kostet nur diesen Abschnitt.** Eine Anfrage, die als
Zeichenkette statt als Objekt kam, wird verworfen und vermerkt; der Stacktrace
daneben bleibt. Alles andere hieße: je unbrauchbarer die Anwendung, desto
weniger erfahren wir über sie — und das ist die Anwendung, für die jemand hier
nachsieht.

**Was gekürzt wurde, steht dabei.** Ein abgeschnittener Stacktrace sieht aus wie
ein kurzer, ein weggelassener Abschnitt wie einer, den das SDK nie geschickt
hat. Beides schickt den Suchenden an die falsche Stelle. Deshalb trägt jede
Meldung ihre Notizen:

```json
"notes": {
  "truncated": ["exception.0.value", "breadcrumbs"],
  "invalid":   ["request", "user.ip_address"]
}
```

Vermerkt wird der **Pfad**, nie der Wert: der wäre entweder zu groß (deshalb
wurde er gekürzt) oder ungültig (deshalb wurde er verworfen). Steht in `notes`
nichts, heißt das genau: unverändert übernommen.

## Was vereinheitlicht wird

| Abschnitt | Eingang | Ergebnis |
|---|---|---|
| Zeitpunkt | Sekunden seit 1970 (mit Bruchteilen) oder ISO 8601 | `occurred_at`; fehlt er, gilt der Eingang der Meldung |
| Grad | `error`, `critical`, `warn`, `WARNING` … | `EventLevel`; unbekannt → `error` |
| Plattform | frei, an die vierzig Werte | kleingeschriebene Zeichenkette; fehlt sie → `other` |
| Ausnahme | `{"values": […]}` oder nackte Liste | Liste, **älteste Ursache zuerst** |
| Stacktrace | an der Ausnahme oder daneben | Rahmen an der Ausnahme, älteste zuerst |
| Meldungstext | `message` als Text oder Objekt, `logentry` | `formatted` + `template` + `params` |
| Anfrage | `query_string` als Text, Objekt oder Paarliste | durchweg Objekt |
| Marken | Objekt oder Paarliste | Objekt |
| Spuren | `{"values": […]}` oder nackte Liste | Liste, **älteste zuerst** |
| Kontexte | frei benannte Fächer | unverändert, `type` ergänzt; `trace` mit festen Feldern |

### Reihenfolgen

Sie sind die eigentliche Information und werden **nie** angetastet:

- **Ausnahmen**: älteste Ursache zuerst, zuletzt geworfene Ausnahme zuletzt. Die
  Überschrift kommt von der letzten — das ist die, die die Anwendung gesehen
  hat. Wer die Liste umdreht, vertauscht Ursache und Wirkung.
- **Stapelrahmen**: ältester zuerst, Fehlerstelle zuletzt.
- **Spuren**: zeitlich aufsteigend, die letzte ging dem Fehler voraus.

Daraus folgt, wo gekappt wird: bei Ausnahmen und Rahmen **hinten**, bei Spuren
**vorn**. Beide Male fällt das Unwichtigere weg — aber es liegt an
verschiedenen Enden.

### Überschrift und Verortung

`title` und `culprit` werden hier gebildet und nicht bei der Anzeige: sie
stehen auch in E-Mail-Betreffs, Chat-Nachrichten und Suchergebnissen, und
dreimal dieselbe Ableitung wäre dreimal die Gelegenheit, sie unterschiedlich zu
machen.

- `title` — `Typ: Text` der zuletzt geworfenen Ausnahme, sonst der Meldungstext.
- `culprit` — was die Anwendung angibt (`culprit`, sonst `transaction`); fehlt
  beides, der **letzte Rahmen aus eigenem Code** (`in_app`). Bei einer Ausnahme
  aus zweihundert Rahmen ist der Rahmenwerks-Code darüber für niemanden die
  Antwort auf „wo".

`in_app` wird dabei nie geraten. Fehlt die Angabe, bleibt sie `null` und heißt
„unbekannt" — ein geratenes `false` würde den Rahmen verstecken, in dem der
Fehler sitzt.

## Grenzen

Die Aufnahme kennt nur eine Grenze: wie groß eine Meldung im Ganzen sein darf.
Das genügt nicht — eine erlaubte Meldung von knapp einem Megabyte darf nicht aus
einem einzigen Fehlertext von einem Megabyte bestehen, denn der wird in Listen,
Suchergebnissen und Benachrichtigungen wieder ausgepackt. Die Werte stehen in
`config/ingest.php` unter `normalization.limits`:

| Schlüssel | Vorgabe | Wofür |
|---|---|---|
| `string_chars` | 8192 | Zeichen je Textfeld |
| `source_line_chars` | 512 | Zeichen je Zeile Quelltext |
| `exceptions` | 25 | verschachtelte Ursachen |
| `frames` | 250 | Stapelrahmen je Stacktrace |
| `context_lines` | 10 | Quelltextzeilen um die Fehlerstelle |
| `threads` | 25 | Ausführungsstränge |
| `breadcrumbs` | 100 | Spuren |
| `entries` | 100 | Einträge je Schlüssel-Wert-Abschnitt |
| `depth` | 5 | Verschachtelungstiefe in freien Abschnitten |

Gezählt werden **Zeichen, nicht Bytes**: eine Grenze in Bytes schneidet ein
Mehrbyte-Zeichen mitten durch, und die kaputte Bytefolge bringt danach das
Ablegen zu Fall — die Kürzung, die Schaden verhindern soll, hätte selbst welchen
angerichtet.

## Die Ablage

Die ausgewertete Meldung steht in `events`, das Original unverändert daneben in
`ingest_payloads` (`ingest_payload_id`). Das ist die Zusage, auf der die ganze
Kette beruht: wird an einem Schritt etwas geändert, lässt sich alles erneut
auswerten.

Die Aufteilung der Spalten folgt einer Regel: **wonach gefiltert und sortiert
wird, bekommt eine eigene Spalte, alles andere ein JSON-Fach.** Grad,
Zeitpunkt, Umgebung und Fassung stehen in jeder Liste — die in ein JSON-Feld zu
legen hieße, für jede Fehlerliste die ganze Tabelle zu lesen. Stacktrace,
Anfrage und Spuren dagegen werden immer nur für **eine** Meldung geöffnet,
nämlich die gerade angesehene.

Ein zweiter Durchlauf **ersetzt** den Datensatz (`unique` auf
`ingest_payload_id`). Ein zweiter daneben wäre schlimmer als gar keiner: er
stünde doppelt in jeder Zählung, und welcher gilt, wüsste niemand.

## Was hier nicht passiert

**Kein Entfernen personenbezogener Daten.** Das ist das Scrubbing (I7) und steht
in der Kette **davor** — was hier ankommt, darf gespeichert werden. Die
Normalisierung sorgt nur dafür, dass der Schritt davor weiß, wo er suchen muss:
Kekse in `request.cookies`, Kopfzeilen in `request.headers`, örtliche Variablen
in den Rahmen unter `vars`.

**Kein Aussortieren.** Eine Meldung, von der nur der Meldungstext lesbar ist,
wird als Meldung mit Meldungstext abgelegt — mit einem Vermerk, was verworfen
wurde. Ausgesondert wird nur, was gar keine Nutzdaten hat, und das entscheidet
schon das Entpacken.
