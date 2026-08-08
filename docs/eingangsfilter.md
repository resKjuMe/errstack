# Eingangsfilter

Nicht jede Meldung, die ankommt, ist eine, die jemand sehen will. Eine
Browser-Erweiterung des Besuchers stürzt ab, ein Crawler läuft in eine Route,
die es nicht mehr gibt, eine Entwicklerin testet gegen `localhost` — dreierlei
Rauschen, und alles davon landet ohne Filter in derselben Liste wie der Fehler,
der die Kasse lahmlegt.

Der Eingangsfilter wirft dieses Rauschen weg, **bevor** es gespeichert wird.
Nicht nachträglich: was aussortiert wird, kostet danach keine Normalisierung,
keinen Fingerabdruck, keine Einfügung und keinen Zähler.

```
ProcessIngestPayload
  └ ProcessingPipeline
      ├ Entpacken       Rohdaten → Feld-Baum
      ├ Eingangsfilter  ◀── hier
      ├ Scrubbing
      └ …
```

## Die sieben Arten

Jede ist ein eigener Schalter am Projekt (`App\Enums\InboundFilterKind`). Alle
stehen ab Werk auf **aus**: ein Filter, der von selbst Meldungen verschluckt,
wäre die Sorte Überraschung, die das Vertrauen in ein Fehler-Werkzeug kostet.

| Art | Woran erkannt | Eigene Liste |
|---|---|---|
| Browser-Erweiterungen | Adressschema des Stapelrahmens (`chrome-extension://` …), bekannte Herkünfte, bekannte Fehlertexte | nein |
| Veraltete Browser | `contexts.browser` gegen eine Untergrenze | ja, mit Vorgabe |
| Lokale Entwicklung | Wirt der Adresse, `Host`-Kopfzeile, `server_name` | nein |
| Web-Crawler | User-Agent | nein |
| Fehlermeldungen nach Muster | Meldungstext, Ausnahmetyp, Ausnahmetext | ja |
| Absender-Sperrliste | `user.ip_address`, `request.env.REMOTE_ADDR` | ja |
| Release-Sperrliste | `release` | ja |

Die drei Arten ohne eigene Liste erkennen ihr Rauschen aus einer eingebauten
Liste (`App\Support\Ingest\Filtering\Defaults`). Was eine Browser-Erweiterung
ist, ändert sich nicht je Projekt — stünde es in den Listen, müsste jedes
Projekt dieselben zwanzig Zeilen eintragen.

## Die Einträge

Ein Eintrag ist ein **Platzhalter-Muster**, kein regulärer Ausdruck: die Listen
schreiben die Leute, die die Fehlerliste ansehen, und ein regulärer Ausdruck
wäre hier ein Werkzeug, mit dem sich versehentlich alles wegfiltern lässt.

- `*` steht für beliebig viele Zeichen; verglichen wird der **ganze** Wert.
  `*ResizeObserver*` für einen Teiltreffer — ohne Platzhalter würde eine
  Release-Sperre `1.2` sonst auch `21.2.5` treffen.
- **Adressen** sind davon ausgenommen: dort gilt entweder die genaue Adresse
  oder ein Netz in CIDR-Schreibweise (`203.0.113.0/24`). `203.0.113.*` sieht
  richtig aus und träfe auch `203.0.113.50`; bei IPv6, wo dieselbe Adresse
  mehrere Schreibweisen hat, ginge ein Textvergleich reihenweise daneben. Eine
  IPv4-Adresse im IPv6-Kleid (`::ffff:203.0.113.5`) wird auf ihre IPv4-Form
  zurückgeführt, damit eine Sperre `203.0.113.0/24` auch auf einem Anschluss
  greift, der beide Familien bedient. `/0` ist nicht erlaubt — das wäre der eine
  Eintrag, mit dem sich ein Projekt in einem Zug still stellen ließe.
