<?php

namespace App\Support\Ingest\Spikes;

use App\Models\IngestVolume;
use App\Models\Project;
use App\Support\Ingest\Sampling\WindowCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Zählt die laufende Minute: wie viele Ereignisse angekommen sind und wie viele
 * davon die Drosselung verworfen hat.
 *
 * **Im Zwischenspeicher und nicht in der Datenbank** — derselbe Grund wie beim
 * Zähler der Stichprobe ({@see WindowCounter}),
 * nur schärfer: dieser Zähler wird bei **jedem** Ereignis angefasst, und er
 * wird gerade dann angefasst, wenn eine fehlerhafte Auslieferung Millionen
 * davon erzeugt. Ein Schreibvorgang je Ereignis machte den Schutz gegen die
 * Flut zu ihrem größten Verstärker.
 *
 * Festgeschrieben wird einmal je Minute vom Durchlauf ({@see SpikeSweep}), der
 * die abgeschlossene Minute abholt und als Zeile in {@see IngestVolume}
 * ablegt.
 *
 * Was das kostet: geht der Zwischenspeicher verloren (Neustart, geleerter
 * Cache), fehlt eine Minute im Verlauf und eine laufende Spitze wird eine
 * Minute später erkannt. Der Fehler geht damit in die harmlose Richtung — zu
 * spät gedrosselt statt zu früh —, und genau so soll es sein: ein Schutz, der
 * bei einem Cache-Ausfall grundlos zu drosseln beginnt, wäre schlimmer als die
 * Spitze.
 */
final class SpikeCounter
{
    /**
     * Wie lange ein Minutenzähler aufbewahrt wird.
     *
     * Fünf Minuten für einen Wert, der nach einer gebraucht wird: der Durchlauf
     * holt die abgeschlossene Minute ab, sobald die nächste begonnen hat. Die
     * Spanne darüber ist der Spielraum für einen Zeitplan, der einmal nicht
     * pünktlich anläuft — ohne sie wäre die Menge dieser Minute im Verlauf
     * schlicht eine Null, und eine Null im Verlauf senkt den Vergleichswert.
     */
    private const TTL_SECONDS = 300;

    /**
     * Vermerkt ein angekommenes Ereignis und gibt zurück, das wievielte es in
     * dieser Minute ist.
     *
     * Gezählt werden **alle** Ereignisse, auch die anschließend verworfenen:
     * die Zahl soll sagen, wie viel eine Anwendung gemeldet hat, und nicht, wie
     * viel davon wir behalten haben. Andernfalls sähe eine Drosselung im
     * Verlauf aus wie eine Anwendung, die sich beruhigt hat.
     */
    public function count(Project $project, ?Carbon $at = null): int
    {
        return $this->increment($this->key('events', $project, $at));
    }

    /**
     * Vermerkt ein von der Drosselung verworfenes Ereignis.
     */
    public function countDiscarded(Project $project, ?Carbon $at = null): void
    {
        $this->increment($this->key('dropped', $project, $at));
    }

    /**
     * Holt die Zahlen einer Minute ab und räumt sie weg.
     *
     * Abholen und Wegräumen in einem Schritt: bliebe der Zähler stehen, würde
     * ein doppelt angelaufener Durchlauf dieselbe Minute ein zweites Mal
     * verbuchen — und die verworfenen Ereignisse stünden doppelt in der
     * Statistik.
     *
     * @return array{events: int, discarded: int}
     */
    public function take(Project $project, Carbon $bucket): array
    {
        return [
            'events' => $this->pull($this->key('events', $project, $bucket)),
            'discarded' => $this->pull($this->key('dropped', $project, $bucket)),
        ];
    }

    /**
     * Die Zahlen der laufenden, noch nicht abgeschlossenen Minute — für die
     * Anzeige, die währenddessen jemand aufruft.
     *
     * @return array{events: int, discarded: int}
     */
    public function peek(Project $project, ?Carbon $at = null): array
    {
        return [
            'events' => (int) Cache::get($this->key('events', $project, $at), 0),
            'discarded' => (int) Cache::get($this->key('dropped', $project, $at), 0),
        ];
    }

    private function increment(string $key): int
    {
        // Erst anlegen, dann hochzählen: manche Zwischenspeicher legen bei einem
        // `increment` auf einen fehlenden Schlüssel selbst einen an — dann aber
        // ohne Verfallszeit, und der Zähler dieser Minute stünde in einem Jahr
        // noch da.
        Cache::add($key, 0, self::TTL_SECONDS);

        $count = Cache::increment($key);

        return is_int($count) && $count > 0 ? $count : 1;
    }

    private function pull(string $key): int
    {
        $value = Cache::pull($key);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function key(string $kind, Project $project, ?Carbon $at): string
    {
        return 'spike:'.$kind.':'.$project->id.':'.IngestVolume::bucket($at)->getTimestamp();
    }
}
