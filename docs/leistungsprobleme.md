# Leistungsprobleme (Erkennung in gespeicherten Abläufen)

Die Antwortzeiten sagen, **dass** etwas langsam ist. Die Leistungsprobleme
sagen, **warum** — und zwar ohne dass jemand einen Ablauf von Hand durchsieht.

```
transactions + transaction_spans   (PF1, bereits gespeichert)
        │
        ▼  Warteschlange „performance"
   DetectPerformanceIssues
        │
        ▼  PerformanceScanner  ──▶ acht Erkenner, der Reihe nach
        │
        ├──▶ performance_detections   der einzelne Fund samt Beleg
        └──▶ issues (category = performance)
                 └ event_groups        derselbe Weg wie bei einem Fehler
```

## Nie im Aufnahme-Request

Die Erkennung läuft **ausschließlich** auf bereits gespeicherten Abläufen. Der
Verarbeitungsschritt `ScanPerformance` tut genau eine Sache: er reiht einen
Auftrag ein. Alles Weitere passiert in `DetectPerformanceIssues`, auf einer
eigenen Warteschlange.

Das ist keine Vorsicht, sondern Notwendigkeit. Ein Ablauf mit fünfhundert
Schritten bedeutet für die Erkenner bis zu hunderttausend Vergleiche. Liefe das
in derselben Ausführung wie die Aufnahme, teilte es sich deren Zeitgrenze, deren
Wiederholungen und deren Warteschlange — eine Anwendung mit vielen Schritten
würde die Aufnahme ihrer eigenen Fehlermeldungen ausbremsen.

Ein Auftrag darf mehrfach zugestellt werden. Der eindeutige Index
`(transaction_id, fingerprint)` an `performance_detections` entscheidet, ob ein
Vorfall neu ist; nur ein **frisch** angelegter Fund bewegt die Zähler des
Eintrags. `transactions.scanned_at` wird erst am Ende gesetzt — ein
abgebrochener Lauf soll erneut laufen dürfen.

## Dieselbe Maschinerie wie bei einem Fehler

Ein Leistungsproblem bekommt eine Zeile in `issues`, unterschieden nur durch
`category`. Damit gelten Zustand, Priorität, Zuweisung, Zeitreihe und Alarme
unverändert, ohne dass eine dieser Funktionen von Leistungsproblemen wissen
müsste.

Der Weg dorthin ist derselbe: Fingerabdruck → `event_groups` → `issues`. Auch
die Gruppe ist dieselbe Tabelle — „was gehört zusammen" ist bei einer
wiederholten Abfrage dieselbe Frage wie bei einer wiederholten Ausnahme. Solche
Gruppen haben keine Ereignisse; ihre Belege sind die Funde.

Der Fingerabdruck besteht aus **Muster + Transaktionsname + Gegenstand**. Nicht
darin: die Kennung des Ablaufs, die Zeit, die Umgebung — sonst wäre jeder Fund
sein eigener Eintrag und aus der Erkennung würde ein Protokoll.

**Getrennt bleibt die Ansicht.** Jede Liste filtert ausdrücklich nach einer
Kategorie (`Issue::scopeOfCategory()`); es gibt keinen Bildschirm, der beide
ungetrennt zeigt. Die Fehlerliste liegt unter `/fehler`, die Leistungsprobleme
unter `/leistungsprobleme`, und die Detailseite eines Fehlers ist unter der
Adresse eines Leistungsproblems nicht zu haben.

## Die acht Muster

| Muster | Woran es erkannt wird | Verlorene Zeit |
|---|---|---|
| N+1-Abfragen | eine auslösende Abfrage, danach dieselbe Abfrageform vielfach unter demselben Elternteil | alle Wiederholungen außer der ersten |
| Aufeinanderfolgende gleichartige Abfragen | dieselbe Form mehrfach hintereinander, **ohne Überlappung** | Summe minus die längste |
| Doppelte Abfragen | derselbe Abfrage**text** samt Werten mehrfach | alle außer der ersten |
| Langsamer HTTP-Aufruf | ein ausgehender Aufruf über der Schwelle | die Zeit **über** der Schwelle |
| Übergroße/unkomprimierte Datei | Größe über der Schwelle oder übertragene ≈ entpackte Größe | die ganze Ladezeit |
| Render-blockierende Ressource | `resource.render_blocking_status = blocking` | die ganze Ladezeit |
| Hauptthread-Blockade | lange Aufgabe des Browsers | die Zeit über 50 ms |
| Cache-Fehlgriffe | wiederholt `cache.hit = false` auf derselben Schlüsselform | Dauer der Fehlgriffe (Untergrenze) |

