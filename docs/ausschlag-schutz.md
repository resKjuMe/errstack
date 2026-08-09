# Ausschlag-Schutz

Eine fehlerhafte Auslieferung erzeugt in Minuten mehr Meldungen als ein Projekt
sonst in Wochen: eine Schleife, die bei jedem Durchlauf meldet, ein Aufruf, der
in jeder Antwort scheitert. Ohne Deckel läuft davon der Speicher voll, das
Kontingent ist bis zum Monatsende aufgebraucht, und die Verarbeitung kommt
tagelang nicht hinterher — wegen **eines** Fehlers, der nach der ersten Meldung
bekannt war.

Der Ausschlag-Schutz erkennt so eine Flut, drosselt die Aufnahme und meldet das
dem Team. Er ist je Projekt einzuschalten und steht ab Werk auf **aus**: er
wirft Meldungen weg, und das darf keine Überraschung sein.

## Erkannt wird am Verlauf, nicht an einer Zahl

Ein fester Absolutwert wäre für jedes Projekt der falsche — zehntausend
Meldungen je Minute sind bei der einen Anwendung Normalbetrieb und bei der
anderen der Vorfall. Gemessen wird deshalb gegen den eigenen Verlauf:

| Größe | Woher |
|---|---|
| Verlaufswert | Median der letzten 180 gemessenen Minuten (`App\Support\Ingest\Spikes\SpikeBaseline`) |
| Schwelle | Verlaufswert × Faktor, mindestens die eingestellte Untergrenze |
| Auslösung | eine Minute über der Schwelle |
| Entwarnung | zwei Minuten in Folge unter der halben Schwelle |

Drei Entscheidungen daran sind wichtiger, als sie aussehen:

- **Median statt Mittelwert.** Der Mittelwert wäre von genau dem Ausschlag
  verdorben, den wir suchen: eine Minute mit einer Million Meldungen hebt den
  Durchschnitt einer Stunde so weit, dass die nächste Flut als normal durchgeht.
- **Gedrosselte Minuten zählen nicht mit.** Sonst hübe eine lange Spitze ihren
  eigenen Maßstab an, bis sie als normal gilt — der Schutz schaltete sich selbst
  ab.
- **Ohne genug Verlauf wird nicht gedrosselt.** Solange weniger als 15 gemessene
  Minuten vorliegen, entscheidet der Schutz gar nicht. Ein Vergleichswert aus
  einer Handvoll Minuten ist keine Aussage über den Normalbetrieb, und die
  Alternative wäre, ein frisch eingeschaltetes Projekt in der dritten Minute zu
  drosseln.

Die Untergrenze ist der Schutz des Schutzes: bei einem Verlaufswert von zwei
Meldungen je Minute wäre das Fünffache zehn — ein ruhiges Projekt läge beim
ersten kurzen Ausschlag in der Drosselung.

## Was die Drosselung tut

Getroffen wird, was gegen das Ereignis-Kontingent zählt
(`App\Enums\IngestType::countsTowardEventQuota()`): Fehler, Transaktionen,
Sitzungen, Aufzeichnungen, Profile. Ein Lebenszeichen eines Cronjobs, ein Anhang
und die Verworfen-Meldung eines SDK gehen weiter durch — ausgerechnet die
Auskunft darüber wegzuwerfen, während wir wegwerfen, wäre widersinnig.

Die Antwort an das SDK bleibt eine **200 mit Kennung**. Ein SDK, das eine
Ablehnung sieht, versucht es erneut — und eine Wiederholung ist das Letzte, was
eine Anwendung braucht, die gerade zu viel meldet.

**Verworfen wird nie stillschweigend.** Jedes gedrosselte Ereignis wird gezählt
und taucht zweimal auf: als Verwerfung mit dem Grund `throttled`
(`ingest_discards`) und in der Bilanz des Vorfalls
(`spike_protection_states.discarded`). Beides steht auf der Projektseite
„Ausschlag-Schutz".

## Warum das nichts kostet

Der Schutz liegt auf dem heißesten Weg der Anwendung — er wird bei jedem
Ereignis gefragt, und zwar gerade dann, wenn es Millionen davon sind. Er kommt
deshalb ohne Datenbankzugriff aus:

```
Aufnahme (je Ereignis)          Durchlauf (je Minute, spikes:sweep)
  ├ Zustand lesen   Cache         ├ Minute abholen        Cache → ingest_volumes
  ├ Zähler +1       Cache         ├ Verworfenes verbuchen ingest_discards + Vorfall
  └ Entscheidung    —             ├ Verlaufswert neu bilden
                                  └ Entwarnung, wenn ruhig
```

Eine Zeile je verworfenem Ereignis wäre genau der Schreibsturm, den die
Drosselung verhindern soll. Gezählt wird deshalb im Zwischenspeicher, und
festgeschrieben wird einmal je Minute.

Die einzige Ausnahme ist das Auslösen selbst: dort entsteht eine Zeile und geht
eine Benachrichtigung hinaus — einmal je Vorfall, gegen den Wettlauf paralleler
Anfragen gesperrt.

Läuft der Zeitplan nicht, wächst kein Verlauf, und ohne Verlauf drosselt der
Schutz nicht. Eine **bereits laufende** Drosselung endet dann allerdings auch
nicht von selbst — dafür gibt es den Knopf.

## Aufheben von Hand

„Ich weiß, was da passiert, lass es durch": die laufende Drosselung wird
beendet, und für die eingestellte Ruhefrist wird nicht erneut gedrosselt. Ohne
diese Frist wäre der Knopf wirkungslos — die Flut läuft ja weiter, und die
nächste Minute löste sofort wieder aus.

Das ist ausdrücklich **nicht** dasselbe wie das Abschalten des Schutzes: der
Schalter gilt für die Zukunft, das Aufheben für genau diesen Vorfall. Wer beides
in einen Schalter legte, zwänge jeden, der einmal durchlassen will, den Schutz
dauerhaft abzuschalten.

Wer aufgehoben hat, steht am Vorfall — beim Nachlesen ist das die erste Frage.

## Meldungen

Zwei gehen hinaus, beide an die Kanäle der Organisation und beide dringend
(nie gebündelt):

- **Auslösung** — mit der beobachteten Menge, der Schwelle und dem üblichen
  Verlauf. Wer entscheiden soll, ob er aufsteht, braucht die Zahlen und nicht
  nur „es hat ausgelöst".
- **Ende** — von selbst oder von Hand, mit der Zahl der verworfenen Ereignisse.
  Wer nur das Auslösen meldet, lässt jeden Empfänger im Glauben, es werde immer
  noch verworfen.

## Einstellungen

`/organisationen/{organisation}/projekte/{projekt}/ausschlagschutz` — ansehen
darf jedes Mitglied, ändern und aufheben die Verwaltung.

| Einstellung | Vorgabe | Bedeutung |
|---|---|---|
| Ausschlag-Schutz | aus | Ohne ihn wird weder gedrosselt noch ein Verlauf mitgeschrieben. |
| Faktor | 5 | Ab dem Wievielfachen des Verlaufswerts eine Minute als Spitze gilt. |
| Untergrenze | 500 | Darunter wird nie gedrosselt. |
| Ruhefrist | 15 min | Wie lange nach einem Aufheben von Hand Ruhe ist. |

Die Seite zeigt daneben den Verlauf der letzten Stunde, den laufenden Vorfall
und die früheren Auslösungen mit ihren Zahlen. Die Schwelle allein wäre eine
Zahl ohne Bezug: „ab 4.500 je Minute" beantwortet die Frage „ist das viel?"
erst, wenn die tatsächlichen Minuten daneben stehen.
