# Gruppierung gleichartiger Meldungen

Zehntausend gleiche Abstürze sollen ein Eintrag sein und nicht zehntausend. Das
ist die ganze Aufgabe — und die Hälfte davon ist die Gegenrichtung: zwei
verschiedene Ursachen dürfen nicht im selben Eintrag verschwinden.

```
events (einheitliches Modell, I4)
        │
        ▼  Verarbeitungskette, Schritt „Grouping"
   Grouper
   ├ projektweite Regel?     ─▶ Fingerabdruck der Regel
   ├ eigene Angabe des SDK?  ─▶ Fingerabdruck des SDK
   └ sonst                   ─▶ Standardverfahren
        │
        ▼
   event_groups (ein Fingerabdruck je Projekt)
```

Der Schritt ist `App\Support\Ingest\Processing\Steps\GroupEvent`. Er schreibt an
das Ereignis den Fingerabdruck, die Gruppe und die **Begründung** — was hier
nicht passiert, ist zählen: Häufigkeit, erstes und letztes Auftreten und
betroffene Nutzer sind die Aggregation (I6) und stehen in der Kette danach.

## Die Zusage

**Gleiche Eingabe ergibt dauerhaft dieselbe Gruppe.** Daran hängt mehr, als es
aussieht. Verschiebt sich ein Fingerabdruck, bekommt ein seit Monaten laufender
Fehler eine zweite Gruppe, seine Zählung beginnt bei eins, und jede Alarmregel,
die auf ihn zeigt, meldet einen neuen Fehler. Alles, was in den Hash eingeht,
ist deshalb ausdrücklich benannt und nirgends beiläufig.

Der Hash ist SHA-256, auf 32 Zeichen gekürzt. Die Kürzung ist der Platz in der
Tabelle; 128 Bit reichen dafür weit — die Frage ist nicht Fälschungssicherheit,
sondern ob zwei verschiedene Fehler zufällig zusammenfallen.

## Das Standardverfahren

Vier Wege, in dieser Rangfolge — jeder greift, wenn der vorherige nichts hergibt
(`App\Support\Ingest\Grouping\DefaultFingerprint`):

| Verfahren | Bestandteile | wann |
|---|---|---|
| `stacktrace` | Ausnahme-Typ + Stapelrahmen | Regelfall bei einem Absturz |
| `exception` | Ausnahme-Typ + Text | Ausnahme ohne Stacktrace |
| `message` | Vorlage des Meldungstextes | Nachricht ohne Ausnahme |
| `fallback` | Titel, Fehlerstelle, Vorgang | nichts von alledem |
| `empty` | Plattform + Grad | nichts, wonach sich unterscheiden ließe |

Der entscheidende Zug ist, was **nicht** eingeht:

