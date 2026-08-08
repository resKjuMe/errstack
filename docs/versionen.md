# Versionen (Releases)

Nach jeder Auslieferung kommt dieselbe Frage: **war das schon vorher so?** Ohne
eine Antwort darauf ist ein Fehlerbericht eine Beobachtung ohne Bezug — man
sieht, dass etwas kaputt ist, aber nicht, ob man es gerade selbst kaputt gemacht
hat.

Diese Aufgabe erfasst deshalb, in welcher ausgelieferten Version ein Fehler
zuerst und zuletzt auftrat, und führt die Versionen als eigene Liste.

```
ProcessIngestPayload
  └ ProcessingPipeline
      ├ … (Entpacken, Filter, Scrubbing, Normalisieren, Gruppieren)
      ├ Fehler-Eintrag fortschreiben   (I6)
      └ Version erfassen  ◀── hier
```

## Wo die Version herkommt

Aus der Meldung selbst: SDKs schicken sie im Feld `release`. Die Angabe steht
bereits an jedem Ereignis (`events.release`), seit die Meldungen vereinheitlicht
werden — hier wird aus ihr ein Gegenstand.

Zwei Wege führen zu einer Zeile in `releases`:

1. **Von selbst**, sobald eine Meldung eine bisher unbekannte Version mitbringt.
   Das ist der Regelfall und der Grund, warum die Liste nie gepflegt werden muss.
2. **Angekündigt** über die Schnittstelle
   (`POST /api/0/organizations/{org}/projects/{projekt}/releases`). Dafür gibt es
   einen eigenen Weg, weil die Reihenfolge im Betrieb umgekehrt ist: erst wird
   ausgeliefert, dann kommt (hoffentlich lange) keine Meldung. Wer beim
   Ausliefern Bescheid gibt, hat die Version in der Liste, bevor der erste Fehler
   daraus eintrifft — samt Zeitpunkt der Auslieferung, den aus einer Meldung
   niemand ableiten kann.

Der Endpunkt ist **wiederholbar**: dieselbe Version noch einmal anzulegen ist
kein Fehler, sondern eine Ergänzung. Er steht in einer Auslieferungs-Pipeline,
und die läuft bei einem Fehlschlag noch einmal.

Auch Transaktionen (Antwortzeiten) bringen ihre Version mit. Eine Auslieferung,
aus der bislang nur Messungen eintrafen, ist eine erfolgreiche Auslieferung und
gehört genauso in die Liste.

