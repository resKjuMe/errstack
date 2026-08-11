# Anbindungen

Eine Anbindung verbindet eine Organisation mit einem Ort, an dem ihre Arbeit
liegt. Es gibt zwei Sorten:

* **GitHub** — dort liegt der Code. Commits kommen von selbst herein, und
  nebenbei lassen sich Tickets anlegen.
* **Jira** und **Linear** — dort liegen die Tickets. Sie hängen an einer
  gemeinsamen Schnittstelle; alles, was unten über Tickets steht, gilt für beide
  gleich.

Ein Fehler kann mit **mehreren** Tickets verknüpft sein, auch mit welchen aus
verschiedenen Systemen.

Ohne sie funktioniert alles weiter — Auslieferungen entstehen aus Meldungen, und
eine Bauumgebung übergibt ihre Commits über die Schnittstelle. Die Anbindung
nimmt drei Handgriffe ab:

* **Commits kommen von selbst.** Wer eine Version mit ihrem Stand ankündigt
  (`ref`), bekommt ihren Inhalt ohne weiteres Zutun — der Vergleich mit der
  vorigen Version ist genau die Frage „was ist neu".
* **Aus einem Fehler wird ein Ticket.** Ein Klick auf der Fehlerseite legt es an
  und verknüpft es.
* **Ein geschlossenes Ticket erledigt den Fehler.** Der Abgleich läuft über
  Webhooks.

## Einrichten (einmal je Installation)

Bei GitHub unter *Settings › Developer settings › OAuth Apps* eine App anlegen.
Als Rückadresse gehört dort genau diese Adresse hinein:

```
<APP_URL>/einstellungen/anbindungen/github/rueckkehr
```

Danach in der `.env`:

```
GITHUB_CLIENT_ID=…
GITHUB_CLIENT_SECRET=…
GITHUB_WEBHOOK_SECRET=…
```

Das Webhook-Geheimnis ist frei wählbar; es unterschreibt die eingehenden
Meldungen. **Ohne dieses Geheimnis wird jede eingehende Meldung abgewiesen** —
und es wird auch kein Webhook eingerichtet, weil dessen Meldungen sicher im
Nichts landen würden.

Für ein selbst betriebenes GitHub Enterprise kommen zwei Adressen dazu:

```
GITHUB_URL=https://github.example.com
GITHUB_API_URL=https://github.example.com/api/v3
```

## Verbinden (einmal je Organisation)

Unter *Einstellungen › Anbindungen* auf **Mit GitHub verbinden**. Nach der
Anmeldung wird ausgewählt, welche Repositories diese Organisation versorgen. Für
jedes ausgewählte wird — sofern die Rechte reichen — ein Webhook eingerichtet
(`push`, `issues`).

Reichen die Rechte für den Webhook nicht, wird das Repository trotzdem
verbunden: Commits holen und Tickets anlegen gehen ohne ihn, nur der
Zustandsabgleich fehlt. Der Webhook lässt sich dann von Hand einrichten:

| Feld | Wert |
|---|---|
| Payload URL | `<APP_URL>/api/hooks/github` |
| Content type | `application/json` |
| Secret | derselbe Wert wie `GITHUB_WEBHOOK_SECRET` |
| Events | *Pushes* und *Issues* |

## Was die Anbindung darf

Die Berechtigungen sind `repo` und `read:org`. `repo` ist grob, und das ist
GitHubs Zuschnitt, nicht unserer: Commits privater Repositories lesen und
Tickets anlegen gibt es dort nicht kleiner.

Das Zugriffstoken wird **verschlüsselt** gespeichert und nirgends wieder
ausgegeben — weder in der Oberfläche noch im Änderungsprotokoll noch in einer
Fehlermeldung. Was hinausgeht, ist der Kontoname, unter dem verbunden wurde.

## Verbindung verloren

Wird das Token zurückgezogen oder das Zugriffsrecht entzogen, antwortet GitHub
auf **jeden** Aufruf mit `401` oder `403`. Das wird an der Anbindung
festgehalten, und die Seite zeigt es samt der Meldung, die GitHub geschickt hat.

Das ist der Unterschied, auf den es hier ankommt: ohne diesen Zustand kämen die
Commits einer Auslieferung einfach nicht mehr, und das sieht aus wie „diese
Version hatte keine". Eine kurze Störung — Netzfehler, Zeitüberschreitung, ein
`500` drüben — löst das **nicht** aus; sie geht von selbst vorbei.

Der Weg zurück ist **Neu verbinden**. Die verbundenen Repositories und ihre
Commits bleiben dabei erhalten.

