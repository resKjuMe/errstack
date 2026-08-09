<?php

namespace App\Http\Controllers;

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
 * {@see ReleaseList} weiter. Die Detailseite einer Version samt Vergleich zur
 * Vorversion ist eine eigene Aufgabe (R8) — diese Seite beantwortet nur, welche
 * Versionen es gibt und wo man hinschauen sollte.
 */
class ReleaseController extends Controller
{
    public function __invoke(ReleaseListRequest $request): InertiaResponse
    {
        $filter = $request->filter();

        $releases = ReleaseList::paginate($filter);

        return Inertia::render('releases/Index', [
            'releases' => $releases,
            'totalLabel' => Formats::number($releases->total()),
            // Die Umgebung wirkt nicht: eine Version wird als Ganzes
            // ausgeliefert und ist keine Eigenschaft einer Umgebung. Sie danach
            // zu trennen ginge nur über die Einzelereignisse. Statt die Auswahl
            // still zu übergehen, sagt die Seite es — wie die Fehlerliste.
            'environmentIgnored' => $filter->environment !== null,
        ]);
    }
}
