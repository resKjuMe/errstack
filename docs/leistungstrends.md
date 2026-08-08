# Leistungstrends (Bruchpunkt-Erkennung in den Antwortzeiten)

Die Übersicht (PF2) sagt, **wie schnell** eine Seite gerade ist. Die
Leistungsprobleme (PF6) sagen, **warum** ein einzelner Ablauf langsam war. Keins
von beidem beantwortet die Frage, an der die meiste Zeit verloren geht: **wann
ist das eigentlich passiert?**

Eine Seite, die von 200 ms auf 900 ms rutscht, funktioniert weiter. Sie meldet
nichts, sie taucht in keiner Fehlerliste auf, und im Wochenvergleich sieht sie
nach drei Wochen wieder völlig normal aus — beide Seiten des Vergleichs liegen
dann längst auf der neuen Höhe. Genau diese Lücke schließt PF7.

```
transaction_aggregates            (PF1, Anzahl + Verteilung je Minute)
        │
        ▼  stündlich: performance:trends
   TrendScan
        ├── TrendSeries      je Transaktion 168 Stundenfenster (2 Abfragen)
        ├── BreakpointScan   Rangsummentest über alle Trennstellen
        ├── TrendCause       die Auslieferung, die zeitlich dazu passt (R3)
        └── TrendNotifier    Verschlechterungen an die Kanäle (A1)
                 │
                 ▼
   transaction_trend_detections   eine Zeile je Transaktion und Richtung
                 │
                 ▼  /leistung/trends
   TrendList                      Liste, Filter, „gesehen"
```

## Der Bruchpunkt wird gesucht, nicht gesetzt

Für jede mögliche Trennstelle im Verlauf werden die Stunden davor mit denen
danach verglichen; die Stelle mit dem deutlichsten Unterschied gewinnt. Das ist
der Unterschied zum Vergleich zweier fester Zeiträume
(`TransactionTrend`, der Pfeil in der Übersicht): der findet nur, was sich
**gegenüber genau dem Vorzeitraum** verschoben hat.

Verglichen werden die **p95-Werte der Stundenfenster**, und zwar mit einem
Rangsummentest (Mann-Whitney-U in der Normalverteilungs-Näherung). Drei Gründe,
alle drei aus dem Auftrag:

| Anforderung | Wie der Test sie erfüllt |
|---|---|
| Robust gegen Ausreißer | Er kennt nur die Reihenfolge der Werte, nicht ihren Abstand. Eine Stunde, in der die Datenbank hustete, ist ein hoher Rang und keine Verdopplung eines Mittelwerts. |
| Kein Alarm bei zu geringer Aussagekraft | Der z-Wert wächst mit der Zahl der Fenster. Aus vier guten und vier schlechten Stunden folgt nicht dasselbe wie aus vierzig und vierzig. |
| Verlässlich trotz grober Klassen | Die Verteilung springt in Verdopplungsschritten, gleiche Perzentile sind der Regelfall. Die Bindungskorrektur rechnet sie heraus; ohne sie wäre der z-Wert systematisch zu groß. |

Der Schwellwert ist mit **4,0** bewusst hoch — deutlich über den üblichen 1,96.
Über alle Trennstellen zu suchen und dann die beste zu nehmen, ist ein
Mehrfachvergleich: schon in einem Verlauf ohne jede Änderung findet man eine
Stelle, an der die Hälften zufällig auseinanderliegen.

## Wann nichts gemeldet wird

Alle Bedingungen müssen zugleich erfüllt sein
(`App\Support\Performance\Trends\BreakpointScan`):

| Bedingung | Wert | Warum |
|---|---|---|
| Messungen je Stundenfenster | 5 | Ein p95 aus drei Messungen ist die größte der drei. Dünnere Fenster gehen gar nicht erst in die Rechnung. |
| Bewertbare Fenster je Seite | 6 | Erst dann ist eine neue Höhe ein Zustand und kein Vorfall. Für den kurzen Ausschlag ist der Schwellwert-Alarm zuständig (A3). |
| Messungen je Seite | 50 | Die Mindestdatenmenge. Sie sichert etwas anderes ab als die Zahl der Fenster: dass die verglichenen Höhen überhaupt etwas bedeuten. |
| Aussagekraft | z ≥ 4,0 | Siehe oben. |
| Änderung | ≥ 20 % | Unterhalb davon ist der Unterschied ein Artefakt der Mischung. |
| Höhe | ≥ 50 ms auf einer Seite | Von 2 ms auf 8 ms ist eine Vervierfachung und trotzdem keine Nachricht. |

