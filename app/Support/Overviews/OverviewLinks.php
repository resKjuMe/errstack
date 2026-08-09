<?php

namespace App\Support\Overviews;

use App\Support\Filters\FilterQuery;
use App\Support\Filters\GlobalFilter;

/**
 * Die Wege aus einer Übersicht heraus.
 *
 * **Jede Zahl ist ein Link, und das ist die halbe Aufgabe.** Eine Übersicht
 * beantwortet keine Frage zu Ende — sie sagt, wo etwas los ist. Eine Zahl ohne
 * Weg dahinter zwingt jeden dazu, die Auswahl in der Detailansicht von Hand
 * nachzubauen, und dabei entsteht regelmäßig ein anderer Ausschnitt als der,
 * über den er gerade gestaunt hat.
 *
 * **Deshalb trägt jeder Weg den Filter mit.** Der Zeitraum und die Umgebung
 * gelten in der Zielansicht weiter; nur die Projektauswahl darf sich verengen,
 * wenn die Zahl für genau ein Projekt galt.
 */
final class OverviewLinks
{
    /**
     * Eine Adresse mit dem Zustand der Filterleiste im Rücken.
     *
     * @param  array<string, mixed>  $parameters  Pfad-Parameter der Route
     * @param  list<string>|null  $projects  Projekt-Kürzel, auf die der Link zeigen
     *                                       soll — `null` übernimmt die Auswahl
     *                                       der Leiste unverändert
     */
    public static function to(
        string $route,
        array $parameters,
        GlobalFilter $filter,
        ?array $projects = null,
    ): string {
        $href = route($route, $parameters);
        $query = FilterQuery::build($filter, $projects);

        return $query === '' ? $href : $href.'?'.$query;
    }
}
