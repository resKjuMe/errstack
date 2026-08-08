# Verarbeitung eingehender Meldungen

Angenommen wird im Endpunkt, ausgewertet im Hintergrund. Die Trennung ist der
Kern: die überwachte Anwendung wartet auf unsere Antwort, während bei ihr gerade
etwas schiefgeht — was wir vor der Antwort tun, bezahlt sie mit.

```
POST /api/{projekt}/store/        ─┐
POST /api/{projekt}/envelope/     ─┴─▶ ingest_payloads (Rohdaten)
                                            │
                                            ▼  Warteschlange „ingest"
                                     ProcessIngestPayload
                                            │
                                            ▼
                                     ProcessingPipeline
                                     ├ Entpacken      (Rahmen)
                                     ├ Eingangsfilter (I8)
                                     ├ Scrubbing      (I7)
                                     ├ Stichprobe     (I9)
                                     ├ Antwortzeiten  (PF1) ─▶ transactions
                                     ├ Leistungssuche (PF6) ─▶ Warteschlange „performance"
                                     ├ Normalisierung (I4)  ─▶ events
                                     ├ Grouping       (I5)
                                     ├ Aggregation    (I6)
                                     └ Version        (R1) ─▶ releases
```

Was der Eingangsfilter aussortiert — und was von einer aussortierten Meldung
bleibt —, steht in [eingangsfilter.md](eingangsfilter.md).
Was die Normalisierung aus einer Meldung macht — und warum sie nichts
aussortiert —, steht in [normalisierung.md](normalisierung.md). Wie
Transaktionen und ihre Einzelschritte abgelegt werden, steht in
[antwortzeiten.md](antwortzeiten.md); die beiden Schritte fassen verschiedene
Meldungsarten an und kommen sich nicht in die Quere. Was danach in den
gespeicherten Abläufen gesucht wird — N+1-Abfragen, doppelte Abfragen, langsame
Aufrufe —, steht in [leistungsprobleme.md](leistungsprobleme.md); der Schritt
`ScanPerformance` reiht dafür nur einen Auftrag ein und arbeitet selbst nichts
ab. Wie aus der Angabe `release` einer Meldung eine ausgelieferte Version wird —
und warum dieser Schritt am Ende der Kette steht —, steht in
[versionen.md](versionen.md).

Was am Eingang ankommen **muss**, damit die Original-SDKs ohne Änderung hierher
melden, steht in [compat/README.md](compat/README.md) — samt der Abweichungen zur
Sentry-Spezifikation, die bis heute bestehen.

## Der Rahmen

`App\Jobs\ProcessIngestPayload` ist der Job je Meldung — bei einem Envelope je
Element, nicht je Anfrage: die Elemente sind voneinander unabhängig, und ein
Anhang, dessen Auswertung scheitert, darf die Fehlermeldung nicht mitreißen.

Er tut vier Dinge und sonst nichts:

1. **Doppel erkennen** — siehe unten.
2. **Kette laufen lassen** (`ProcessingPipeline`).
3. **Dauer messen** und den Ausgang festhalten.
4. **Scheitern regeln** — Wiederholung mit wachsendem Abstand
   (10 s, 30 s, 2 min, 10 min), danach als gescheitert vermerken.

Was inhaltlich mit einer Meldung geschieht, steht in den Schritten.

## Einen Schritt hinzufügen

Eine Klasse, die `App\Support\Ingest\Processing\ProcessingStep` umsetzt, und
eine Zeile in `config/ingest.php` unter `processing.steps` — an der Stelle der
Reihenfolge, an die der Schritt gehört. Kein bestehender Schritt und nicht der
Rahmen werden dafür angefasst.

```php
final class DropCrawlerReports implements ProcessingStep
{
    public function handle(ProcessingContext $context, Closure $next): void
    {
        if ($this->isCrawler($context->data)) {
            // Der passende Grund kommt mit dem Schritt, der ihn braucht —
            // DiscardReason wächst mit, statt vorsorglich gefüllt zu werden.
            $context->drop(DiscardReason::Filtered, 'crawler');

            return;
        }

        $next($context);
    }
}
```

