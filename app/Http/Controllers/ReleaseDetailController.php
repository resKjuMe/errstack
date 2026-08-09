<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReleaseDetailRequest;
use App\Models\Release;
use App\Support\FilterData;
use App\Support\Releases\ReleaseDetail;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Detailseite einer Auslieferung — was in ihr steckt und wie sie ausgegangen
 * ist.
 *
 * Anders als die Liste hängt sie an einer Kennung in der Adresszeile und hat
 * damit keine Vorauswahl über die Filterleiste. Deshalb steht die Rechteprüfung
 * hier ausdrücklich, wie bei der Fehler-Detailseite.
 *
 * Die Leiste steht trotzdem auf der Seite, aber ohne Projektauswahl: Zeitraum
 * und Umgebung gelten für jede Kennzahl hier (F7), das Projekt steht durch die
 * Version fest.
 *
 * Der Controller rechnet nichts; was auf der Seite steht, stellt
 * {@see ReleaseDetail} zusammen.
 */
class ReleaseDetailController extends Controller
{
    public function __invoke(ReleaseDetailRequest $request, Release $release): InertiaResponse
    {
        Gate::authorize('view', $release);

        $filter = $request->filter();

        return Inertia::render('releases/Show', [
            'filter' => FilterData::bar($filter, projects: false),
            ...ReleaseDetail::present($release, $filter),
        ]);
    }
}