**Ohne Angabe entsteht nichts.** Eine Meldung ohne `release` gehört zu keiner
Version; ein Ersatzwert („unbekannt") wäre eine erfundene Auslieferung, die sich
später von einer echten nicht mehr unterscheiden ließe.

## Die Versionsangabe bleibt eine Zeichenkette

Was ein SDK schickt, ist nicht verhandelbar: `1.4.2`, `v2.0.0-rc.1`,
`mein-dienst@3.1.0`, ein Commit-Hash, der Zählerstand der Bauumgebung. Nichts
davon wird umgeschrieben.

Zerlegt wird trotzdem, **daneben** (`App\Support\Releases\Version`) — denn ohne
Ordnung ist eine Versionsliste unbrauchbar: als Text sortiert steht `1.10.0` vor
`1.9.0`. Die Zerlegung landet in vier Spalten (`sort_major`, `sort_minor`,
`sort_patch`, `sort_prerelease`) und wird nur beim Anlegen geschrieben — sie
hängt allein an der Versionsangabe, und die ist der Schlüssel.

Erkannt werden ein vorangestelltes Paket (`mein-dienst@1.2.3`), ein führendes
`v`, ein bis drei Zahlen, ein Vorabteil hinter `-` und ein Bauteil hinter `+`.
Der Bauteil zählt nach der Spezifikation nicht zur Rangfolge und wird verworfen.

Was sich nicht zerlegen lässt, bekommt **keine** erfundene Ordnung, sondern
Nullwerte. In der Liste steht es hinter den nummerierten Versionen, nach Zeit
sortiert, und ist als „ohne Rangfolge" gekennzeichnet — sonst sähe die Sortierung
kaputt aus.

Die Sortierung ist damit vierstufig: zerlegbar vor unzerlegbar, dann die Zahlen
absteigend, dann endgültige Fassungen vor ihren Vorabversionen (`1.0.0` über
`1.0.0-rc.1`), zuletzt die Zeit.

## Was am Fehler steht

Am Fehler-Eintrag stehen zwei Verweise: `first_release_id` („zuerst gesehen in")
und `last_release_id` („zuletzt aufgetreten in"), jeweils mit einem eigenen
Zeitstempel daneben.

Warum eigene Zeitstempel und nicht `first_seen`/`last_seen`? Weil die beiden für
**alle** Meldungen des Eintrags stehen, die neuen nur für die **mit
Versionsangabe**. Ein SDK, das die Version erst seit gestern mitschickt, hat
einen Eintrag, der seit Wochen läuft und dessen erste bekannte Version von
gestern ist — gegen `first_seen` verglichen käme nie eine erste Version
zustande.

Der zweite Grund ist die Fortschreibung: Meldungen kommen **nicht** in ihrer
zeitlichen Reihenfolge an. Ein SDK, das nach einer Netztrennung seine
Warteschlange leert, liefert Stunden später Ereignisse von vorhin. Eine
nachgereichte alte Meldung darf die zuletzt betroffene Version nicht
zurückdrehen — wohl aber die zuerst betroffene nach vorn holen.

Fortgeschrieben wird deshalb mit `case when` in **einer** Anweisung und ohne
Sperre, genau wie die Zähler am Eintrag (I6): bei einem Ausfall tragen alle
gleichzeitig verarbeiteten Meldungen dieselbe Version, und damit wäre genau
diese eine Zeile die, auf die jeder Arbeiter schreiben will.

## Die Versionsliste

`/versionen` zeigt je Version, wie viele Fehler **neu** dazugekommen sind und wie
viele davon inzwischen **erledigt** sind. Beide Zahlen kommen aus einer Abfrage
über `issues.first_release_id`, nicht aus einer je Zeile.

„Erledigt" heißt hier: von den in dieser Version neu aufgetretenen Fehlern sind
so viele inzwischen erledigt. **Nicht** „in dieser Version behoben" — dafür
bräuchte es den Vermerk, in welcher Auslieferung jemand einen Fehler geschlossen
hat, und den gibt es erst mit dem Bearbeiten von Fehlern (S6). Bis dahin wäre die
Aussage geraten, und eine geratene Zahl in einer Auslieferungs-Übersicht ist
schlimmer als keine: sie sieht aus wie eine Messung.

Der Zeitraum der Filterleiste wirkt über die **Spanne** einer Version (erstes bis
letztes Auftreten), nicht über einen einzelnen Zeitpunkt — dieselbe Überlegung
wie bei den Fehler-Einträgen. Eine angekündigte Version hat noch keine Spanne;
für sie zählt der Zeitpunkt der Auslieferung, ersatzweise der ihrer Ankündigung.
Ohne diesen zweiten Zweig wäre sie ausgerechnet am Tag der Auslieferung nicht in
der Liste.

Die gewählte **Umgebung wirkt nicht**: eine Version wird als Ganzes ausgeliefert
und gehört nicht zu einer Umgebung. Die Seite sagt das, statt die Auswahl still
zu übergehen.

## Suchen

In der Fehlerliste wirken zwei Begriffe:

| Begriff | Bedeutung |
|---|---|
| `firstRelease:1.0.0` | zum ersten Mal in dieser Version gesehen |
| `release:1.0.0` | in dieser Version gesehen (erste **oder** letzte) |

Mehrere Werte desselben Begriffs sind ein Oder, verschiedene Begriffe ein Und.
Werte mit Leerzeichen stehen in Anführungszeichen. Der Schlüssel wird ohne
Rücksicht auf Groß- und Kleinschreibung erkannt, der Wert genau genommen —
`1.0.0-RC1` ist nicht `1.0.0-rc1`.

`release:` hat eine **Grenze**: erfasst sind nur die erste und die letzte
Version. Ein Fehler, der in `1.0.0` begann, in `1.1.0` weiterlief und in `1.2.0`
zuletzt auftrat, wird von `release:1.1.0` nicht gefunden. Die vollständige
Antwort bräuchte je Eintrag und Version eine Zeile — eine Tabelle in der
Größenordnung der Ereignisse, und die ist diese Auskunft nicht wert.

Das ist ausdrücklich der **Anfang** der Suchsprache und nicht die Sprache selbst:
`is:unresolved`, `browser:`, Klammern und Verneinung kommen mit S4. Bis dahin
werden unbekannte Begriffe **nicht** stillschweigend übergangen, sondern über der
Liste genannt — sonst sähe sie aus, als hätte sie den Begriff ausgewertet.

## Was hier nicht steht

- **Gesundheit und Verbreitung** einer Version (wie viele Sitzungen und Nutzer
  mit ihr abstürzen, wie schnell sie sich ausbreitet) sind Fragen an die
  Sitzungsdaten — R7.
- **Quellkarten und die Rückübersetzung minimierter Stacktraces** stehen in
  [quellkarten.md](quellkarten.md) — R5. Die Artefakte hängen an einer Version;
  was mit ihnen geschieht, ist eine eigene Frage.
- **Commits und Repositories** an einer Version — R2. Die Spalte `ref` steht
  dafür schon bereit und bleibt bis dahin leer.
- **Wann eine Version wohin ausgeliefert wurde** steht in
  [auslieferungen.md](auslieferungen.md) — R3. `released_at` hier ist die eine
  angekündigte Zeit; eine Version geht nacheinander in mehrere Umgebungen.
- **Detailseite und Vergleich zur Vorversion** — R8.
