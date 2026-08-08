# Läuft die eigene Installation noch rund?

Nicht zu verwechseln mit der [Selbstüberwachung](selbstueberwachung.md): die
meldet die eigenen **Fehler** über den regulären Weg an eine
Errstack-Installation. Hier geht es um den Zustand *dieser* Installation — steht
noch alles, kommt die Verarbeitung mit, liegt etwas quer.

Der Unterschied ist im Ernstfall der entscheidende. Die Selbstüberwachung setzt
voraus, dass die Anwendung noch so weit lebt, dass sie melden kann. Ein
Rückstand ist dagegen **kein Fehler**: es meldet niemand etwas, die Anwendung
läuft, sie kommt nur nicht mehr mit. Von außen sieht das aus wie Betrieb — bis
Nutzer fragen, warum ihre Fehler fehlen.

Drei Wege hinein, je nachdem, wer fragt:

| Wer fragt | Wohin | Was er bekommt |
| --- | --- | --- |
| Ladeverteiler, Container-Verwaltung | `/health` | „geht" oder „geht nicht", sonst nichts |
| Prometheus & Verwandte | `/metrics` | alle Zahlen, maschinenlesbar (ausgeliefert **aus**) |
| Der Betreiber | Nutzer-Menü → **Betrieb** | dieselben Zahlen zum Ansehen, mit Schaltflächen |

## `/health`

Prüft die vier Bestandteile, ohne die nichts funktioniert — **schreibend und
lesend**, nicht nur die Verbindung:

| Prüfung | Was passiert |
| --- | --- |
| Datenbank | `select 1` |
| Zwischenspeicher | Wert schreiben, zurücklesen, löschen |
| Warteschlange | Länge abfragen |
| Dateiablage | Datei schreiben, zurücklesen, löschen |

Ein reiner Verbindungsaufbau würde die Fälle übersehen, die tatsächlich
vorkommen: eine Ablage, die voll ist, ein Zwischenspeicher, der annimmt und
nichts behält, eine Datenbank, die nur noch lesend antwortet.

```bash
curl -i https://errstack.example/health
```

```json
{ "status": "ok", "checks": { "database": "ok", "cache": "ok", "queue": "ok", "storage": "ok" } }
```

Die eigentliche Auskunft ist der **Statuscode**: `200`, solange alles antwortet,
sonst `503`. Ein Ladeverteiler liest nichts anderes.

Die Adresse ist **ohne Anmeldung** erreichbar und deshalb wortkarg. Kein Grund,
keine Fehlermeldung, keine Zahlen: die Meldung einer Datenbank nennt
Rechnernamen, Datenbanknamen und oft den Benutzer, und wer die Adresse errät,
bekäme sie frei Haus. Wer den Grund braucht, findet ihn im Log oder in der
Betriebsansicht.

Was `/health` **nicht** prüft, ist der Rückstand. Eine Installation mit vollen
Warteschlangen ist nicht kaputt, sie ist beschäftigt — wer sie deswegen aus dem
Ladeverteiler nimmt, nimmt ihr die Arbeiter weg, die den Rückstand gerade
abbauen. Dafür sind die Kennzahlen und die Warnung da.

Eine Prüfung, die zu lange braucht, gilt als gescheitert
(`ERRSTACK_HEALTH_SLOW_MS`, Vorgabe 2000): eine Datenbank, die für `select 1`
zwei Sekunden braucht, ist für die Fehlerannahme so gut wie weg. Das ist
ausdrücklich **keine** Zeitschranke — abgebrochen wird nichts, gemessen wird
hinterher. Gegen ein Hängen ohne Ende hilft nur die Zeitschranke des Treibers.

Laravels eingebaute Adresse `/up` bleibt daneben bestehen. Sie prüft nichts und
beantwortet damit eine andere Frage: „läuft hier überhaupt PHP" gegen „kann
diese Installation arbeiten".

## `/metrics`

Dieselben Zahlen im Prometheus-Textformat. **Ausgeliefert aus**, und das ist
keine Vorsicht um ihrer selbst willen: die Antwort nennt Rückstände,
Warteschlangenlängen und Laufzeiten und sagt damit einem Fremden, wann diese
Installation überlastet ist — und wann ein Angriff also am wenigsten auffällt.

```dotenv
ERRSTACK_METRICS_ENABLED=true
ERRSTACK_METRICS_TOKEN=…      # optional, aber im offenen Netz Pflicht
ERRSTACK_METRICS_PREFIX=errstack
```

```bash
curl -H "Authorization: Bearer $ERRSTACK_METRICS_TOKEN" https://errstack.example/metrics
```

```
# HELP errstack_ingest_backlog Angenommene, noch nicht ausgewertete Meldungen.
# TYPE errstack_ingest_backlog gauge
errstack_ingest_backlog 42
errstack_ingest_backlog_age_seconds 17
errstack_queue_size{queue="ingest"} 40
errstack_health{check="database"} 1
```

Ist die Adresse aus, antwortet sie `404` und nicht `403` — eine abgeschaltete
Adresse soll nicht verraten, dass es sie gibt. Der Token ist optional, weil er
nicht überall gebraucht wird: steht die Adresse im inneren Netz, ist das Netz
der Schutz; steht sie öffentlich, ist er es.

Alle Werte sind **Gauges** — Momentaufnahmen. Histogramme gibt es bewusst nicht:
die Anwendung hält keine Sammelregister über Prozessgrenzen hinweg, und was sie
liefern kann, sind Momentaufnahmen.

Ein Wert, den diese Installation nicht ermitteln kann, wird **weggelassen** und
nicht als `0` gemeldet — eine Warteschlangen-Anbindung, die nicht zählen kann,
darf nicht als leer erscheinen.