## Lösen

Die Anbindung zu lösen wirft keine Daten weg. Die Repositories bleiben samt
ihren Commits stehen und fallen auf den Stand zurück, den sie ohne Anbindung
hätten: eingetragen, mit ihrer Geschichte, nur ohne jemanden, der Neues holt.

Der Webhook bei GitHub bleibt bestehen — ihn zu entfernen bräuchte genau das
Token, das dabei weggeworfen wird. Was er künftig schickt, wird abgewiesen.

## Was mit eingehenden Meldungen geschieht

| Ereignis | Wirkung |
|---|---|
| `issues` | Der Zustand jeder Verknüpfung wird nachgeführt. Ein **geschlossenes** Ticket setzt den Fehler auf erledigt. |
| `push` | Auslieferungen, die auf einen der gepushten Stände zeigen und noch keine Commits haben, holen sie nach. |
| alles andere | wird festgehalten, aber nicht ausgewertet |

Ein **wieder geöffnetes** Ticket öffnet den Fehler bewusst *nicht* wieder:
„erledigt" kann hier auf einem zweiten Ticket beruhen, auf einer Auslieferung
oder schlicht auf einer Entscheidung. Ein Klick drüben soll die nicht
überstimmen.

Jede Zustellung wird genau einmal ausgewertet. GitHub wiederholt sie, wenn die
Antwort ausbleibt, und auf Knopfdruck — die Kennung der Zustellung
(`X-GitHub-Delivery`) entscheidet, wer die erste war.

# Ticket-Systeme (Jira, Linear)

Der Zweck ist derselbe wie bei GitHub, nur ohne Code: aus einem Fehler ein
Ticket machen, ein vorhandenes verknüpfen, und den Zustand beider Seiten
zusammenhalten.

## Verbinden (einmal je Organisation)

**Diese Anbindungen brauchen keine Einrichtung der Installation.** Kein Eintrag
in der `.env`, keine registrierte App — verbunden wird mit einem **API-Token**,
das jemand in seinen Kontoeinstellungen erzeugt. Es gehört der Organisation und
liegt verschlüsselt an ihrer Anbindung.

Unter *Einstellungen › Anbindungen*:

| Anbieter | Felder |
|---|---|
| Jira | Adresse der Instanz (`https://acme.atlassian.net`), E-Mail-Adresse zum Token, API-Token |
| Linear | API-Schlüssel |

Jira Cloud verlangt E-Mail-Adresse **und** Token zusammen (Basic-Auth); ein
`Bearer` gilt dort nur für Zugänge einer installierten App. Bei Linear steckt
alles im Schlüssel.

**Das Token wird geprüft, bevor es gespeichert wird.** Antwortet der Anbieter
nicht, entsteht keine Anbindung — und eine vorhandene bleibt unverändert. Ein
Tippfehler beim Erneuern ersetzt also nicht die funktionierende Anbindung.

## Rückadresse eintragen (Pflicht für den eingehenden Abgleich)

Ohne sie erfährt Errstack nichts davon, dass ein Ticket drüben geschlossen
wurde. Die vollständige Adresse steht auf der Anbindungsseite; sie sieht so aus:

```
<APP_URL>/api/hooks/tickets/jira/<geheimnis>
```

**Sie enthält ein Geheimnis — behandeln Sie sie wie ein Passwort.** Das ist der
Unterschied zu GitHub, und er ist nicht gewählt, sondern vorgefunden: Jira Cloud
unterschreibt eine über die Schnittstelle eingetragene Rückadresse nicht, und
Linears Unterschrift hängt an einem Geheimnis, das erst beim Einrichten des
Webhooks drüben entsteht. Deshalb weist die Adresse den Anrufer aus.

Wo sie einzutragen ist:

| Anbieter | Ort | Ereignisse |
|---|---|---|
| Jira | *Systemeinstellungen › Webhooks* | *Issue: created, updated* |
| Linear | *Settings › API › Webhooks* | *Issues* |

Ist die Adresse einmal irgendwo gelandet — in einem Ticket, einem Chat, einem
Zustellungsprotokoll —, ist sie kein Geheimnis mehr. **Adresse erneuern** setzt
ein neues Geheimnis; die alte antwortet danach mit `401` und muss beim Anbieter
ersetzt werden.

## Statusabgleich

Beide Richtungen sind **einzeln** abschaltbar:

| Schalter | Wirkung |
|---|---|
| Ticket erledigt → Fehler erledigt | Ein Ticket, das drüben in einen erledigten Zustand wechselt, setzt den Fehler auf erledigt. |
| Fehler erledigt → Ticket erledigt | Ein hier erledigter Fehler schließt sein Ticket; wird er wieder geöffnet, geht das Ticket denselben Weg zurück. |