Zwei Regeln durchziehen die Tabelle:

**Verlorene Zeit ist vermeidbare Zeit**, nicht die gemessene Dauer. Ein fremder
Dienst braucht immer etwas; anzurechnen ist nur der Teil darüber. Andernfalls
stünde in der Liste eine Zahl, die niemand einsparen kann — und die Rangfolge
der Einträge wäre keine Rangfolge des Nutzens mehr.

**Fehlende Angaben sind kein Befund.** Ob eine Ressource blockiert, sagt der
Browser; ob ein Cache-Zugriff getroffen hat, sagt das SDK. Wo die Angabe fehlt,
wird nichts gemeldet. Ein Erkenner, der Unbekanntes als Fehler liest, meldet
jedes SDK an, das die Zahl nicht mitschickt.

## Reihenfolge statt Sonderfälle

Mehrere Muster passen auf dieselben Schritte: fünf identische Abfragen
hintereinander sind doppelt, gleichartig **und** sähen mit einer Abfrage davor
wie ein N+1 aus. Alle drei zu melden hieße, dieselbe Baustelle dreimal in die
Liste zu schreiben.

Deshalb gilt: **wer zuerst kommt, behält die Schritte.** Ein Fund, dessen
Schritte vollständig vergeben sind, entfällt. Die Reihenfolge steht in
`config/ingest.php` unter `performance.detectors` und ist von der genaueren
Aussage zur allgemeineren geordnet. Kein Erkenner prüft die Frage eines anderen
mit.

Vollständig, nicht teilweise: ein einzelner überschneidender Schritt macht zwei
Funde nicht zu einem. Eine langsame Abfrage, die zugleich Teil einer Serie ist,
bleibt eine eigene Aussage.

## Abfrageform statt Abfragetext

`select … where id = 1` und `… where id = 2` sind für die Datenbank zwei
Abfragen und für die Suche nach einem N+1 **eine**. `QueryShape` ersetzt Werte
durch Platzhalter, fasst `in (?, ?, ?)` zu `in (?)` zusammen und räumt Weißraum
auf — bei Adressen entsprechend Kennungen im Pfad und den Abfrageteil.

Das ist ausdrücklich **kein SQL-Parser**. Gebraucht wird keine Zerlegung,
sondern eine stabile Kennung für den Vergleich. Angezeigt wird immer die echte
Abfrage aus dem Beleg.

## Schwellen je Projekt

Welche Muster es gibt, entscheidet der Code (`PerformanceProblem`) — zu jedem
gehört ein Erkenner, und ein Eintrag ohne diesen Code wäre eine Zeile, die
nichts findet. Einstellbar ist, **ab wann** sie anschlagen, und ob sie laufen:
`/organisationen/{org}/projekte/{projekt}/leistungserkennung`.

Gespeichert wird nur, **was abweicht**. Ein Muster auf seinen Vorgabewerten
bekommt keine Zeile in `performance_settings`, und eine bestehende verschwindet,
sobald jemand zurückstellt. Der Grund zeigt sich beim nächsten verbesserten
Vorgabewert: wer den vollen Satz kopiert hätte, bliebe für immer auf dem alten
Wert stehen, obwohl er nie etwas eingestellt hat.

Die Schwellen stehen in den Einheiten, in denen jemand sie eingibt —
Millisekunden, Kilobyte, Anzahl. Die Umrechnung in Mikrosekunden und Bytes
passiert an genau einer Stelle (`Thresholds`).

## Ein Muster hinzufügen

1. Einen Fall in `App\Enums\PerformanceProblem` ergänzen, samt `defaults()` und
   `limits()`.
2. Eine Klasse unter `App\Support\Performance\Detection\Detectors` schreiben,
   die `Detector` erfüllt. Sie bekommt Schritte und Schwellen und gibt Funde
   zurück — sie schreibt nichts.
3. Sie in `config/ingest.php` unter `performance.detectors` eintragen. Wohin,
   entscheidet die Frage: ist die neue Aussage genauer als eine bestehende,
   steht sie davor.
4. Die Beschriftungen in `lang/*/enums.php` ergänzen.

Kein bestehender Erkenner wird dafür angefasst, und der Ablauf auch nicht. Dass
die Erkenner ohne Datenbank prüfbar sind, ist die Probe auf diese Trennung —
`tests/Unit/PerformanceDetectorsTest.php` läuft ohne eine einzige Migration.