## Was die Klassenbreite kostet

Die Verteilung legt Dauern in Klassen ab, die sich je Schritt verdoppeln
(`DurationHistogram`). Zwei Folgen, die man kennen muss:

- Eine Änderung, die **keine** Klassengrenze überschreitet, ist nicht zu sehen.
- Eine, die es tut, wird im ungünstigsten Fall um bis zum Doppelten zu groß
  ausgewiesen.

**Dass** eine Transaktion umgeschlagen ist und **wann**, steht damit fest; „um
wie viel" ist eine Größenordnung und keine Messung. Für die Frage, um
derentwillen die Liste existiert, ist das die richtige Abwägung — dieselbe, die
schon der Pfeil in der Übersicht trifft. Den genauen Verlauf zeigt die
Detailseite (PF3).

## Der Verdächtige

`TrendCause` sucht die Auslieferung, deren Zeitpunkt zwischen der Stunde vor dem
Bruch und dem Ende der Bruchstunde liegt — in **derselben** Umgebung, wie bei
den Markierungen der Verlaufsgrafiken. Das Fenster ist eng mit Absicht: wer weit
genug zurücksieht, findet in einer Anwendung mit täglichen Auslieferungen immer
eine, und eine Zuordnung, die immer eine findet, sagt nichts.

Sie steht als Angabe **neben** dem Bruch und nicht in seiner Überschrift. Ein
zeitlicher Zusammenhang ist kein Beweis — es kann ebenso ein neuer Kunde sein,
ein gekippter Index oder ein Nachbardienst.

## Eine Zeile je Transaktion und Richtung

`transaction_trend_detections` wird **fortgeschrieben**, nicht angehängt: der
stündliche Durchlauf findet denselben Bruch immer wieder, solange er im Rückblick
von einer Woche liegt. Jedes Mal eine Zeile anzulegen hieße, dieselbe
Verschlechterung stündlich zu melden.

Eine **neue** Meldung gibt es, wenn der Bruchpunkt um mehr als sechs Stunden
wandert — dann ist es ein zweiter Umschlag und nicht mehr derselbe. Dabei fallen
auch „gesehen" und „gemeldet" zurück.

Gemeldet werden ausschließlich **Verschlechterungen**, und zwar als Warnung und
nicht als Fehler: die Anwendung tut noch, was sie soll, nur langsamer. Wer das in
derselben Stufe meldet wie einen Ausfall, sorgt dafür, dass beim nächsten Ausfall
niemand mehr hinsieht. Verbesserungen stehen in der Liste, gehen aber nicht
hinaus.

## Nur die Standard-Umgebung

Gerechnet wird je Projekt für `default_environment`. Über alle Umgebungen zu
rechnen wäre das Vielfache an Arbeit für Zahlen, die niemand als Meldung haben
will: dass die Testumgebung langsamer geworden ist, während dort jemand ein
Profiling laufen lässt, ist keine Nachricht.

## Der Aufwand

Zwei Abfragen je Projekt und Umgebung, unabhängig von der Datenmenge:

1. welche Transaktionen genug Verkehr für einen Beleg haben (höchstens 200, die
   verkehrsreichsten),
2. für genau diese der Stundenverlauf — die Verteilungen werden **in der
   Datenbank** zusammengelegt, aus 10.080 Minutenfenstern je Transaktion werden
   168 Zeilen.

Gerastert wird über den Text des Zeitstempels (`substr(window_start, 1, 13)`),
weil `date_format` und `strftime` nicht in beiden Datenbanken existieren — wie in
der Detailanalyse.

Die Liste selbst rechnet nichts: eine Bruchpunkt-Suche über eine Woche Verlauf je
Transaktion ist nichts, was während eines Seitenaufrufs stattfinden kann.

## Was hier noch nicht steht

Eine Auflösung feiner als eine Stunde. Sie hätte nur Wert, wenn auch die
Zuordnung zu einer Auslieferung feiner wäre — und die ist es nicht: zwischen
„ausgeliefert" und „in den Zahlen sichtbar" liegen ohnehin Minuten bis Stunden
Anlaufzeit.
