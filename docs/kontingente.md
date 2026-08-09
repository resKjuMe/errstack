# Kontingente und Ratenbegrenzung

Ein einzelnes fehlerhaftes Projekt kann eine ganze Installation mit Daten
überfluten: eine Schleife, die bei jedem Durchlauf meldet, ein SDK ohne
Stichprobe, ein Lasttest gegen die Produktion. Ohne Grenze bezahlt das die
gesamte Organisation — mit Plattenplatz, mit Antwortzeiten und damit, dass der
eine Fehler, um den es ging, in Millionen gleichartiger Zeilen untergeht.

Kontingente begrenzen, was hereinkommt. Überschrittenes wird **gebremst,
gezählt und gemeldet** — in dieser Reihenfolge, denn eine Grenze, deren Wirkung
man nicht sieht, ist eine stille Lücke in den Daten.

## Zwei Stufen, und die Reihenfolge ist der Punkt

```
POST /api/{projekt}/store/
  ├ ingest.throttle        grob, je Herkunft — VOR der Anmeldung
  ├ ingest.key             Client-Schlüssel prüfen
  ├ ingest.quota:errors    Kontingente des erkannten Schlüssels
  └ Controller             ablegen, Job einreihen
```

Die grobe Bremse steht **vor** der Anmeldung. Die feinen Kontingente hängen an
einem gültigen Schlüssel — wer keinen hat, käme sonst an ihnen vorbei und dürfte
unbegrenzt Schlüssel durchprobieren. Sie zählt deshalb je Absender-Adresse und
mitgeschicktem Schlüssel, ohne die Datenbank anzufassen
(`INGEST_MAX_REQUESTS_PER_MINUTE`, Vorgabe 5000; `0` schaltet sie ab).

Beim Envelope läuft die zweite Stufe **ohne** Datenart: was darin steckt, weiß
vor dem Zerlegen niemand. Geprüft wird dort nur die Rate des Schlüssels, die
Kontingente je Datenart fallen Element für Element an
(`App\Support\Ingest\EnvelopeIntake`). Genau daraus folgt die wichtigste Zusage:
**ein aufgebrauchtes Transaktions-Kontingent nimmt die Fehlermeldung daneben
nicht mit.**

## Drei Ebenen nebeneinander

| Ebene | Wo eingestellt | Gilt für |
|---|---|---|
| Organisation | `/organisationen/{organisation}/kontingente` | alle Projekte zusammen, je Datenart |
| Projekt | `…/projekte/{projekt}/kontingente` | dieses Projekt, je Datenart |
| Client-Schlüssel | Schlüssel-Seite (`rate_limit_per_minute`) | alles, was über ihn hereinkommt |

Sie verfeinern sich nicht, sie gelten nebeneinander — die **engste** Grenze
entscheidet. Ein Projekt mit großzügigem eigenem Kontingent wird trotzdem
abgewiesen, wenn die Organisation am Ende ist; deshalb steht deren Verbrauch mit
auf der Projektseite.

Der Schlüssel ist als einziger ohne Datenarten: er ist die Notbremse für eine
einzelne durchdrehende Anwendung und soll nicht erst greifen, wenn jemand die
richtige Datenart erraten hat.

## Fünf Datenarten

`App\Enums\QuotaCategory` — gröber geschnitten als die Element-Typen des
Envelope, weil ein Kontingent eine Betreiber-Entscheidung ist und keine Aussage
über Envelope-Elemente:

| Datenart | Umfasst |
|---|---|
| Fehler | `event`, `/store/`, Sicherheitsberichte des Browsers |
| Transaktionen | `transaction` — und das Profil, das daran hängt |
| Aufzeichnungen | `replay_event` und `replay_recording` zusammen |
| Anhänge | `attachment` |
| Cronjob-Lebenszeichen | `check_in` |

Was **nicht** dabei ist, ist ebenso Absicht: Sitzungen, Verworfen-Meldungen des
SDK und Rückmeldungen betroffener Personen zählen gegen nichts. Sie sind Angaben
**über** Ereignisse, die bereits gezählt wurden.

