# Anbindungen

Eine Anbindung verbindet eine Organisation mit dem Ort, an dem ihr Code liegt.
Zurzeit gibt es eine: **GitHub**.

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
