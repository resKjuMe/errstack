# Zuständigkeit: wem ein Fehler gehört

Ein Fehler ohne Zuständigen bleibt liegen. Wer sich kümmert, hängt in aller
Regel davon ab, **wo** er passiert ist — und das steht in der Meldung. Diese
Liste schreibt den Zusammenhang einmal auf, statt ihn bei jedem Eintrag neu von
Hand herzustellen.

```
Meldung (Stacktrace, Adresse, Merkmale)
        │
        ▼  Verarbeitungskette, Schritt „AssignOwner" (nur beim ersten Auftreten)
   OwnershipSubjects   Pfade · Adresse · Module · Merkmale
        │
        ▼
   ownership_rules     von oben nach unten, die LETZTE zutreffende gewinnt
        │
        ▼
   IssueAssignee       #Kasse · anna@example.com  ─▶ Person oder Team
        │
        ├─ Schalter an  ─▶ Zuweisung + Benachrichtigung
        └─ Schalter aus ─▶ Vorschlag im Zuweisungs-Dialog
```

Die Seite steht unter **Projekt ▸ Zuständigkeit**
(`/einstellungen/organisationen/<org>/projekte/<projekt>/zustaendigkeit`). Ansehen darf sie
jedes Mitglied — sie ist die Antwort auf „warum steht mein Name an diesem
Fehler?" —, ändern nur die Verwaltung.

## Eine Regel

Vier Angaben: worauf sie sich bezieht, das Muster, gegebenenfalls der Name eines
Merkmals, und die Zuständigen.

| Bezieht sich auf | Verglichen wird mit | Beispiel |
| --- | --- | --- |
| Pfad | den Dateipfaden **aller** Rahmen des Stacktrace (`abs_path`, `filename`) | `src/billing/*` |
| Adresse | `request.url` der Meldung | `*/checkout/*` |
| Modul | dem `module` der Stacktrace-Rahmen | `com.acme.billing.*` |
| Merkmal | dem Wert des benannten Merkmals | `server_name` ▸ `web-*` |

`*` steht für beliebig viele Zeichen und ist das einzige Sonderzeichen;
verglichen wird auf den ganzen Wert (`Pattern`, dieselbe Klasse wie im
Eingangsfilter). Reguläre Ausdrücke gibt es hier bewusst nicht: die Regeln
schreiben die Leute, die die Fehlerliste ansehen.

**Zuständige stehen als Text**, eine Zeile je Person oder Team — genau die
Schreibweise, die auch ins Suchfeld passt: `#Kasse` für ein Team,
`anna@example.com` für eine Person. `me` und `none` sind keine Zuständigen und
werden abgewiesen: in einer Regel bezeichnen sie niemand Bestimmtes.

### Ein Pfad ist repository-relativ gemeint

Im Stacktrace steht der Pfad, unter dem die Datei **auf dem Server** liegt
(`/var/www/releases/17/src/billing/Invoice.php`); in der Regel steht der aus dem
Repository (`src/billing/*`). Ein Pfad-Muster ohne führenden `*` oder `/` wird
deshalb zusätzlich so verglichen, als stünde `*/` davor — genau die Erwartung,
die eine CODEOWNERS-Datei mitbringt. Wer das nicht will, schreibt das Muster mit
führendem `/`; dann gilt es unverändert. Rückwärtsschrägstriche aus
Windows-Anwendungen werden vorher zu Schrägstrichen.

## Die letzte zutreffende Regel gewinnt

Das ist die eine Festlegung, aus der sich der Rest ergibt — übernommen aus
CODEOWNERS, damit eine importierte Datei hier dasselbe bedeutet wie dort. Sie
lässt sich auch ohne diesen Bezug begründen: eine Liste wird von allgemein nach
speziell geschrieben, und die speziellere Zeile steht dann unten.

```
src/*            #Plattform
src/billing/*    #Kasse      ◀ gewinnt für src/billing/Invoice.php
```

Neue Regeln kommen deshalb **ans Ende** und sind damit die Ausnahme von allem
darüber. Eine abgeschaltete Regel zählt nicht mit — auch nicht als „getroffen,
aber still"; solange sie aus ist, gewinnt die Regel darüber.

## Automatisch zuweisen

Der Schalter steht über den Regeln und ist ab Werk **aus**. Ist er an, gilt:

- **Nur beim ersten Auftreten eines Fehlers.** Bei jeder Meldung zu prüfen wäre
  zweimal falsch: wer eine Zuständigkeit von Hand aufhebt, sagt „nicht ich", und
  ein Schritt, der jeden unbeanspruchten Eintrag wieder zuweist, widerspricht ihm
  minütlich. Betrieblich ist es der Unterschied zwischen einem seltenen und einem
  ständigen Vorgang: das Regelwerk hängt nicht am heißen Weg.