## Die Betriebsansicht

Im Nutzer-Menü unter **Betrieb**. Sie beantwortet die drei Fragen in der
Reihenfolge, in der sie im Ernstfall gestellt werden:

1. **Zustand** — dieselben vier Prüfungen wie `/health`, samt Laufzeit.
2. **Rückstand und Dauern** — wie viel wartet, wie lange schon, wie lange die
   Kette rechnet und wie lange eine Meldung insgesamt unterwegs ist.
3. **Was liegengeblieben ist** — gescheiterte Jobs und gescheiterte Meldungen,
   jeweils mit Schaltfläche.

Der Unterschied zwischen den beiden letzten Punkten zählt: **Rechenzeit** misst,
wie lange die Verarbeitungskette arbeitet, die **Dauer bis zur Sichtbarkeit**,
wie lange eine Meldung insgesamt unterwegs war — Wartezeit eingeschlossen. Gehen
sie auseinander, während die Rechenzeit gleich bleibt, fehlen Arbeiter und kein
Schritt ist langsam geworden.

Ebenso der Unterschied zwischen gescheiterten **Jobs** und gescheiterten
**Meldungen**: ein Job lässt sich wiederholen, solange es ihn gibt. Nach einem
reparierten Schritt der Kette gibt es ihn nicht mehr — die Rohdaten liegen aber
noch da, und `ingest:retry` (bzw. die Schaltfläche) lässt sie erneut durchlaufen.

### Wer sie sehen darf

```dotenv
ERRSTACK_OPERATORS=betrieb@example.com,zweiter@example.com
```

Eine Liste von E-Mail-Adressen. Sie steht in der Umgebung und nicht in der
Datenbank, weil sie eine Eigenschaft der *Installation* ist und nicht der Daten
darin: wer den Server betreibt, kann die Umgebung ändern — wer nur ein Konto
hat, nicht.

Ist die Liste leer, sehen die **Besitzer einer Organisation** die Ansicht. Das
ist die brauchbare Vorgabe für die übliche Installation mit einer einzigen
Organisation. Sobald es mehrere gibt, gehört die Liste gesetzt: ein Besitzer der
einen Organisation ist dann gerade nicht der Betreiber.

Wer die Ansicht nicht sehen darf, sieht auch den Menü-Eintrag nicht.

## Die Warnung

Ein Rückstand meldet sich nicht von selbst — deshalb sieht `ops:watch` minütlich
nach (eingehängt in den Zeitplan, siehe `routes/console.php`).

```dotenv
ERRSTACK_BACKLOG_MAX_PENDING=1000        # wartende Meldungen
ERRSTACK_BACKLOG_MAX_AGE_SECONDS=300     # Alter der ältesten
ERRSTACK_BACKLOG_GRACE_MINUTES=5         # so lange muss es so bleiben
ERRSTACK_BACKLOG_REPEAT_MINUTES=60       # Abstand einer Wiederholung
ERRSTACK_BACKLOG_LOG_CHANNEL=            # leer: Standard-Stapel
```

Zwei Schwellen, weil einzeln keine trägt. Die **Menge** allein schlägt bei jedem
Ansturm an, auch bei einem, der in zehn Sekunden abgearbeitet ist. Das **Alter**
allein bleibt still, solange eine einzige alte Meldung quer liegt, während
tausend frische auflaufen. Über der Schwelle ist, wer eine von beiden reißt.

Entscheidend ist die **Frist**: gewarnt wird erst, wenn die Schwelle
ununterbrochen `GRACE_MINUTES` lang überschritten ist. Eine Warnung, die bei
jeder Lastspitze kommt, wird nach der dritten weggeklickt — und die vierte war
die echte. Legt sich die Lage, kommt genau einmal die Entwarnung.

Gemeldet wird auf zwei Wegen: ins **Log**, weil das der Ort ist, an dem der
Betreiber ohnehin nachsieht und der auch dann noch schreibt, wenn sonst nichts
mehr geht — und an die **Selbstüberwachung**, weil dort schon die eigenen Fehler
landen. Ohne `ERRSTACK_DSN` tut der zweite Weg nichts.

Ausdrücklich **nicht** über die Alarme der Anwendung (A2/A3): die gehören Kunden
und hängen an einem Projekt. Ein Rückstand hat kein Projekt — und ein Alarmweg,
der selbst über die stehende Warteschlange läuft, meldet im Ernstfall gar nichts.

Von Hand nachsehen geht jederzeit:

```bash
php artisan ops:watch      # Rückstand samt Schwellen, meldet wenn nötig
php artisan ingest:status  # dieselben Zahlen ausführlicher
```

## Ausprobieren

```bash
# 1. Zustand: sollte "ok" melden
curl -s localhost:8000/health

# 2. Den Arbeiter anhalten (composer dev startet einen) und Meldungen schicken —
#    etwa aus einer angebundenen Anwendung oder von Hand:
curl -s -X POST "localhost:8000/api/1/store/?sentry_key=<schlüssel>" \
  -H 'Content-Type: application/json' \
  -d '{"message":"Rückstand-Probe","platform":"php"}'

# 3. Nachsehen: der Rückstand steigt
php artisan ops:watch

# Ein gescheiterter Job für die Fehlerablage — er zeigt die Schaltflächen:
php artisan demo:ingest --fail
```

Nach `GRACE_MINUTES` steht die Warnung im Log, und die Betriebsansicht zeigt
„Über der Schwelle seit …". `/health` bleibt dabei `200` — das ist kein Versehen,
sondern der Punkt: die Installation ist beschäftigt, nicht kaputt.