- **Browser-Grenzen** sind `safari:6` („alles unter Fassung 6") oder `ie` („jede
  Fassung"). Verglichen wird nur die Hauptfassung, und der Name muss dem
  entsprechen, den das SDK meldet — `opera` trifft **nicht** `Opera Mini`, weil
  das ein eigener Browser mit eigener Zählung ist.

Ein Eintrag lässt sich stilllegen, ohne ihn zu löschen. Das ist der Weg, ein
Muster zu prüfen, das im Verdacht steht, zu viel wegzunehmen.

## Was von einer gefilterten Meldung bleibt

Die Zählung. Sie steht in `ingest_discards` mit dem Grund `filtered` und der
**Filterart als Kategorie** — die Filterseite des Projekts liest sie von dort
und zeigt sie neben dem jeweiligen Schalter.

Das ist keine Zugabe, sondern die Gegenseite des Filters: eine gefilterte
Meldung hinterlässt in der Fehlerliste keine Lücke. Ohne die Zahl neben dem
Häkchen wäre nicht zu erkennen, ob ein Filter zwei Meldungen im Monat nimmt oder
die Hälfte des Aufkommens — und „warum fehlt mein Fehler?" wäre nicht zu
beantworten. Die Seite darf deshalb jedes Mitglied ansehen; ändern darf sie die
Verwaltung.

## Grenzen der Absender-Sperrliste

Verglichen wird nur, was **in der Meldung steht**: `user.ip_address` und
`request.env.REMOTE_ADDR`. Beides füllen Server-SDKs; ein Browser-SDK schickt
statt einer Adresse den Platzhalter `{{auto}}` und überlässt es dem Server, die
Verbindungsadresse einzusetzen — was diese Anwendung heute nicht tut. Für
Browser-Meldungen greift die Sperrliste deshalb nicht.

Die weitergereichten Kopfzeilen (`X-Forwarded-For`, `X-Real-IP`) werden
**bewusst** nicht herangezogen. Sie sind frei wählbar: hörte die Sperrliste auf
sie, könnte sich jeder Absender in die Sperre eines anderen schreiben — oder
aus seiner eigenen heraus.

## Was der Filter nicht tut

- **Nachträglich löschen.** Was gespeichert ist, bleibt; das Aufräumen ist
  eigene Arbeit (O2).
- **Anhänge fassen.** Ein Anhang kommt als eigenes Element und trägt nichts,
  woran er seiner gefilterten Meldung zuzuordnen wäre. Gefiltert werden nur
  Fehler und Transaktionen — eine Sitzung, ein Lebenszeichen oder ein
  Client-Report sind keine „Meldungen", und den Client-Report zu filtern hieße
  ausgerechnet die Zählung wegzuwerfen, die erklärt, was ein SDK selbst
  verworfen hat.
- **Das Kontingent zurückgeben.** Eine gefilterte Meldung zählt als verworfen
  und nicht als ausgewertet — sie geht in keine Auswertung des Aufkommens ein.
  Die Anfrage selbst hat der Absender allerdings gestellt, und die Annahme
  (samt der Grenze je Schlüssel) liegt vor dem Filter.
- **Die Rohdaten bereinigen.** Der Filter steht vor dem Scrubbing; eine
  gefilterte Meldung erreicht es nie, ihr Rumpf bleibt also so in
  `ingest_payloads` liegen, wie das SDK ihn schickte — auch bei einem Projekt,
  das Adressen oder Nutzerdaten sonst entfernen lässt. Das ist die Kehrseite
  davon, den Weg zurück offenzuhalten: die Rohdaten sind das Einzige, woraus
  sich eine zu weit gefasste Sperre wieder aufholen ließe. Aufgeräumt wird
  beides zusammen (O2).

## Verwandte Dateien

- `app/Support/Ingest/Filtering/` — Auswertung, ohne Datenbank prüfbar
- `app/Support/Ingest/Processing/Steps/FilterEvent.php` — der Schritt in der Kette
- `app/Support/InboundFilterData.php` — Nutzlast der Filterseite
- `tests/Feature/Ingest/InboundFilterTest.php` — was getroffen wird
- `tests/Feature/Projects/InboundFilterSettingsTest.php` — die Bedienung
