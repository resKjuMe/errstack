<?php

namespace App\Support\Replays;

use App\Models\Replay;
use App\Support\Filters\GlobalFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Die Auswahl der Aufzeichnungen, aus der die Übersichtsseite entsteht.
 *
 * Die Liste beantwortet eine einzige Frage — „welche Sitzungen gab es im
 * gewählten Zeitraum, und in welchen ist etwas schiefgegangen". Sie ist der
 * Einstieg für den seltenen Fall, dass jemand ohne einen bestimmten Fehler
 * hierherkommt; der Regelweg führt von einem Fehler zu **seiner** Aufzeichnung
 * und nicht über diese Liste.
 *
 * Deshalb kein Blättern: wer die dreihundertste Sitzung von gestern sucht, sucht
 * in Wahrheit einen Fehler. Die Liste zeigt die neuesten und sagt dazu, dass sie
 * gekappt ist.
 *
 * Die schweren Spalten fasst sie nicht an — es gibt hier gar keine: die
 * Bilddaten liegen auf der Platte, und diese Zeile ist klein. Das ist der Grund,
 * aus dem die Aufteilung so gewählt wurde.
 */
final class ReplayOverview
{
    public function __construct(
        private readonly GlobalFilter $filter,
        private readonly bool $onlyWithErrors = false,
    ) {}

    /**
     * Die neuesten Aufzeichnungen im Zeitraum.
     *
     * @return Collection<int, Replay>
     */
    public function replays(int $limit): Collection
    {
        return $this->query()
            ->with('project:id,slug,name')
            ->newestFirst()
            ->limit($limit)
            ->get();
    }

    /**
     * Wie viele es im Zeitraum insgesamt sind.
     *
     * Getrennt gezählt und nicht aus der Länge der Liste abgeleitet: die Liste
     * ist gekappt, und „50 Aufzeichnungen" wäre bei einer Obergrenze von 50 eine
     * Aussage über die Obergrenze und keine über die Daten.
     */
    public function total(): int
    {
        return $this->query()->count();
    }

    /**
     * @return Builder<Replay>
     */
    private function query(): Builder
    {
        // Nach dem Beginn der Sitzung eingeschränkt und nicht nach ihrem Ende:
        // gefragt wird „welche Sitzungen gab es heute", und eine Sitzung, die um
        // 23:55 begann und um 0:10 endete, gehört zu gestern — dort hat sie
        // angefangen, und dort sucht sie jemand.
        $query = $this->filter->apply(Replay::query(), 'started_at')->playable();

        if ($this->onlyWithErrors) {
            $query->where('error_count', '>', 0);
        }

        return $query;
    }
}