**Der Text der Ausnahme, sobald es einen Stacktrace gibt.** Er trägt die
wechselnden Anteile („Nutzer 4711 nicht gefunden"), der Stacktrace nicht. Mit ihm
im Fingerabdruck entstünde je Kennung eine Gruppe.

**Die Zeilennummer eines Rahmens.** Sie verschiebt sich bei jeder Änderung an der
Datei; mit ihr bekäme derselbe Fehler nach jedem Deployment eine neue Gruppe.

**Rahmen aus fremdem Code**, solange das SDK `in_app` setzt. Sie sind bei jedem
Fehler dieselben und würden die Zuordnung nur empfindlich gegen jede
Fassungsänderung einer Bibliothek machen. Setzt ein SDK `in_app` gar nicht,
gelten alle Rahmen — eine Zuordnung nach fremdem Code ist besser als keine.

## Wechselnde Anteile

`App\Support\Ingest\Grouping\Variables` ersetzt in Texten und Pfaden, was
erkennbar eine Kennung ist: Speicheradressen, UUIDs, Prüfsummen, Zeitpunkte,
IP- und E-Mail-Adressen, Tokens und frei stehende Zahlen ab vier Stellen. Pfade
verlieren zusätzlich den Bauteil vor dem Quellverzeichnis, Fassungsnummern und
den Prüfsummen-Anteil gebündelter Dateien (`app.4f3a2b1c.js`).

Zwei Fehler sind möglich, und sie sind **nicht gleich schlimm**:

- **Zu wenig ersetzen** ergibt je Kennung eine Gruppe. Sieht man sofort, behebt
  man mit einer Regel.
- **Zu viel ersetzen** verbirgt eine Ursache hinter einer anderen. Sieht man
  nicht — und niemand sucht nach dem, was er nicht sieht.

Deshalb bleiben kurze Zahlen stehen: `404`, `30 s`, `3 von 5` sind Angaben zum
Fehler und sollen ihn unterscheiden dürfen.

## Eigene Angabe des SDK

Schickt eine Meldung ein Feld `fingerprint`, gilt es statt des
Standardverfahrens:

```json
{ "fingerprint": ["abrechnung"] }
```

Der Platzhalter `{{ default }}` setzt die Bestandteile des Standardverfahrens
ein — damit lässt sich **verfeinern statt ersetzen**:

```json
{ "fingerprint": ["{{ default }}", "{{ tags.mandant }}"] }
```

heißt „gruppiere wie immer, aber je Mandant getrennt". Feld-Platzhalter wie
`{{ error.type }}` funktionieren auch mitten im Text. Ein Feld, das die Meldung
nicht hat, wird zu `<none>` — weggelassen würde es die Zahl der Bestandteile von
der einzelnen Meldung abhängig machen, und derselbe Fehler bekäme mit und ohne
Marke zwei Gruppen.

Ein Fingerabdruck, der **nur** aus `{{ default }}` besteht, gilt als keine
Angabe: manche SDKs schicken ihn als Vorgabewert mit.

## Projektweite Regeln

`fingerprint_rules`, gepflegt unter *Projekt → Gruppierung*. Eine Regel ist eine
Liste von Bedingungen (alle müssen zutreffen) und der Fingerabdruck, den sie
setzt:

| Bedingung | Fingerabdruck |
|---|---|
| `error.type: *TimeoutException` | `["zeitueberschreitung"]` |
| `stack.path: */abrechnung/*` | `["{{ default }}", "abrechnung"]` |
| `logger: worker.*`, `!release: 2.*` | `["worker-alt"]` |

Muster sind Platzhalter-Ausdrücke mit `*` und `?` und **keine** regulären
Ausdrücke — ein falscher regulärer Ausdruck kann in einem Schritt, der bei jeder
Meldung läuft, teuer werden. Ohne Platzhalter trifft ein Muster genau.

Die **erste** zutreffende Regel gewinnt; die Reihenfolge ist einstellbar. Und
Regeln gewinnen **auch über die Angabe des SDK**: sie wurden von Hand angelegt,
weil das Grouping in diesem Projekt daneben lag — häufig gerade weil ein SDK
einen unbrauchbaren Fingerabdruck schickt. Gewönne das SDK, wäre die Regel genau
dort wirkungslos, wo sie gebraucht wird.

**Regeln wirken nicht rückwirkend.** Bereits ausgewertete Meldungen behalten ihre
Gruppe. Eine Regel, die alte Meldungen umsortiert, würde Zähler, Zeitverläufe und
bereits verschickte Alarme im Nachhinein verfälschen; wer den Bestand mitziehen
will, wertet erneut aus.

## Die Begründung

An jedem Ereignis steht unter `grouping`, wie es in seine Gruppe kam:

```json
{
  "source": "stacktrace",
  "values": ["error.type=TypeError", "stack.frame=handle in app/Jobs/Import.php"],
  "components": [{ "name": "error.type", "value": "TypeError" }],
  "rule_id": null
}
```

Ohne diese Angabe wäre der Fingerabdruck eine Zeichenkette ohne Herkunft: man
sähe, **dass** zwei Meldungen zusammengefasst wurden, aber nicht, **wonach** —
und könnte weder eine Regel schreiben noch eine falsche erkennen. Sie steht am
Ereignis und nicht nur an der Gruppe, weil sich das Verfahren ändern kann; die
Frage ist dann nicht „wie wird heute gruppiert?", sondern „warum landete diese
Meldung damals dort?".

## Gruppe und Fehler-Eintrag

`event_groups` ist ein Fingerabdruck je Projekt und bewusst schmal — kein
Zähler, kein Zustand, keine Priorität. Das ist der Fehler-Eintrag (I6), und er
wird mehrere Gruppen umfassen können, sobald S9 das Zusammenführen von Hand
bringt. Läge der Zähler an der Gruppe, wäre jedes Zusammenführen ein Umrechnen
und jedes Auftrennen ein Verlust.

Der Fingerabdruck steht deshalb **auch** am Ereignis, nicht nur die
Gruppen-Kennung: nach dem Zusammenführen zeigen mehrere Fingerabdrücke auf
denselben Eintrag, und ohne den Wert am Ereignis ließe sich eine Untergruppe
nicht mehr verlustfrei herauslösen.