- **Nie über eine bestehende Zuständigkeit.** Eine von Hand getroffene Zuweisung
  wird nicht überschrieben.
- **Der erste auflösbare Zuständige** der gewinnenden Regel bekommt sie. Eine
  Zuständigkeit lässt sich nicht teilen; vorgeschlagen werden trotzdem alle.
- **Benachrichtigt wird wie bei einer Zuweisung von Hand**, über denselben Weg
  und unter denselben persönlichen Einstellungen (A5) — ohne Handelnden, im
  Verlauf steht deshalb „automatisch".

Was das kostet: ein Fehler, den es schon gab, bevor die Regel geschrieben wurde,
bleibt herrenlos. Genau dafür stehen dieselben Regeln als Vorschlag im
Zuweisungs-Dialog.

## Vorschläge im Zuweisungs-Dialog

Öffnet man die Zuweisung an **einem** Fehler, führen die Regeln die Liste an
(`kind: 'ownership'`) — mit der Regel als Begründung daneben („Regel
`path:src/billing/*`"). Ausgewertet wird gegen das **zuletzt** gesehene Ereignis
des Eintrags: nach einem Umbau steht im ältesten ein Pfad, den es nicht mehr
gibt.

Darunter stehen die Autoren der verdächtigen Commits (R4). Die Rangfolge ist
kein Zufall: eine Regel ist eine Entscheidung, ein Abgleich mit einem Commit ist
eine Vermutung. Dieselbe Rangfolge gilt beim automatischen Zuweisen — dort steht
`AssignOwner` vor `AssignSuspectCommit` in der Verarbeitungskette, und weil jeder
Schritt nur zuweist, was niemandem gehört, hat der erste entschieden.

Bei einer Sammelaktion über mehrere Fehler bleibt es bei der reinen
Auswahlliste — eine Zuständigkeit, die für den einen gilt, gilt nicht für die
anderen 12.479.

## Vorschau

„Wer wäre für so ein Ereignis zuständig?" — Pfad, Adresse, Modul oder ein
Merkmal eingeben, und die Antwort steht da: der Zuständige, die zutreffenden
Regeln und welche davon gewinnt. Gerechnet wird serverseitig und mit **denselben**
Klassen wie bei einer echten Meldung; eine zweite Auswertung wäre genau die
zweite Meinung, die eine Vorschau nicht sein darf.

## CODEOWNERS übernehmen

Wer eine CODEOWNERS-Datei hat, hat die Frage schon beantwortet. Die Datei wird
**eingefügt**, nicht angebunden — dasselbe Verhältnis wie bei den Commits (R2):
ein Vorgang, den jemand auslöst und dessen Ergebnis er sieht, statt eines stillen
Abgleichs, der eines Morgens die Zuständigkeiten umgeschrieben hat.

Übersetzt wird Zeile für Zeile zu Pfad-Regeln:

| CODEOWNERS | wird zu | warum |
| --- | --- | --- |
| `/src/billing/` | `src/billing/*` | der führende Schrägstrich meint das Repository-Wurzelverzeichnis, das es hier nicht gibt; ein Verzeichnis meint alles darin |
| `docs/**/*.md` | `docs/*/*.md` | `*` überspringt hier ohnehin Verzeichnisgrenzen |
| `@acme/kasse` | `#kasse` | der Bereich vor dem Schrägstrich ist die GitHub-Organisation |
| `@anna` | `anna` | muss einem Konto oder Team hier entsprechen |
| `docs@example.com` | `docs@example.com` | Adressen bedeuten in beiden Systemen dasselbe |

**Die Reihenfolge der Datei bleibt die Reihenfolge der Liste**, und die Zeilen
kommen ans Ende — sie überstimmen damit alles, was vorher da war. Beim zweiten
Import derselben Datei ist das der Grund, vorher aufzuräumen.

**Übersprungen wird, wessen Zuständige es hier nicht gibt.** Eine Regel, die
niemanden benennt, weist niemandem etwas zu; sie anzulegen wäre die bequeme und
die unehrliche Wahl — die Liste sähe vollständig aus und wäre es nicht. Die
Meldung nach dem Import nennt beide Zahlen.

## Grenzen

- Höchstens **250 Regeln** je Projekt und **10 Zuständige** je Regel. Die erste
  Grenze ist großzügiger als bei den Stichproben, weil eine CODEOWNERS-Datei
  mittlerer Größe dreistellig viele Zeilen hat; die zweite ist ein Hinweis: wer
  eine Datei zwanzig Leuten zuschreibt, hat die Zuständigkeit aufgehoben.
- Eine Regel gilt **ab dem nächsten neuen Fehler** und verteilt keine
  bestehenden.
- Ein gelöschtes Konto räumt die Regel nicht mit auf — die Zuständigen stehen
  als Text. Eine nicht mehr auflösbare Regel weist niemandem etwas zu und fällt
  in der Vorschau sofort auf.