Die zweite steht getrennt, weil sie in einem fremden System **schreibt**. Es gibt
Teams, die ihre Vorgänge ausschließlich drüben schließen wollen, weil dort eine
Abnahme dranhängt.

Ein **wieder geöffnetes** Ticket öffnet den Fehler bewusst *nicht* wieder — aus
demselben Grund wie bei GitHub.

### Was „erledigt" drüben heißt

Weder Jira noch Linear kennen zwei Zustände; beide kennen so viele, wie jemand
angelegt hat. Gelesen wird deshalb nicht der **Name** des Zustands, sondern seine
Einordnung:

| Anbieter | gilt hier als geschlossen |
|---|---|
| Jira | Zustandskategorie `done` (die Kategorie legt Atlassian fest, nicht das Projekt) |
| Linear | Zustandsart `completed` oder `canceled` |

Ein Projekt mit deutschem Arbeitsablauf („Abgenommen") funktioniert damit ohne
Zutun. Auf die Namen zu sehen wäre die naheliegende Abkürzung und die Stelle, an
der so ein Projekt nie mehr einen Fehler erledigt.

### Wie geschlossen wird

Bei Jira ist „schließen" kein Feld, das man setzt, sondern ein **Übergang** — und
welche es gibt, entscheidet der Arbeitsablauf des Projekts. Errstack holt die
möglichen Übergänge und nimmt den, der in die Kategorie `done` führt. Gibt es
keinen (weil ein Pflichtfeld dranhängt oder aus diesem Zustand kein Weg
herausführt), bleibt das Ticket stehen und der Fehler hier trotzdem erledigt: der
Abgleich vermerkt es im Protokoll und hält die anderen Tickets nicht auf.

Bei Linear wird ein Zustand der passenden Art gesetzt — der mit der niedrigsten
Sortierposition, weil Teams oft mehrere haben („Erledigt", „Ausgeliefert").

## Vorbelegung neuer Tickets

Je Anbindung einstellbar: Projekt bzw. Team, Vorgangstyp (nur Jira, Standard
`Task`), Priorität und Zuständigkeit. Leere Felder werden **nicht** mitgeschickt —
ein leeres `assignee` ist bei Jira kein „niemand", sondern ein Prüffehler.

Die Priorität schreibt sich je Anbieter anders: Jira nimmt den Namen (`High`),
Linear eine Zahl (0–4). Die Zuständigkeit ist die Kennung des Kontos **beim
Anbieter** — eine Zuordnung zwischen den Nutzerverwaltungen gibt es nicht, und
eine über die E-Mail-Adresse geratene wäre genau die Art Vermutung, die im
Betrieb einmal den Falschen benachrichtigt.

Nichts davon wird beim Speichern gegen den Anbieter geprüft. Ein Projekt, das es
nicht gibt, meldet sich beim ersten Anlegen mit seiner eigenen Meldung — und die
ist aussagekräftiger als alles, was hier zu prüfen wäre.

## Verknüpfen von Hand

Im Formular auf der Fehlerseite darf die Kennung **ganz** stehen (`OPS-42`) oder
nur die Nummer (`42`). Ein Schlüssel, der nicht zum ausgewählten Projekt passt,
ist ein Eingabefehler und keine Nummer mit Vorsilbe: `ENG-42` im Projekt `OPS`
ist ein anderes Ticket.

## Fehler des Fremdsystems

Sie werden **wörtlich** gezeigt und nicht durch einen freundlicheren Satz
ersetzt: „Field 'priority' cannot be set" ist die Antwort auf „warum entsteht
kein Ticket". Beim Anlegen und Verknüpfen erscheinen sie am Formularfeld, beim
Abgleich in der Warteschlange im Protokoll.

Ein abgelehnter **Zugang** (`401`/`403`, bei Linear auch ein
`AUTHENTICATION_ERROR` mit Status 200) wird an der Anbindung festgehalten und auf
der Seite angezeigt — wie bei GitHub, und aus demselben Grund: sonst bliebe es
still.

## Lösen

Verknüpfte Tickets bleiben lesbar, wenn die Anbindung gelöst wird: die
Verknüpfung trägt Kennung und Adresse bei sich. Sie wird nur nicht mehr
abgeglichen. Das Ticket drüben bleibt ohnehin unberührt — auch beim Lösen einer
einzelnen Verknüpfung wird es nicht geschlossen.
