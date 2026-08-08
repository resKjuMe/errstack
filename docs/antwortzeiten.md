# Antwortzeiten (Transaktionen und Spans)

Ein Fehler sagt, dass etwas kaputt ist. Eine Transaktion sagt, dass etwas
**langsam** ist — und ihre Einzelschritte sagen, wo die Zeit geblieben ist.

```
Envelope-Element {"type":"transaction"}
        │
        ▼  ingest_payloads (Rohdaten, unverändert)
   ProcessIngestPayload  →  ProcessingPipeline
        │                        └ RecordTransaction  ← dieser Teil
        ▼
   transactions                 eine Zeile je gemessenem Aufruf
   transaction_spans            die Einzelschritte, als Baum
   transaction_aggregates       Anzahl, Summe, Verteilung je Minute
   transaction_user_aggregates  Nutzer und Unzufriedene je Minute
```

## Eine Transaktion ist kein Fehler

Das ist keine Formulierung, sondern die Bauweise. Transaktionen liegen in
eigenen Tabellen, werden von einem eigenen Schritt geschrieben und sind über
keine Beziehung von einer Fehlermeldung aus erreichbar. Eine gemeinsame Tabelle
mit einer Spalte „ist ein Fehler" hätte jedem Aufrufer einen Filter zur Pflicht
gemacht — und beim ersten vergessenen stünde die Fehlerliste voll mit
erfolgreichen Seitenaufrufen.

`RecordTransaction` fasst deshalb ausschließlich Meldungen des Typs
`transaction` an und reicht alles andere unverändert weiter. Umgekehrt gilt
dasselbe: die Schritte, die Fehler gruppieren und zusammenfassen (I5, I6),
sehen keine Transaktion.

Aus einem gespeicherten Ablauf kann später trotzdem ein Eintrag in der
Issue-Maschinerie werden — als **Leistungsproblem**, nicht als Fehler, und in
einer eigenen Liste. Wie die Erkennung das macht und warum sie nie im
Aufnahme-Request läuft, steht in [leistungsprobleme.md](leistungsprobleme.md).

## Was gespeichert wird

| Tabelle | Inhalt |
|---|---|
| `transactions` | Name, Operation, Anfang/Ende, Dauer, Ausgang, Umgebung, Version, Nutzer-Kennung, Trace-Zusammenhang, Messwerte |
| `transaction_spans` | Operation, Beschreibung, Dauer, Eltern-Schritt, Zusatzangaben, Reihenfolge |
| `transaction_aggregates` | je Name, Operation, Umgebung und Minute: Anzahl, Fehlschläge, Summe, Kleinstes, Größtes, Verteilung |
| `transaction_user_aggregates` | je Nutzer, Name, Operation, Umgebung und Minute: Anzahl der Aufrufe und wie viele davon zu langsam waren |

Zwei Festlegungen fallen auf:

**Dauern in Mikrosekunden.** In Millisekunden wäre eine Datenbankabfrage von
300 µs eine Null, und eine Verteilung, in der die Hälfte der Werte Null ist,
taugt für keine Auswertung. Zeitpunkte tragen aus demselben Grund
Millisekunden (`timestamp(3)`); ohne sie begänne jeder Schritt einer
Transaktion zur selben Zeit und die Reihenfolge im Ablauf wäre verloren.

**Umgebung und Version als Text**, nicht als Fremdschlüssel — wie bei den
Fehlermeldungen. Eine versteckte oder gelöschte Umgebung soll ihre Messwerte
nicht mitnehmen.

## Der Trace-Zusammenhang

Drei Kennungen halten einen Ablauf über mehrere Dienste zusammen:

- `trace_id` — der ganze Ablauf. Dieselbe in Browser, Backend und Worker.
- `span_id` — dieser Aufruf innerhalb des Ablaufs.
- `parent_span_id` — der aufrufende Schritt. Leer bei der äußersten
  Transaktion.

Der Index auf `trace_id` trägt bewusst **kein** `project_id` vorneweg: bei
mehreren Diensten liegen die Transaktionen eines Ablaufs in mehreren Projekten,
und die Trace-Ansicht (PF4) sammelt sie alle.

Meldet ein SDK keinen Trace-Zusammenhang, wird einer aus der Ereignis-Nummer
abgeleitet — nicht zufällig gezogen. Sonst entstünde bei jedem
Wiederholungsversuch ein neuer Trace.

## Die Vorberechnung

Eine Anwendung mit 100 Aufrufen je Sekunde erzeugt im Monat 260 Millionen
Zeilen. Eine Übersichtsseite kann darüber nicht rechnen. Deshalb wird bei jeder
Messung ein Zeitfenster von einer Minute fortgeschrieben; die Übersicht summiert
diese Fenster.

Was im Schlüssel steht, ist die eigentliche Entscheidung: **Name, Operation und
Umgebung** — alles, wonach die Übersicht filtert. Nicht Version und nicht
Nutzer: jedes weitere Merkmal vervielfacht die Zeilen.

**Perzentile brauchen eine Verteilung.** Ein p95 lässt sich nicht addieren — aus
sechzig Minuten-p95 wird nicht das p95 der Stunde. Gespeichert wird deshalb eine
Häufigkeitstabelle über logarithmisch wachsenden Klassen
(`DurationHistogram`): jede Klasse ist doppelt so breit wie die vorige, was zu
den Daten passt (bei 40 ms sind 5 ms viel, bei 40 s belanglos). Der Preis ist
eine bekannte Ungenauigkeit — der Fehler eines Perzentils ist höchstens die
Breite seiner Klasse, und ausgewiesen wird deren Obergrenze, also nie zu
niedrig.

