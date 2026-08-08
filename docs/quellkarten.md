# Quellkarten (Source Maps)

Ein JavaScript-Fehler aus dem Browser sieht ohne Quellkarten so aus:

```
TypeError: Cannot read properties of undefined (reading 'summe')
  at a.b.c (https://example.com/static/js/app.4f2c1e.js:1:48213)
```

Eine Datei, eine Zeile, ein Name aus drei Buchstaben. Der Fehler ist da, die
Stelle ist unbrauchbar — und das ist der Regelfall, denn ausgeliefert wird
minimiert.

Diese Aufgabe macht daraus:

```
TypeError: Cannot read properties of undefined (reading 'summe')
  at berechneSumme (src/warenkorb/summe.ts:42:8)

     40 |   const posten = warenkorb.posten;
     41 |
  ▸  42 |   return posten.reduce((summe, p) => summe + p.preis, 0);
     43 | }
```

Dazu braucht sie zwei Dinge, die nur die Bauumgebung hat: das ausgelieferte
Bundle und die Quellkarte dazu.

```
Bauumgebung                         Errstack
───────────                         ────────
npm run build
  ├ app.4f2c1e.js
  └ app.4f2c1e.js.map
      │
      └ POST …/releases/1.3.0/files ──▶ release_artifacts (+ Ablage)

Browser meldet Fehler ──▶ Aufnahme ──▶ Warteschlange „symbolication"
                                          └ SymbolicateEvent ──▶ event_symbolications
```

## Das Ereignis bleibt unverändert

Die Rückübersetzung steht in einer **eigenen** Zeile (`event_symbolications`) und
nicht in `events.exceptions`. Dafür gibt es zwei Gründe, und beide kommen aus dem
Betrieb.

**Quellkarten kommen nach den ersten Fehlern.** Erst knallt es, dann fällt auf,
dass niemand die Karten ausliefert. Würden die übersetzten Rahmen in das Ereignis
geschrieben, sähen Meldungen desselben Fehlers unterschiedlich aus, je nachdem
wann sie eintrafen — und es gäbe keine Möglichkeit mehr zu sagen, was das SDK
eigentlich gemeldet hat.

**An den gemeldeten Rahmen hängt die Gruppierung.** Ein Fingerabdruck, der sich
mit dem Upload einer Quellkarte ändert, spaltet einen laufenden Fehler in zwei.

Die Anzeige zeigt die übersetzte Fassung, sobald es sie gibt, und lässt auf die
gemeldete umschalten. Das ist keine Bequemlichkeit: eine Karte aus einem
**anderen** Bau desselben Bundles liefert plausible falsche Zeilen, und der
einzige Weg, das zu merken, ist der Vergleich. Deshalb steht an jedem übersetzten
Rahmen auch, woraus er entstanden ist.

## Artefakte hochladen

```
POST   /api/0/organizations/{org}/projects/{projekt}/releases/{version}/files
GET    /api/0/organizations/{org}/projects/{projekt}/releases/{version}/files
DELETE /api/0/organizations/{org}/projects/{projekt}/releases/{version}/files/{id}
```

Die Adresse ist die von Sentry (`releases/…/files`), damit `sentry-cli` ohne Umbau
damit sprechen kann.

Hochgeladen wird als `multipart/form-data`:

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `file` | ja | Die Datei — Bundle oder Quellkarte. |
| `name` | ja | Der **Artefakt-Pfad**, unter dem gesucht wird: `~/static/js/app.js`. |
| `debug_id` | nein | Die Debug-Kennung, falls das Werkzeug sie kennt. |
| `source_map_ref` | nein | Verweis auf die Quellkarte, falls `sourceMappingURL` beim Bauen entfernt wurde. |

Beispiel:

```bash
curl -X POST \
  -H "Authorization: Bearer $TOKEN" \
  -F "name=~/static/js/app.4f2c1e.js" \
  -F "file=@dist/app.4f2c1e.js" \
  https://errstack.example/api/0/organizations/acme/projects/webshop/releases/1.3.0/files
```

