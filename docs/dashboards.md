# Dashboards

Die freie Auswertung beantwortet **eine** Frage auf einmal. Die Fragen, die man
morgens stellt, sind aber immer dieselben fünf — wie viele Fehler, wen trifft es,
wie schnell ist die Anwendung, was hat die neue Fassung mitgebracht —, und sie
nacheinander zusammenzusuchen heißt, jeden Morgen fünfmal dasselbe zu tippen.

Ein Dashboard ist die Antwort darauf: eine benannte Sammlung von Kacheln, jede
mit ihrer eigenen Abfrage, frei im Raster angeordnet.

```
dashboards/Show.jsx  ── liefert das Raster (Kacheln, Lage, Abfragen)
        │
        ├── Kachel 1 ──► GET …/kacheln/1/daten ──┐
        ├── Kachel 2 ──► GET …/kacheln/2/daten ──┤  nebeneinander,
        ├── …                                    ├─ nicht nacheinander
        └── Kachel n ──► GET …/kacheln/n/daten ──┘
                              │
                              ▼
                         WidgetData
                              │  WidgetQuery + WidgetOverrides + Filterleiste
                              ▼
                         DiscoverEngine (D1)
                              │  Verlauf → Zeitreihe · Rest → Rangliste
                              ▼
                         SeriesView · TableView · NumberView · MapView
```

## Eine Kachel speichert ihre Abfrage, nicht ihre Daten

Das ist die Zusage, an der alles hängt. Was ein Dashboard zeigt, wird bei jedem
Aufschlagen neu gerechnet — es ist so aktuell wie die Zahl daneben in der freien
Auswertung. Läge in der Datenbank ein Ergebnis, wäre ein Dashboard eine Sammlung
von Momentaufnahmen mit unbekanntem Alter, und die erste Frage vor jeder Zahl
wäre „von wann ist das".

Gespeichert wird deshalb genau das, was in der Adresszeile der freien Auswertung
steht: Quelle, Gruppierung, Kennzahlen, Suchbedingung, Sortierung, Zeilenzahl,
Schrittweite (`WidgetQuery`). Es ist dieselbe Beschreibung und keine zweite —
sonst gäbe es zwei Stellen, an denen „was ist eine Abfrage" beantwortet wird.

Gelesen wird sie **nachsichtig**: was aus der Datenbank kommt, ist Monate alt,
und ein Feld kann fortgefallen sein. Eine Kachel, die sich deswegen nicht mehr
öffnen lässt, wäre die schlechtere Antwort — unbekannte Angaben fallen heraus,
und ob die Abfrage *rechenbar* ist, entscheidet weiterhin allein der Motor.

Weil es dieselbe Beschreibung ist, lässt sich eine **gespeicherte Auswertung**
(D3) mit einem Klick als Kachel übernehmen: sie bekommt eine Kopie der Frage,
keinen Verweis. Ihr gespeicherter Zeitraum geht dabei nicht mit — hier gilt die
Filterleiste des Dashboards (siehe unten). Die Einzelheiten stehen in
[Freie Auswertung](auswertung.md).

## Zwanzig Kacheln, zwanzig Anfragen

Die Aufgabe verlangt, dass ein Dashboard mit zwanzig Kacheln ohne merkliche
Verzögerung lädt. Erreicht wird das nicht dadurch, dass die Abfragen schneller
werden, sondern dadurch, dass sie **nebeneinander** laufen:

* Die Seite liefert das Raster — Kacheln, Anordnung, Abfragen. Sie steht sofort.
* Jede Kachel holt ihre Zahlen über eine eigene Adresse. Der Browser stellt die
  zwanzig Anfragen gleichzeitig; jede Kachel füllt sich, sobald ihre da ist.
* Der Zwischenspeicher des Motors (D1) greift dabei wie überall.

Eine einzige Antwort mit allen zwanzig Ergebnissen wäre serverseitig eine
Schleife: zwanzig Abfragen hintereinander, und der Bildschirm bliebe so lange
leer wie ihre Summe dauert. Deshalb beantwortet `WidgetData` **eine** Kachel und
nimmt keine Liste.

Eine abgelehnte Abfrage ist dabei eine Auskunft und kein Loch: die betroffene
Kachel sagt mit Grenze und verlangtem Wert, warum nicht gerechnet wurde, die
übrigen neunzehn stehen unverändert da.

## Die Filterleiste gilt — außer die Kachel sagt etwas anderes

Zeitraum, Umgebung und Projekt gehören der globalen Filterleiste, wie bei den
gespeicherten Suchen (S6). Wer ein Dashboard über den letzten 30 Tagen des
Webshops aufschlägt, sieht seine Kacheln über den letzten 30 Tagen des Webshops.

Die Ausnahme ist ausdrücklich und liegt **an der Kachel** (`WidgetOverrides`):
sie darf Zeitraum, Umgebung und Projekt einzeln für sich überschreiben. Ohne das
ließe sich „letzte Stunde neben letzten 30 Tagen" nicht auf einen Bildschirm
bringen — und das ist der halbe Zweck eines Dashboards. Einzeln, nicht gemeinsam:
eine Kachel darf „immer letzte 7 Tage" sagen und beim Projekt trotzdem mitgehen.

