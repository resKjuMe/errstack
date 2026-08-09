# Anhänge zu Meldungen

Ein Absturz erklärt sich oft erst mit dem, was daneben lag: dem Screenshot der
Seite, auf der der Nutzer stand, der Logdatei der letzten Minute, dem
Speicherabbild des abgestürzten Prozesses. Die SDKs schicken das als eigene
Elemente eines Envelope mit; diese Aufgabe legt sie ab und zeigt sie an.

```
SDK ──▶ POST …/envelope/
          ├ {"type":"event"}      ──▶ ingest_payloads ──▶ events
          └ {"type":"attachment",      │
              "filename":"a.png"}  ──▶ ingest_payloads
                                          │  Warteschlange „ingest"
                                          ▼
                                   StoreAttachment
                                   ├ Zeile  ──▶ event_attachments
                                   └ Datei  ──▶ Laufwerk (config/attachments.php)
```

Die Auswertung von Speicherabbildern (Minidump-Symbolisierung) gehört **nicht**
dazu: hier werden Dateien abgelegt, angezeigt und wieder weggeräumt.

## Ein Anhang kennt seine Meldung nur als Nummer

`event_attachments` hat **keinen** Fremdschlüssel auf `events`, sondern die
Ereignisnummer als Zeichenkette (`event_reference`). Das ist keine Sparsamkeit,
sondern die Reihenfolge im Betrieb: Meldung und Anhang sind zwei Elemente mit je
einem eigenen Job, und welcher zuerst durchläuft, hängt an der Zahl der Arbeiter.
Ein Anhang trifft deshalb regelmäßig **vor** seiner Meldung ein — ein
Fremdschlüssel wäre in genau diesem Regelfall nicht zu setzen, und ein Anhang
ohne Meldung würde verworfen, obwohl an ihm nichts fehlt.

Gefunden wird er über Projekt **und** Nummer. Die Detailseite hat die Meldung in
der Hand und fragt nach ihren Anhängen; den umgekehrten Weg — von der Datei zur
Meldung — gibt es in der Oberfläche nicht, und deshalb braucht er auch kein
Nachverknüpfen (anders als bei den Rückmeldungen, siehe `user_reports`).

## Was angezeigt wird — und was nur heruntergeladen

Beim Ablegen wird jede Datei anhand ihres gemeldeten Inhaltstyps in eine von drei
Arten eingeordnet (`event_attachments.kind`):

| Art      | woraus                                        | Anzeige                       |
|----------|-----------------------------------------------|-------------------------------|
| `image`  | Aufzählung `attachments.preview.image_types`   | eingebettet, inline geliefert |
| `text`   | Aufzählung `attachments.preview.text_types`    | Anriss auf Klick              |
| `binary` | alles andere, auch ein fehlender Inhaltstyp    | ausschließlich Download       |

**Diese Einordnung ist eine Sicherheitsentscheidung, keine Bequemlichkeit.** Eine
Datei in einem Anhang kommt aus einer überwachten Anwendung; wer dort schreiben
kann, könnte über unsere Adresse beliebiges HTML im Browser eines Teammitglieds
ausführen. Deshalb:

- Nur `image` und `text` gehen überhaupt **inline** an den Browser, alles andere
  mit `Content-Disposition: attachment` und `Content-Type:
  application/octet-stream`.
- `image/svg+xml` steht bewusst in **keiner** der beiden Aufzählungen: eine
  SVG-Datei ist ein Dokument mit Skriptmöglichkeit und kein Bild.
- Jede Auslieferung trägt `X-Content-Type-Options: nosniff` — ohne sie darf ein
  Browser den Inhalt beschnüffeln und die Einordnung damit umgehen.
- Die Art wird **gespeichert** und nicht bei jeder Anzeige neu bestimmt: eine
  spätere Ergänzung der Aufzählung soll nicht rückwirkend Dateien inline stellen,
  die beim Eintreffen als Download eingeordnet waren.

Der Textanriss wird als `text/plain` ausgeliefert, auch wenn die Datei
`application/json` ist — für den Browser wäre das ein Dokument, das er auslegt.

## Grenzen

Zwei Grenzen greifen in der Verarbeitung, wo das Projekt bekannt ist. Was eine
davon reißt, wird **für sich** verworfen und gezählt; die Meldung, zu der der
Anhang gehört, ist ein eigenes Element und kommt trotzdem an.

| Wert                              | Vorgabe | wogegen                                        |
|-----------------------------------|---------|------------------------------------------------|
| `attachments.max_bytes`           | 20 MB   | eine einzelne zu große Datei                   |
| `attachments.max_per_event`       | 20      | ein SDK, das bei jedem Versuch dasselbe schickt |

Davor liegt die Grenze der Aufnahme (`ingest.envelope.max_attachment_bytes`), die
nur den Envelope kennt und ohne Projektbezug greift. Die Werte hier dürfen enger
sein — sie sind die Entscheidung des Betreibers.

Der Grund steht danach in der Verworfen-Statistik (`ingest_discards`, Gründe
`too_large` und `too_many_items`, Kategorie `attachment`) und als Warnung im
Protokoll. Das ist der Punkt: ein fehlender Screenshot soll erklärbar bleiben und
nicht still verschwinden.

**Anhänge zählen nicht gegen das Ereignis-Kontingent des Projekts.** Ein Anhang
ist eine Angabe über ein Ereignis und nicht selbst eines
(`IngestType::countsTowardEventQuota()`).

## Gar nicht speichern