## Die Antwort an das SDK

Abgewiesen wird mit `429` und einer Wartezeit in `Retry-After` — und die ist der
eigentliche Inhalt der Antwort. Ohne sie versucht ein SDK es nach seinem eigenen
Zeitplan wieder, und der ist bei einer Fehlerwelle „gleich wieder".

| Fall | `Retry-After` |
|---|---|
| Rate gerissen | Sekunden bis zum Ende der laufenden Minute |
| Monatskontingent aufgebraucht | Sekunden bis zum Monatsersten |

Die zweite Angabe ist unbequem und ehrlich: wer sein Monatskontingent am
Zwölften verbraucht hat, wartet nicht zwölf Sekunden. Eine kürzere Zahl wäre
freundlicher und würde dazu führen, dass ein SDK bis zum Monatsende alle paar
Sekunden anklopft.

## Gezählt wird im Zwischenspeicher

Der Verbrauch steht in Redis bzw. dem eingerichteten Cache, nicht in der
Datenbank (`App\Support\Ingest\Quotas\QuotaCounter`). Gezählt wird bei **jeder**
eingehenden Meldung, und eine Meldung kommt in genau der Lage herein, in der die
überwachte Anwendung ohnehin Mühe hat — ein Schreibvorgang je Meldung wäre eine
zweite Last neben der Ablage selbst.

Der Preis: geht der Zwischenspeicher verloren, beginnt die Zählung von vorn und
es kommt **mehr** herein als vorgesehen. Der Fehler geht in die harmlose
Richtung — zu viele Daten statt einer Anwendung, die stumm bleibt, weil ein
Neustart ihren Zähler auf „aufgebraucht" gestellt hat.

Was nicht verloren gehen darf, steht deshalb in der Datenbank: die Grenzen
(`quotas`), die Zählung des Verworfenen (`ingest_discards`) und der Vermerk,
welche Warnung schon hinausging.

Was eine Grenze abgewiesen hat, wird **nicht** gebucht. Sonst bliebe ein
Projekt, das einmal darüber war, bis zum Monatsersten stumm, auch wenn die
Grenze längst angehoben ist.

## Warnungen bei 80 % und 100 %

Beide werden gebraucht. Bei 80 % ist noch Zeit, das Kontingent anzuheben oder
eine gesprächige Anwendung zu drosseln; bei 100 % ist die Nachricht keine
Warnung mehr, sondern die Erklärung dafür, dass gerade nichts mehr ankommt. Wer
nur die zweite verschickt, erfährt vom Problem, wenn er es nicht mehr abwenden
kann.

Je Schwelle **einmal** im Monat, vermerkt an der Kontingent-Zeile — geprüft wird
bei jeder aufgenommenen Meldung, und ohne diese Zusage käme eine Nachricht je
Ereignis. Empfänger sind Eigentümer und Verwaltung der Organisation, über die
üblichen persönlichen Einstellungen (`NotificationEventType::QuotaWarning`).

Eine geänderte Grenze setzt den Vermerk zurück: wer von 100.000 auf 500.000
anhebt, hat gerade entschieden, dass die Meldung von gestern erledigt ist — und
will bei 400.000 wieder eine bekommen.

## Was verworfen wurde, steht auf derselben Seite

Die Kontingent-Seite zeigt unter den Grenzen die Zählung der letzten 30 Tage
nach Grund — `rate_limited` und `quota_exceeded` aus dieser Stufe, daneben
`filtered` (Eingangsfilter), `sampled` (Stichprobe) und die Gründe, die das SDK
selbst gemeldet hat. Ohne diese Tabelle wäre die Antwort auf „warum fehlen seit
gestern Meldungen?" eine `429` in einem Protokoll, das niemand liest.

Siehe auch [eingangsfilter.md](eingangsfilter.md) für das Rauschen, das schon
vorher wegfällt, und [betrieb.md](betrieb.md) für den Betrieb im Übrigen.
