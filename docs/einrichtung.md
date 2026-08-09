# Der Einrichtungs-Assistent

Vom angelegten Projekt zum ersten Fehler — ohne dass jemand vorher wissen muss,
was eine DSN ist.

Der Assistent liegt unter
`/einstellungen/organisationen/{organisation}/projekte/{projekt}/einrichtung`. Dorthin
verweist das Anlegen eines Projekts unmittelbar, und in den Projekt-Einstellungen
steht der Weg zurück: der Ablauf ist **jederzeit erneut** aufrufbar. Das ist kein
Zugeständnis an Vergessliche — eine zweite Anwendung an dasselbe Projekt
anzuschließen ist der Normalfall, und ein Assistent, der nur einmal aufgeht,
wäre dafür wertlos.

## Aufbau

| Schritt | was passiert |
| --- | --- |
| 1. Technik wählen | die Anleitung, nicht die Plattform des Projekts (siehe unten) |
| 2. SDK einbinden | installieren, einstellen, Testfehler auslösen — mit eingesetzter DSN |
| 3. Warten | der Bildschirm erkennt die erste Meldung von selbst |
| Hilfe | die häufigsten Ursachen, wenn nichts ankommt — und was verworfen wurde |

## Kein gespeicherter Fortschritt

Der Assistent hat keinen eigenen Zustand. Welche Anleitung gewählt ist, steht in
der Adresszeile (`?anleitung=php-laravel`); wie weit die Einrichtung ist, steht
in den Daten selbst — nämlich in der Frage, ob je eine Meldung angenommen wurde.

Ein Fortschrittsfeld in der Datenbank wäre die naheliegende und falsche
Alternative: es veraltet in dem Moment, in dem jemand eine zweite Anwendung
anschließt, und es müsste zurückgesetzt werden, damit der Ablauf erneut
aufrufbar bleibt — also genau das, was die Daten ohnehin von selbst beantworten.

## Anleitung ≠ Plattform

`App\Enums\Platform` ist eine Sortierhilfe für die Projektliste („PHP",
„JavaScript"). Eine Anleitung muss dagegen sagen, welches Paket installiert wird
und wohin die DSN gehört, und das ist bei Laravel ein anderer Text als bei einem
PHP-Skript und bei React ein anderer als beim nackten Browser. Deshalb gibt es
`App\Support\Setup\SetupGuide` daneben — feiner geschnitten und ausschließlich
für den Assistenten.

Die Plattform des Projekts entscheidet nur die **Vorauswahl**, nicht das
Angebot: ein als „PHP" angelegtes Projekt darf sein erstes Ereignis aus dem
Browser schicken, und die Auswahl im Assistenten ändert die Einstellung des
Projekts nicht.

## Woher die Beispiele stammen

Ausschließlich die offiziellen SDKs, unverändert, mit getauschter DSN — das ist
die Zusage aus [`docs/compat/`](compat/README.md), und der Assistent ist die
Stelle, an der sie jemandem begegnet. Der Code steht in `SetupGuide::steps()`
und wird **nicht** übersetzt; die Texte drumherum stehen in
`lang/<sprache>/setup.php`.

Wer in `docs/compat/` eine SDK-Fassung anhebt, sieht hier nach: die Beispiele
sind an den dort nachgewiesenen abgenommen.

## Wie der Wartebildschirm merkt, dass etwas ankam

Zwei Wege, und beide werden gebraucht:

- **Der Broadcast** (`issue.created`, derselbe Kanal wie die Fehlerliste) ist das
  Klingelzeichen — er kommt in dem Moment, in dem der erste Fehler entsteht.
- **Die Abfrage** von `…/einrichtung/stand` alle drei Sekunden ist der Boden,
  auf dem der Bildschirm steht. Der Websocket-Server ist optional, und der
  Broadcast entsteht erst nach der Auswertung in der Warteschlange — gerade in
  einer frischen Installation ist beides oft noch nicht in Betrieb.

Gemeldet wird die **angenommene** Meldung (`ingest_payloads`), nicht der fertige
Fehlereintrag: der Datensatz entsteht beim Annehmen, der Eintrag erst danach in
der Warteschlange. Auf den Eintrag zu warten hieße, bei stehendem Queue-Worker
minutenlang „nichts angekommen" anzuzeigen, obwohl die Meldung längst da ist —
und den Einrichtenden nach der falschen Ursache suchen zu lassen. Der Verweis
auf den Fehler kommt nach, sobald es ihn gibt.

## Die Hilfe zeigt, was verworfen wurde

Der wertvollste Teil der Hilfe ist keine Liste allgemeiner Ursachen, sondern die
Zählung aus `ingest_discards` der letzten 24 Stunden. Sie unterscheidet zwei
Probleme, die nichts miteinander zu tun haben:

- **nichts gesendet** — DSN falsch, Firewall zu, Prozess vor dem Senden beendet;
- **gesendet und abgewiesen** — Schlüssel abgeschaltet, Eingangsfilter,
  Stichprobe, ein `before_send`, das `null` zurückgibt.

Steht dort eine Zahl, ist die Verbindung nachweislich in Ordnung und die
allgemeine Ursachenliste führt in die Irre. Was ein SDK selbst verworfen hat,
behält dessen eigene Bezeichnung (`queue_overflow`, `before_send` …) — die Liste
wächst mit jeder SDK-Fassung, und ein erfundener deutscher Name dafür wäre nicht
wiederzuerkennen.

## Rechte

Dasselbe wie für die Client-Schlüssel (`manageKeys`): die Seite zeigt die DSN im
Klartext, und sie ist der Zugang zur Datenaufnahme. Wer sie nicht verwalten darf,
bekommt in den Projekt-Einstellungen auch den Weg dorthin nicht angeboten.