Der Datenschutz-Schalter „Anhänge nicht speichern" je Projekt
(`projects.scrub_attachments`, Seite *Datenschutz*) verwirft Anhänge und
Aufzeichnungen bereits beim Schwärzen — vor dem Ablegen. An einer Datei lässt sich
nichts schwärzen: sie ist entweder unbedenklich oder gar nicht.

## Ablage

```
attachments.disk   Laufwerk aus config/filesystems.php   (ATTACHMENTS_DISK)
attachments.path   Präfix in diesem Laufwerk             (ATTACHMENTS_PATH)
```

Der Pfad ist `<präfix>/<projekt>/<zwei zeichen>/<sha1>`:

- **Prüfsumme als Name** — derselbe Screenshot, den ein Absturzdialog mit „erneut
  versuchen" zu jeder Meldung mitschickt, belegt einmal Platz. Gelöscht wird
  entsprechend die Zeile, und die Datei nur, wenn keine zweite Zeile mehr auf sie
  zeigt.
- **Projekt im Pfad** — ein gelöschtes Projekt lässt sich als Ordner wegwerfen,
  ohne die Zeilen aller anderen zu befragen. Nebeneffekt und erwünscht: zwei
  Projekte teilen keine Dateien.
- **Zwei Zeichen als Unterordner** — ein einzelnes Verzeichnis mit hunderttausend
  Dateien ist auf einer echten Platte nicht mehr benutzbar; beim Objektspeicher
  verteilt es die Schlüssel.

Im Betrieb ist das Laufwerk ein Objektspeicher (`s3`), in der Entwicklung die
lokale Platte, im Test ein vorgetäuschtes Laufwerk.

## Aufbewahrung

Anhänge haben ihre **eigene** Frist je Projekt
(`projects.attachment_retention_days`, Vorgabe für neue Projekte aus
`attachments.retention_days`, standardmäßig 7 Tage) — neben der Frist der
Ereignisse und nicht darin. Der Grund ist das Verhältnis: eine Meldung sind
wenige Kilobyte und die Grundlage jeder Auswertung über Wochen, ein
Speicherabbild ist zwanzig Megabyte und wird in den Tagen gebraucht, in denen
jemand den Absturz untersucht. Wer Meldungen ein Jahr behalten will, will nicht
ein Jahr Speicherabbilder behalten.

Weggeräumt wird täglich im Zeitplan:

```
php artisan attachments:prune
```

Der Durchlauf geht Projekt für Projekt gegen dessen eigene Frist, gerechnet ab
dem **Eingang** der Datei (nicht ab dem Anlegen der Zeile: dazwischen liegt die
Warteschlange). Er löscht Zeile, Beleg **und** Datei — ein Löschen nur in der
Datenbank wäre der Fehler, den niemand bemerkt, bis die Platte voll ist.

Gemeldet werden zwei Zahlen: gelöschte Anhänge und tatsächlich freigegebener
Platz. Beides fällt auseinander, sobald zwei Anhänge auf denselben Inhalt zeigen
(dann bleibt die Datei) oder das Laufwerk ein Löschen verweigert (dann steht eine
Warnung im Protokoll).

Ein gelöschtes **Projekt** nimmt seine Dateien mit: die Zeilen fallen der
Fremdschlüssel-Kaskade zum Opfer und wären für diesen Durchlauf nicht mehr
sichtbar, deshalb wirft das Projekt beim Löschen seinen Ablage-Ordner selbst weg
(`AttachmentStore::forgetProject()`).

Läuft der Zeitplan der Anwendung nicht (`schedule:work` bzw. ein Minuten-Cron auf
`schedule:run`), bleibt alles liegen, und die Angabe „verfällt am …" auf der
Fehlerseite wird zur Behauptung.

## Anhänge ohne Meldung

Ein Anhang wird abgelegt, ohne dass wir wissen, ob seine Meldung noch kommt — er
trifft im Normalfall vor ihr ein. Daraus folgt ein Fall, den es geben **muss**:
kommt die Meldung nie (das SDK hat sie nicht geschickt, ein Eingangsfilter hat sie
aussortiert, der Envelope trug gar keine Nummer), bleibt ein Anhang liegen, den
keine Seite anzeigt.

Die Alternative wäre, jeden Anhang ohne Meldung zu verwerfen — und damit den
Regelfall zu treffen, für den die Zuordnung über die Nummer überhaupt gebaut ist.
Begrenzt wird der Fall stattdessen durch die Aufbewahrung: nach
`attachment_retention_days` räumt der nächtliche Durchlauf ihn weg wie jeden
anderen. Wer den Fall häufig hat, sieht ihn an der Verworfen-Statistik der
zugehörigen Meldungen (Grund `filtered`) und nicht an den Anhängen.

## Löschen von Hand

Auf der Fehlerseite lässt sich jeder Anhang einzeln löschen. **Gelöscht wird
dabei auch der Beleg in `ingest_payloads`**, aus dem der Anhang entstand: er ist
eine zweite Kopie derselben Bytes, und ohne ihn wäre das Löschen keines — ein
erneut eingereihter Beleg („Fehlgeschlagene Meldungen erneut einreihen") würde
den gelöschten Screenshot sonst wieder anlegen. Dasselbe gilt für das Aufräumen
nach Frist.

Das Recht ist dasselbe wie für die Zustandsaktionen an einem Fehler (`update`) und
nicht das Löschen des Fehlers: einen Screenshot wegzuwerfen, der personenbezogene Daten
zeigt, ist die Arbeit an der Fehlerliste und keine Verwaltungsaufgabe — und wer
sie erst beantragen muss, tut sie nicht.
