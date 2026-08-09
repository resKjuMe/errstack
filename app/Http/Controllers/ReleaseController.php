<?php

namespace App\Http\Controllers;

use App\Enums\ReleaseSort;
use App\Http\Requests\ReleaseListRequest;
use App\Support\Formats;
use App\Support\Releases\ReleaseList;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Versionsliste: welche Auslieferungen es gab und was mit ihnen
 * dazugekommen ist.
 *
 * Wie die Fehlerliste hängt sie nicht an einem Projekt in der Adresszeile —
 * welche Projekte gemeint sind, sagt die globale Filterleiste (F7).
 *
 * Der Controller rechnet nichts; er liest die Adresszeile und reicht sie an
 * {@see ReleaseList} weiter.
 */
class ReleaseController extends Controller
{
    public function __invoke(ReleaseListRequest $request): InertiaResponse
    {
        $filter = $request->filter();
        $sort = $request->sort();

        $releases = ReleaseList::paginate($filter, $sort);

        return Inertia::render('releases/Index', [
            'releases' => $releases,
            'sort' => $sort->value,
            'sortOptions' => ReleaseSort::options(),
            'totalLabel' => Formats::number($releases->total()),
            // Die Umgebung entscheidet nicht, **welche** Versionen hier stehen:
            // eine Version wird als Ganzes ausgeliefert und ist keine
            // Eigenschaft einer Umgebung; sie danach zu trennen ginge nur über
            // die Einzelereignisse. Auf die Kennzahlen daneben wirkt sie sehr
            // wohl — Sitzungen gehören zu einer Umgebung (R7). Statt die Auswahl
            // still halb zu übergehen, sagt die Seite genau das.
            'environmentPartial' => $filter->environment !== null,
        ]);
    }
}
