# Persönliche Benachrichtigungs-Einstellungen

Wer benachrichtigt, fragt **eine** Stelle: `App\Notifications\NotificationPreferences`.
Sie beantwortet, ob eine Meldung einen bestimmten Nutzer auf einem bestimmten
Weg erreichen darf. Gäbe es zwei solche Stellen, wäre eine davon irgendwann
anderer Meinung — und ein abbestellter Nutzer bekäme trotzdem Post.

## Die drei Achsen

| Achse | Werte | Enum |
| --- | --- | --- |
| Anlass | Alarme, Zuweisungen, Erwähnungen, Workflow-Änderungen, Deploys, Wochenbericht, Kontingent-Warnungen | `NotificationEventType` |
| Weg | E-Mail, in der Anwendung | `NotificationTransport` |
| Bereich | überall, je Organisation, je Projekt | `PreferenceScope` |

Nicht zu verwechseln mit den **Benachrichtigungswegen einer Organisation**
(`NotificationChannel`, Phase A1): die gehören der Organisation und gehen an
feste Adressen — Slack-Räume, Verteiler, Webhooks. Hier geht es um die Person.

## Auflösung

Vom feinsten Bereich zum gröbsten, der erste ausdrückliche Eintrag gewinnt:

```
project:34  →  organization:12  →  global  →  Vorgabe des Anlasses
```

Gespeichert wird nur, was ausdrücklich eingestellt wurde. „Erbt" löscht die
Zeile, statt einen Wert festzuschreiben — nur so wirkt eine später geänderte
Vorgabe für alle, die nichts entschieden haben.

## Kritische Alarme

`NotificationEventType::Alert` ist kritisch. Zwei Dinge folgen daraus:

- **Ruhezeiten und die pauschale Abmeldung übergehen ihn.** Wer nachts nicht
  gestört werden will, hat damit nicht die Bereitschaft abgeschaltet.
- **Ausdrücklich abschalten geht trotzdem** — aber sichtbar: die Übersicht
  meldet jeden Bereich, in dem kein einziger Weg mehr aktiv ist, und der
  Abmelde-Link aus der Mail bietet dafür keinen Ein-Klick-Schalter.

## Abmelden aus der Mail

Jede persönliche Mail trägt einen signierten Link
(`App\Notifications\UnsubscribeLink`) und die Kopfzeile `List-Unsubscribe`.
Der Aufruf **zeigt** nur — verändert wird erst auf Klick, weil Virenscanner und
Vorschau-Funktionen Adressen aus Mails ungefragt öffnen.

Die Abmeldung wirkt sofort: `DeliverPersonalNotification` fragt die Erlaubnis
unmittelbar vor dem Versand erneut ab, nicht nur beim Einreihen. Bereits
wartende Mails gehen damit nicht mehr raus.

## Verwendung

```php
// Eine Person, ein Anlass, im Zusammenhang eines Projekts.
$dispatcher->sendToUser($user, $message, NotificationEventType::Alert, $project);

// Mehrere Personen — jede wird einzeln gefragt.
$dispatcher->sendToUsers($team, $message, NotificationEventType::Alert, $project);
```

Zurück kommen die erlaubten Wege. Die E-Mail reiht der Versand selbst ein; wer
einen weiteren Weg umsetzt (Postfach in der Anwendung), liest den Rest und
fragt für seinen eigenen Weg dieselbe Stelle:

```php
$preferences->allows($user, $event, NotificationTransport::InApp, $project);
```