Weicht eine Kachel ab, **steht das an ihr**. Ohne diesen Vermerk wäre sie die
gefährlichste Zahl auf dem Bildschirm: sie steht neben Kacheln, die etwas
anderes meinen, und sieht genauso aus.

## Das Raster

Gezählt wird in Rasterfeldern und nicht in Pixeln: zwölf Spalten, feste
Zeilenhöhe. Ein Dashboard hat damit auf einem schmalen Bildschirm dieselbe
*Anordnung* und nicht dieselbe *Größe* — gespeicherte Pixel wären die Auflösung
dessen, der die Kachel zuletzt geschoben hat.

Beim Ziehen wird die Strecke in Felder umgerechnet und die Kachel dorthin gelegt;
was im Weg liegt, weicht nach unten aus, und darüber wird aufgerückt
(`layout.js`). Der Bildschirm zeigt während des Ziehens also schon die Anordnung,
die gespeichert wird. Gespeichert wird beim Loslassen — eine Anordnung ist kein
Formular —, und zwar für alle Kacheln in **einem** Aufruf: eine Bewegung im
Raster ist eine Bewegung und nicht zehn.

Ohne Maus geht es ebenfalls: der Griff in der Ecke ist ein Knopf, die Pfeiltasten
verschieben, Umschalt + Pfeiltasten ändern die Größe.

Der Server prüft jede Lage und rückt sie ins Raster (`DashboardLayout`) — nicht
weil die Oberfläche es falsch machte, sondern weil sie nicht die einzige Quelle
ist: Vorlagen legen Kacheln an, ein Duplikat übernimmt sie. Überlappungen löst er
dagegen **nicht** auf: das Raster ist keine Physik-Engine, und ein serverseitiges
Auseinanderschieben änderte die Anordnung hinter dem Rücken dessen, der sie
gerade gelegt hat.

## Darstellungsarten

| Art | Frage | Abfrage |
|---|---|---|
| Linie, Fläche, Balken | wie hat es sich entwickelt | Zeitreihe |
| Tabelle | welche sind die größten | Rangliste |
| Große Zahl | wie viel ist es gerade | Rangliste, eine Zeile |
| Weltkarte | wo sitzen die Betroffenen | Rangliste, nach Land gruppiert |

Die Art entscheidet also, **welche** Frage der Motor bekommt — nicht bloß, wie
das Ergebnis aussieht. Beides zu holen und die Hälfte wegzuwerfen wäre auf einem
Bildschirm mit zwanzig Kacheln die doppelte Arbeit.

Die große Zahl und die Weltkarte sind dabei keine Sonderfälle der Tabelle,
sondern Tabellen mit einer Erwartung an ihre Form: die große Zahl zeigt die erste
Kennzahl der ersten Zeile, die Weltkarte braucht ein Länderkürzel als
Gruppierung (`geo.country` bei den Fehlermeldungen, `country` bei den
Antwortzeiten). Wo die Erwartung nicht erfüllt ist, sagt die Kachel das, statt
ersatzweise etwas anderes zu zeigen.

Die Karte ist eine **Blasenkarte auf einem schematischen Umriss** und keine
Landvermessung: eingefärbte Länder setzten Grenzverläufe voraus, und die wären
Geodaten in der Größenordnung der ganzen Anwendung — auf jeder Seite, die sie
einmal zeigt. Eine Blase am Ort des Landes braucht zwei Zahlen und beantwortet
dieselbe Frage. Länderkürzel, die die Ortsliste nicht kennt, stehen als
Aufzählung unter der Karte; sie wegzulassen hieße, eine Karte zu zeigen, deren
Summe kleiner ist als die Zahl daneben.

## Vorlagen, Duplikate, Freigabe

Drei Vorlagen sind vorhanden — Fehlerübersicht, Performance, Release-Gesundheit.
Eine Vorlage ist ein **Startpunkt und keine Bindung**: angelegt werden ganz
normale Kacheln, und eine später geänderte Vorlage rührt die bereits angelegten
nicht an. Der Herkunftsvermerk sagt nur, woher es kam.

Freigeben heißt sehen, nicht ändern — dieselbe Regel wie bei der gespeicherten
Suche. Ein freigegebenes Dashboard, das unter demselben Namen morgen andere
Kacheln zeigt, wäre schlimmer als keines: es sieht vertraut aus. Wer ein fremdes
als Ausgangspunkt braucht, **dupliziert** es; das Duplikat gehört danach ihm und
ist nicht freigegeben.

Auf die Daten hat all das keinen Einfluss. Eine Kachel ist eine Frage; welche
Zahlen sie zutage fördert, entscheidet nach wie vor die Projektauswahl des
Betrachters. Ein freigegebenes Dashboard über einem Projekt, in dem jemand nicht
ist, liefert ihm schlicht nichts.

## Grenzen

| Grenze | Wert | Grund |
|---|---|---|
| Kacheln je Dashboard | 40 | zwanzig sollen flüssig sein; das Doppelte ist Luft |
| Dashboards je Konto und Organisation | 50 | die Liste soll sich überblicken lassen |
| Zeilen, Zeitfenster, Laufzeit je Abfrage | siehe `config/discover.php` | die Zusage des Motors an die übrigen Leser der Datenbank |

Wer mehr Kacheln braucht, braucht ein zweites Dashboard — und die gibt es
beliebig viele.