**`name` ist die eigentliche Angabe, nicht der Dateiname.** Die Tilde steht für
„irgendeine Adresse dieser Anwendung": dieselbe Datei kommt unter der eigenen
Adresse und unter der eines Auslieferungsnetzes daher, der Pfad dahinter bleibt
derselbe. Eine vollständige Adresse wird beim Hochladen in diese Form gebracht,
und die Abfrage (`?v=3`) fällt weg — sie gehört zur Adresse, nicht zur Datei.

**Der Upload ist wiederholbar.** Derselbe Pfad ersetzt das Artefakt und ist kein
Konflikt: eine Auslieferungs-Pipeline läuft nach einem Fehlschlag noch einmal.
Der Inhalt liegt unter seiner Prüfsumme, zwei Uploads derselben Datei belegen
also einmal Platz.

**Art, Debug-Kennung und Kartenverweis werden aus dem Inhalt gelesen.** Ob eine
Datei eine Quellkarte ist, entscheidet nicht die Endung `.map`, sondern ein
JSON-Objekt mit einem Feld `mappings`. Die Kennung steht in der Karte
(`debug_id`) bzw. im Bundle (`//# debugId=`), der Kartenverweis in der letzten
Zeile des Bundles (`//# sourceMappingURL=`).

### Grenzen

| Grenze | Vorgabe | Umgebungsvariable |
|---|---|---|
| Größe je Datei | 60 MB | `SOURCEMAPS_MAX_FILE_BYTES` |
| Dateien je Version | 2000 | `SOURCEMAPS_MAX_FILES_PER_RELEASE` |
| Größe zum Übersetzen | 40 MB | `SOURCEMAPS_MAX_MAP_BYTES` |
| Rahmen je Meldung | 200 | `SOURCEMAPS_MAX_FRAMES` |

Beide Uploadgrenzen werden als **Prüffehler** abgewiesen: ein Client, der
Prüffehler auswertet, soll für diesen Fall keinen zweiten Zweig brauchen. Das
Mengenlimit gilt nur für **neue** Pfade — ein Ersetzen darf nie daran scheitern,
sonst hätte eine Version an der Grenze keine Möglichkeit mehr, eine falsch
hochgeladene Datei zu berichtigen.

Die Grenze zum Übersetzen ist kleiner als die zum Hochladen: aufbewahren darf
man auch, was sich nicht mehr in den Speicher holen lässt.

## Wie zugeordnet wird

Zwei Wege, und der erste hat Vorrang.

**1. Die Debug-Kennung.** Der Bauvorgang schreibt eine Nummer in Bundle **und**
Karte, das SDK meldet sie in `debug_meta.images` mit. Damit ist die Zuordnung
eindeutig und braucht weder Adresse noch Version — der einzige Weg, der ein
Bundle hinter einem Auslieferungsnetz mit wechselnden Adressen noch findet.

**2. Der Pfad.** Der Rahmen nennt `https://example.com/static/js/app.js`, gesucht
wird das Artefakt dieser Version. Bewerber, von der genauesten Angabe zur
ungenauesten:

```
https://example.com/static/js/app.js   die Adresse selbst
~/static/js/app.js                     die Tilden-Form  ◀── die übliche Angabe
/static/js/app.js                      der nackte Pfad
~/app.js                               Dateiname mit Tilde
app.js                                 Dateiname allein
```

Die Reihenfolge ist die eigentliche Aussage: der Dateiname allein ist eine
begründete Vermutung und darf nie eine genauere Angabe überstimmen.

Führt ein Weg auf das **Bundle** statt auf die Karte, geht es weiter über den
Verweis: `sourceMappingURL` (gegen den eigenen Pfad aufgelöst), dann derselbe Name
mit `.map` daneben, und — nur wenn die Version genau **eine** Karte hat — diese.
Bei zwei Karten wird nicht geraten: eine falsche Karte liefert falsche Zeilen
statt keiner.

