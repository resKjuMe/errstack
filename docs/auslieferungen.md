# Auslieferungen (Deploys)

Nach jeder Störung kommt dieselbe Frage: **hängt das mit dem Deploy von eben
zusammen?** Beantworten lässt sie sich nur, wenn irgendwo steht, wann
ausgeliefert wurde — und das steht in keiner Meldung. Ein SDK schickt die
Version mit (`release`), aber nicht den Zeitpunkt, an dem jemand sie in Betrieb
genommen hat, und erst recht nicht, in welche Umgebung.

Diese Aufgabe erfasst deshalb die Auslieferung selbst, markiert sie in den
Verlaufsgrafiken und sagt den Beteiligten Bescheid.

```
Auslieferungs-Pipeline
  └ POST …/releases/{version}/deploys
      ├ Zeile in `deploys`            (Version, Umgebung, Zeitpunkt)
      ├ wartende Einträge auflösen    (nur Standard-Umgebung)
      └ Commit-Autoren benachrichtigen
```

## Version ist nicht Auslieferung

Die Version (R1) entsteht **von selbst** aus der ersten Meldung; sie sagt, dass
irgendwo etwas mit dieser Angabe lief. Ein Deploy sagt, wann jemand sie
ausgeliefert hat und wohin.

`releases.released_at` daneben bleibt, was es war: die **eine** angekündigte
Zeit aus dem Aufruf, der die Version anlegt. Sie ersetzt die Deploys nicht und
kann es nicht — eine Version geht nacheinander nach `staging` und nach
`production`, und nach einem Rollback ein zweites Mal. Ein einzelnes Feld
verlöre bei jedem dieser Schritte den vorigen Zeitpunkt.

## Melden

```
POST /api/0/organizations/{org}/projects/{projekt}/releases/{version}/deploys
```

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `environment` | nein | Umgebung; ohne Angabe die Standard-Umgebung des Projekts |
| `name` | nein | Beschriftung, etwa der Name des Pipeline-Laufs |
| `url` | nein | Adresse des Baulaufs |
| `started_at` | nein | Beginn der Auslieferung |
| `finished_at` | nein | Ende; ohne Angabe der Zeitpunkt des Aufrufs |

Der Geltungsbereich ist `project:write`, das Lesen (`GET` auf dieselbe Adresse)
braucht `project:read`.

**Jeder Aufruf legt einen Deploy an.** Das ist der Unterschied zum Ankündigen
einer Version, die wiederholt anzulegen nichts ändert: zweimal auszuliefern ist
zweimal ausgeliefert. Nach einem Rollback ist genau das der Normalfall, und die
zweite Zeile ist die Auskunft darüber, dass es zwei Zeitpunkte gab.

Eine unbekannte Umgebung entsteht dabei, wie beim Aufnehmen einer Meldung. Ihre
„gesehen"-Zeitpunkte bleiben unberührt: von dort kam noch keine Meldung, und ein
Deploy ist keine.

## Die Umgebung entscheidet, was folgt

**Markierungen** in den Verlaufsgrafiken zeigen genau **eine** Umgebung — die
aus der Filterleiste, und ohne Auswahl die Standard-Umgebung des Projekts
(`default_environment`, Vorgabe `production`). Ein `staging`-Deploy erklärt
keinen Ausschlag in der Produktion, und beide nebeneinander wären ein Wald aus
Strichen, aus dem sich nichts mehr ablesen lässt.

**„Erledigt im nächsten Release"** wird nur von einer Auslieferung in die
Standard-Umgebung aufgelöst. Bis dahin ist der Vermerk eine Absicht ohne Bezug:
der Fix war geschrieben, die Version, in der er steckt, gab es noch nicht. Mit
dem Deploy wird daraus „erledigt in dieser Version" — ab da gilt dasselbe wie
bei einem von Hand gesetzten Bezug: tritt der Fehler aus einer **neueren**
Version wieder auf, ist das eine Rückkehr (S8); aus derselben oder einer älteren
ist es eine Meldung von einem Stand ohne den Fix.

Nach `staging` aufzulösen wäre der teuerste Fehler dieser Aufgabe: die Einträge
verschwänden aus der Liste, während die Fehler bei den Nutzern weiterlaufen.

Im Verlauf des Eintrags steht danach ein eigener Vermerk („ausgeliefert mit
1.2.0 nach production") — ohne handelndes Konto, wie beim Ablauf einer
Stummschaltung: ausgeliefert hat eine Pipeline. Version und Umgebung stehen als
**Werte** darin und nicht als Verweise, damit der Verlauf eine später gelöschte
Version überlebt.

**Benachrichtigt** wird dagegen bei jeder Auslieferung, samt Umgebung im Text:
„meine Änderung ist auf staging" ist genau die Nachricht, auf die jemand wartet,
der gleich nachsehen will.

## Wer etwas erfährt

Die Autoren der Commits, die in der Auslieferung stecken (R2) — und nur sie. Das
ist der Kreis, für den die Nachricht eine Handlungsaufforderung ist. Ein Rundruf
an alle Mitglieder wäre bei zehn Auslieferungen am Tag die Sorte Meldung, nach
der jemand die Benachrichtigungen ganz abschaltet.

Erreichbar sind davon die, deren Adresse sich einem Konto zuordnen ließ. Die
übrigen bleiben stehen, wo sie stehen: am Commit, mit Name und Adresse. Eine
Mail an eine fremde Adresse aus einem Repository heraus wäre etwas anderes als
eine Benachrichtigung.

Ob überhaupt etwas ankommt, entscheiden die persönlichen Einstellungen (A5). Der
Anlass „Auslieferung" ist per Mail **standardmäßig aus** und im Postfach der
Anwendung an — was mehrmals täglich passiert, schaltet sonst den ersten
überrannten Posteingang ab.

## Markierungen in den Grafiken

Sie liegen auf **demselben Raster** wie die Zahlen: eine Auslieferung fällt in
das Fenster, in dem sie stattfand, und bekommt dessen Nummer. Ein eigenes Raster
wäre eine Genauigkeit, die in einer Grafik von Daumenbreite niemand sieht — und
die beim ersten Zeitzonen-Fehler einen Strich neben seinen Ausschlag legte.

Betroffen sind die Verlaufsgrafik der Fehlerliste, dieselbe in der Liste der
Leistungsprobleme und der Antwortzeiten-Verlauf einer Transaktion (PF3). Der
letzte zeichnet nur Fenster, für die es Messungen gibt; eine Auslieferung in
einem Fenster ohne Messung bekommt dort keine Markierung, weil es keine Stelle
gibt, an der sie stehen könnte.

Gezeigt werden höchstens 60 Markierungen, die jüngsten zuerst ausgewählt: wer
alle zwanzig Minuten ausliefert, hätte in einem Verlauf über 90 Tage einige
tausend Striche und damit eine graue Fläche.

## Was hier nicht steht

- **Der Vergleich zweier Auslieferungen** samt Übersichtszahlen steht in
  [versionen.md](versionen.md) — R8.
- **Gesundheit** einer Version (abgestürzte Sitzungen) — R7.
- **Anbindung an GitHub oder GitLab**, die Commits und Deploys von selbst
  abholt — X1/X2. Bis dahin ist die Schnittstelle der Weg hinein, und für eine
  Auslieferungs-Pipeline ist sie der richtige.
