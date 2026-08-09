# Freie Auswertung (Discover)

Die fest gebauten Seiten beantworten die Fragen, die jemand vorher gestellt hat:
welche Fehler häufen sich, welche Seite ist langsam, welche Auslieferung ist
schlecht. Die Frage, die dazwischen liegt — „welcher Browser hat eigentlich diese
eine Fehlermeldung?", „wie lange braucht der Checkout für Kunden aus einem
bestimmten Land?" —, hat dort keinen Platz und wird heute mit einer Abfrage in
der Datenbank beantwortet.

Die freie Auswertung ist die Stelle, an der sich solche Fragen ohne Datenbank
stellen lassen: Quelle, Gruppierung, Kennzahlen, Suchbedingung, Sortierung — und
die Antwort als Tabelle **und** Diagramm.

```
Adresszeile (der ganze Zustand)
        │
        ▼
DiscoverRequest        Quelle, fields[], metrics[], q, sort, limit, interval
        │                 + globale Filterleiste (Projekt, Umgebung, Zeitraum)
        ▼
DiscoverQuery ──────────► DiscoverEngine (D1)
        │  ohne Schrittweite → Tabelle
        │  mit  Schrittweite → Zeitreihe (dieselben Gruppen)
        ▼
DiscoverData           Spalten, Zeilen, Linien, Katalog
        ├──► discover/Index.jsx      Tabelle + Diagramm
        ├──► DiscoverDrilldown       Zeile → Ereignisse dahinter
        └──► CSV                     genau diese Spalten, genau diese Zeilen
```

## Tabelle und Diagramm sind eine Abfrage

Beide entstehen aus **demselben** `DiscoverQuery`, einmal ohne und einmal mit
Schrittweite. Der Motor bestimmt die Linien der Zeitreihe nicht neu, sondern
liest zuerst die Rangliste und fragt die Reihe dann für genau diese Gruppen ab —
deshalb ist die Summe einer Linie die Zahl, die in der Tabelle steht.

Am Diagramm gibt es daher nur **eine** Einstellung: welche der angeforderten
Kennzahlen gezeichnet wird. Alles andere daran zu ändern hieße, eine zweite
Auswertung neben der ersten zu führen — und zwei Ansichten derselben Frage, die
sich widersprechen, sind schlimmer als eine.

Das Diagramm zeigt höchstens `max_series_groups` Linien (Standard: 10); die
Tabelle zeigt so viele Zeilen, wie die Abfrage verlangt. Steht die Reihe damit
gekürzt da, sagt sie es unter dem Diagramm.

## Genau ein Projekt

Eine Auswertung läuft über ein Projekt. Das ist keine Bequemlichkeit, sondern die
Folge der Grenzen aus D1: Zeit, Zeilenzahl und Stützstellen gelten **je
Abfrage**. Über drei Projekte gerechnet wären es drei Abfragen, und die Zusage an
alle übrigen Leser der Datenbank gälte für keine davon. Dazu kommt, dass sich die
Kennzahlen hinterher nicht zusammenzählen ließen: aus „p95 = 400 ms" und
„p95 = 900 ms" folgt kein gemeinsames p95, und eine Zufriedenheit ist erst recht
kein Mittelwert von Mittelwerten.

Nennt die Filterleiste nicht genau ein Projekt, zeigt die Seite deshalb keine
geratene Auswahl, sondern die Bitte, eines zu wählen — mit den verfügbaren
Projekten als Links daneben.

## Die Umgebung ist eine Suchbedingung

Der Motor kennt genau einen Weg, eine Auswertung einzuschränken: die Suchsprache
aus S4. Die Umgebung der Filterleiste wird deshalb als `environment:…` an die
getippte Bedingung gehängt und nicht als zweiter Parameter geführt — zwei Wege,
eine Auswertung enger zu machen, könnten sich widersprechen.

Kennt eine Quelle das Feld nicht (die Rückmeldungen etwa: eine Rückmeldung
schreibt ein Mensch, keine Umgebung), steht es als „hat nichts eingeschränkt"
unter der Tabelle, statt stillschweigend zu wirken oder stillschweigend zu
fehlen.

## Von einer Zeile zu den Ereignissen

Der Pfeil am Ende einer Zeile führt zu den Ereignissen, aus denen sie entstanden
ist — **aber nur, wenn die Zielansicht genau dieselbe Menge zeigen kann**
(`DiscoverDrilldown`):

| Quelle | Ziel | Übersetzung |
|---|---|---|
| Fehlermeldungen | Fehlerliste | wörtlich: dieselbe Suchsprache (S4) |
| Aufrufe (Messungen und Fenster) | Leistungs-Übersicht | nur `name` und `op` — mehr kennt deren Suche nicht |
| Rückmeldungen | Rückmeldungsliste | nur ohne Gruppierung; die Liste hat keine Suche |

Wo sich eine Gruppe nicht vollständig übersetzen lässt — ein Gruppenwert fehlt,
oder das Feld gibt es im Ziel nicht —, bleibt die Zeile **ohne** Link. Ein Link
auf „ungefähr diese Zeilen" wäre der, nach dem hinterher niemand mehr weiß, warum
die Zahlen nicht zusammenpassen.

## Die Ausgabe als Datei

`/organisationen/{org}/auswertung/csv` ist dieselbe Adresse mit denselben
Parametern. Die Datei enthält deshalb genau die Spalten und genau die Zeilen, die
daneben auf dem Bildschirm stehen — einschließlich der Zeilenzahl: wer 50 Zeilen
sieht und 1000 exportierte, bekäme eine andere Antwort auf dieselbe Frage.

Die Kopfzeile nennt die Einheit (`p95 (duration) [µs]`), die Zahlen stehen roh in
der Schreibweise der gewählten Sprache. Trennzeichen ist das Semikolon, davor
steht eine Byte-Order-Mark — dieselbe Entscheidung wie beim Änderungsprotokoll,
und aus demselben Grund: sonst zeigen Tabellenprogramme aus „ä" zwei Zeichen.

## Wenn eine Abfrage abgelehnt wird

Eine Grenze ist keine Fehlermeldung, sondern eine Auskunft: Die Seite nennt die
Grenze und den verlangten Wert und bleibt bedienbar, sodass sich die Abfrage an
Ort und Stelle ändern lässt.

Tabelle und Diagramm werden dabei getrennt behandelt. Die Zahl der Stützstellen
ist eine Grenze, die **nur** die Zeitreihe betrifft — eine Schrittweite von einer
Minute über dreißig Tage sind über vierzigtausend Punkte. Das nimmt der Tabelle
aber nicht ihre Antwort: dann steht das Diagramm aus, und die Auswertung bleibt.