## Wenn nichts zugeordnet werden kann

Das ist der Teil, der über den Nutzen entscheidet. Eine Rückübersetzung, die
nichts findet, sieht ohne Begründung genauso aus wie eine, die nie stattfand — und
die Ursache liegt fast immer außerhalb dieser Anwendung. Die Anzeige nennt sie
deshalb, je Grund zusammengefasst und mit der Anzahl der betroffenen Rahmen:

| Grund | Was zu tun ist |
|---|---|
| Keine Artefakte zu dieser Version | Upload-Schritt in der Bauumgebung fehlt. |
| Die Meldung nennt keine Version | `release` im SDK setzen — ohne sie ist nicht zu sagen, welche Artefakte gemeint sind. |
| Kein Artefakt zu diesem Pfad | Die geladene Adresse passt nicht zum `name` beim Hochladen. |
| Kein Artefakt zu dieser Debug-Kennung | Die Karten dieses Bauvorgangs wurden nicht hochgeladen. |
| Das Bundle verweist auf keine Quellkarte | `sourceMappingURL` wurde entfernt — `source_map_ref` beim Upload mitgeben. |
| Die verwiesene Quellkarte fehlt | Der Verweis zeigt ins Leere: Karte gebaut, nicht hochgeladen. |
| Die Quellkarte ist unlesbar | Kein JSON, keine Fassung 3, keine `mappings`. |
| Die Karte kennt diese Stelle nicht | Die Karte gehört zu einem anderen Bau desselben Bundles. |
| Die Karte enthält den Quelltext nicht | Ohne `sourcesContent`: Stelle ja, Ausschnitt nein. |

Rahmen, die **nie** gemeint waren — ein Rahmen aus dem PHP-Backend, einer ohne
Datei, einer aus dem Browser selbst —, erzeugen keinen Grund. Sie sind nicht
gescheitert. Diese Trennung ist der Unterschied zwischen einer Diagnose, die man
liest, und einer Liste, die man wegklickt.

## Wann gerechnet wird

Im Hintergrund, in der Warteschlange `symbolication`, und das Ergebnis wird
behalten. Eingereiht wird an zwei Stellen:

1. **Am Ende der Aufnahme** — aber nur, wenn die Meldung überhaupt Rahmen hat, die
   nach ausgeliefertem JavaScript aussehen. Ein Auftrag je Meldung aus einem
   PHP-Backend wäre eine Warteschlange voller Nichts.
2. **Beim Aufschlagen der Fehlerseite**, falls noch keine Übersetzung vorliegt.
   Das ist die wichtigere Stelle — siehe die Reihenfolge im Betrieb oben.

Wer Artefakte hochlädt, macht damit die Ergebnisse **überholt**, die an fehlenden
Artefakten lagen (`unmapped`, `partial`, `failed`); sie werden weggeworfen und beim
nächsten Aufschlagen neu gerechnet. Ein vollständig übersetzter Stacktrace bleibt:
er ist nicht überholt. Neu gerechnet wird **nicht** beim Upload selbst — zweihundert
hochgeladene Dateien würden sonst zweihundertmal dieselben Aufträge einreihen, und
gebraucht wird die Übersetzung erst, wenn jemand hinsieht.

## Was hier nicht steht

- **Native Symbolisierung** (iOS, Android, Debug-Dateien für kompilierte
  Sprachen) ist ausdrücklich nicht Teil dieser Aufgabe. Die Debug-Kennungen aus
  `debug_meta.images` werden mitgeführt, ausgewertet werden nur die von
  JavaScript-Bundles.
- **Eingebettete Karten** (`sourceMappingURL=data:…`) werden nicht ausgewertet:
  sie liegen im Bundle selbst, und das ist eine andere Zuordnung als die eines
  Pfades.
- **Commits an einer Version** — R2. Sie beantworten eine andere Frage: nicht
  „welche Zeile", sondern „welche Änderung".