**Warum die Verteilung immer ein JSON-Objekt ist.** `json_encode` macht aus
einem Feld mit lückenlosen Schlüsseln ab null eine Liste (`[2,5]`) und sonst ein
Objekt (`{"7":2}`) — dieselbe Klasse stünde damit mal unter `$."0"` und mal
unter `$[0]`. Für PHP ist das gleichgültig, für die Übersicht nicht: sie legt
die Verteilungen eines Zeitraums **in der Datenbank** zusammen und liest je
Klasse einen festen Pfad. `TransactionAggregate` schreibt deshalb mit
`JSON_FORCE_OBJECT`.

**Nutzer stehen in einer zweiten Tabelle.** „Wie viele Nutzer sind betroffen"
lässt sich aus dem Aggregat nicht beantworten — der Nutzer steht bewusst nicht
in dessen Schlüssel. Die Frage aus den Einzelmessungen zu beantworten
(`COUNT(DISTINCT user_identifier)`) wäre genau der Vollscan, den die
Vorberechnung vermeiden soll. Also eine eigene Vorberechnung mit einer Zeile je
Nutzer, Transaktion und Minute; die Kennung steht dort **gehasht**, weil sie nur
gezählt und nie angezeigt wird. Ihre Zeilenzahl wächst mit der Zahl der
wiederkehrenden Nutzer und nicht mit der der Aufrufe.

Mitgeschrieben wird dabei, wie viele Aufrufe eines Nutzers über der
Unzufriedenheits-Schwelle lagen (`PERFORMANCE_APDEX_THRESHOLD_US` mal
`PERFORMANCE_MISERY_FACTOR`, Vorgabe 300 ms mal 4 — die Apdex-Rechnung von
Sentry). Die Bewertung fällt **beim Aufnehmen**: eine später geänderte Schwelle
bewertet Altdaten nicht rückwirkend um. Das ist der Preis dafür, die Kennzahl
ohne Vollscan zu bekommen.

**Warum die Zeile gesperrt wird.** Anzahl und Summe ließen sich mit
`count = count + 1` ohne Sperre hochzählen, die Verteilung nicht: sie ist ein
Feld-Baum, der gelesen, geändert und zurückgeschrieben wird. Zwei Arbeiter ohne
Sperre lesen dieselbe Verteilung, und die zweite Messung überschreibt die erste
— die Anzahl stünde auf 2, die Verteilung enthielte einen Wert. Der Ausweg aus
der Wartezeit auf einer heißen Zeile ist nicht die schwächere Zusage, sondern
das Zusammenfassen mehrerer Messungen vor dem Schreiben; das gehört zur Härtung
der Aufnahme (O12).

## Grenzen

| Grenze | Wert | Warum |
|---|---|---|
| Einzelschritte je Transaktion | `INGEST_MAX_SPANS`, Vorgabe 1000 | Eine Anwendung mit einer N+1-Abfrage meldet Zehntausende gleichartige Schritte für **einen** Aufruf. |
| Messwerte je Transaktion | 50 | Die Spalte ist begrenzt, das Schema nicht. |
| Länge einer Beschreibung | 8192 Zeichen | Dort steht das SQL einer Abfrage; bei 255 Zeichen wäre es nicht mehr das Problem, das es benennt. |
| Zeitpunkte | ab Jahr 2000, höchstens einen Tag in der Zukunft | Die Uhr gehört der überwachten Anwendung. Der häufigste Fehler ist ein SDK, das Millisekunden statt Sekunden schickt — `1770000000000` ist das Jahr 58026, und ungeprüft bräche daran die Einfügung ab. Ein Tag Spielraum, damit eine leicht vorlaufende Uhr ihre Messungen behält. |

Was über eine Grenze hinausgeht, wird **gezählt** (`ingest_discards`) und
protokolliert. Ohne diese Zahlen wäre ein abgeschnittener Ablauf in der
Trace-Ansicht nicht von einem vollständigen zu unterscheiden.

## Was ausdrücklich nicht verloren geht

Eine Transaktion wird nur dann nicht abgelegt, wenn sich schlicht nichts messen
lässt — es fehlt Anfang oder Ende. Alles andere wird ersetzt und die Messung
behalten:

| fehlt | Ersatz |
|---|---|
| Name | `<unlabeled transaction>` (wie bei Sentry; übersetzt wird in der Anzeige, nicht in der Ablage) |
| Trace-Zusammenhang | aus der Ereignis-Nummer abgeleitet |
| Umgebung | Standard-Umgebung des Projekts |
| Elternteil eines Schritts | die Transaktion selbst |
| Ausgang | leer — und ein **unbekannter** Ausgang zählt nicht als Fehlschlag, sonst springt die Fehlerrate bei jedem neuen Sentry-Status auf 100 % |

Ein zweiter Durchlauf derselben Meldung ändert die vorhandene Zeile und zählt
die Vorberechnung **nicht** erneut hoch. Dieser Fall ist vorgesehen: ein
gescheiterter Job wird wiederholt, und nach einer Änderung an der Verarbeitung
sollen sich die Rohdaten erneut durchlaufen lassen.

## Was hier noch nicht steht

Die Auswertung. Die Übersicht (PF2) liest aus diesen Tabellen — sie steht unter
`/leistung` und rechnet mit drei Abfragen, unabhängig von der Datenmenge
(`App\Support\Performance\TransactionOverview`). Detailseite mit Verteilung
(PF3), Trace-Ansicht (PF4), Web Vitals (PF5), automatisch erkannte Probleme
(PF6) und Trend-Erkennung (PF7) lesen ebenfalls von hier — geschrieben werden
die Tabellen in diesem Teil.
