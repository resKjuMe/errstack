<?php

namespace App\Support\Ingest\Sampling;

use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Support\Facades\Cache;

/**
 * Zählt, wie oft ein Vorgang im laufenden Zeitfenster gemeldet wurde.
 *
 * Der Zähler ist die Grundlage der Mindestquote: die ersten `n` Meldungen eines
 * Vorgangs je Fenster werden behalten, unabhängig von der Quote. Ein Aufruf, der
 * seltener als `n` je Fenster vorkommt, bleibt damit vollständig sichtbar — und
 * genau das ist die Zusage, ohne die eine Stichprobe die interessanten Fälle
 * zuerst verliert.
 *
 * **Im Zwischenspeicher und nicht in der Datenbank.** Der Zähler wird bei
 * **jeder** gemeldeten Transaktion angefasst; das ist die häufigste Meldung
 * überhaupt. Eine Zeile je Vorgang und Fenster wäre ein Schreibvorgang je
 * Aufruf, und die Tabelle wüchse mit derselben Menge, die die Stichprobe gerade
 * einsparen soll.
 *
 * Was das kostet: geht der Zwischenspeicher verloren (Neustart, geleerter
 * Cache), beginnt die Zählung von vorn und es werden **mehr** Messungen
 * behalten als vorgesehen. Der Fehler geht damit in die harmlose Richtung — zu
 * viele Daten statt zu wenige — und die Hochrechnung bleibt richtig, weil eine
 * garantiert behaltene Messung mit Gewicht 1 zählt.
 */
final class WindowCounter
{
    /**
     * Wie lange ein Zähler aufbewahrt wird, in Vielfachen der Fensterbreite.
     *
     * Zwei Fenster, nicht eines: der Schlüssel enthält den Fensteranfang, ein
     * abgelaufenes Fenster wird also nie wieder gelesen. Die zusätzliche Spanne
     * ist der Spielraum für Uhren, die zwischen mehreren Arbeitern auseinander
     * laufen — ein Zähler, der eine Sekunde zu früh verfällt, würde in dieser
     * Sekunde jeden Vorgang für neu halten.
     */
    private const RETAINED_WINDOWS = 2;

    /**
     * Vermerkt eine Meldung und gibt zurück, die wievielte sie im laufenden
     * Fenster ist.
     *
     * Gezählt werden **alle** Meldungen dieses Vorgangs, nicht nur die
     * behaltenen. Das ist Absicht: die Mindestquote soll „die ersten `n` je
     * Fenster" bedeuten und nicht „die ersten `n`, bis die Quote greift".
     * Andernfalls würde bei 1 % Quote und Mindestquote 1 jede hundertste
     * Messung zusätzlich als garantiert gelten, und der ausgewiesene Durchsatz
     * fiele auf die Hälfte.
     *
     * Kann der Zwischenspeicher nicht zählen, gilt die Meldung als die erste.
     * Auch dieser Fehler geht in die harmlose Richtung.
     */
    public function reserve(Project $project, SampleTarget $target): int
    {
        $key = $this->key($project, $target);

        // Erst anlegen, dann hochzählen: manche Zwischenspeicher legen bei einem
        // `increment` auf einen fehlenden Schlüssel selbst einen an — dann aber
        // ohne Verfallszeit, und der Zähler des Fensters von heute stünde in
        // einem Jahr noch da.
        Cache::add($key, 0, Transaction::BUCKET_SECONDS * self::RETAINED_WINDOWS);

        $count = Cache::increment($key);

        return is_int($count) && $count > 0 ? $count : 1;
    }

    /**
     * Der Schlüssel: Projekt, Vorgang und Fensteranfang.
     *
     * Der Vorgang steht als Streuwert darin und nicht im Klartext — ein
     * Transaktionsname darf 200 Zeichen haben, Leerzeichen und Doppelpunkte
     * enthalten, und beides verträgt nicht jeder Zwischenspeicher.
     */
    private function key(Project $project, SampleTarget $target): string
    {
        $window = Transaction::windowFor(now()->toImmutable())->getTimestamp();

        return 'sampling:'.$project->id.':'.md5($target->group()).':'.$window;
    }
}