Zwei Regeln:

- **Aussortieren heißt `drop()`**, nicht einfach `return`. Wer stillschweigend
  abbricht, lässt die Meldung ohne Begründung verschwinden; sie gilt dann als
  ausgewertet, obwohl nichts passiert ist. Nach einem `drop()` bricht der Rahmen
  die Kette ab — auch wenn der Schritt noch weitergereicht hat.
- **Fehler werden geworfen**, nicht verschluckt. Nur eine Ausnahme führt zur
  Wiederholung. Ein Schritt, der bei einem Aussetzer weitermacht, verwandelt
  einen behebbaren Fehler in stillen Datenverlust.

Ergebnisse für die folgenden Schritte gehen über
`$context->with('name', …)` / `$context->get('name')`. Kein Schritt kennt damit
die Form eines anderen — sonst wäre keiner mehr für sich einsetzbar.

## Doppelte Zustellungen

Bleibt unsere Antwort unterwegs, schickt ein SDK dieselbe Meldung erneut, und
eine Warteschlange darf denselben Job ein zweites Mal ausliefern. Beides ist der
Normalfall, kein Ausnahmefall.

Entschieden wird über die Tabelle `processed_events`: die erste Meldung
beansprucht dort `(projekt, event_id, typ)`, jede weitere scheitert am
eindeutigen Index und gilt als Doppel. Kein Nachsehen-dann-Schreiben — den
Wettlauf zweier Arbeiter entscheidet die Datenbank.

Drei Feinheiten:

- **Angenommen** wird trotzdem alles. Ein eindeutiger Index auf
  `ingest_payloads` würde schon die Annahme scheitern lassen.
- **Der Typ gehört in den Schlüssel.** Ein Anhang trägt die Nummer der Meldung,
  zu der er gehört; ohne den Typ verschwände jeder Screenshot als vermeintliches
  Doppel seines eigenen Fehlers.
- **Nur Meldungen mit eigener Nummer** werden überhaupt gefragt
  (`IngestType::carriesOwnEventId()`).

Ein Wiederholungsversuch erkennt seinen eigenen Anspruch wieder und arbeitet
weiter; nach endgültigem Scheitern wird der Anspruch freigegeben, damit eine
erneute Zustellung eine echte zweite Chance hat.

## Betrieb

```
php artisan queue:work --queue=ingest,notifications,performance,default
php artisan ingest:status            # Rückstand, Dauern, Fehlschläge
php artisan ingest:retry             # Gescheitertes erneut einreihen
php artisan ingest:retry --project=7 --limit=100
```

`ingest:retry` ist nicht dasselbe wie `queue:retry`: dort wird ein gescheiterter
**Job** wiederholt, hier eine gescheiterte **Meldung**. Der Unterschied zählt,
sobald die Ursache nicht der Lauf war, sondern ein Schritt — nach dessen Behebung
gibt es keinen Job mehr, die Rohdaten liegen aber noch da.

Der Zustand steht an der Meldung selbst (`ingest_payloads.processing_state`):

| Zustand | Bedeutung |
|---|---|
| `pending` | wartet auf Auswertung — der Rückstand |
| `processed` | Kette vollständig durchlaufen |
| `duplicate` | war schon da |
| `dropped` | von einem Schritt aussortiert (Filter, Stichprobe, unlesbar) |
| `failed` | alle Versuche gescheitert; mit `ingest:retry` erneut startbar |

Rückstand **und** Dauer gehören zusammen: der Rückstand wächst auch dann, wenn
jeder Durchlauf schnell ist — dann sind es zu viele Meldungen. Die Dauer kann
gut aussehen, während niemand die Warteschlange abarbeitet. Erst nebeneinander
sagen sie, ob mehr Arbeiter nötig sind oder ein Schritt langsam geworden ist.
