<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlobalFilterRequest;
use App\Support\FilterData;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Übersichtsseite. Sie ist die erste Seite mit der globalen Filterleiste und
 * zeigt vorläufig nur, worauf der Filter gerade zeigt — die Auswertungen selbst
 * kommen mit den folgenden Phasen und nutzen denselben Filter.
 */
class DashboardController extends Controller
{
    public function __invoke(GlobalFilterRequest $request): InertiaResponse
    {
        $filter = $request->filter();

        return Inertia::render('Dashboard', [
            'filter' => FilterData::bar($filter),
            'selection' => [
                'projects' => $filter->projects->pluck('name')->values()->all(),
                'environment' => $filter->environment,
                'rangeLabel' => $filter->rangeLabel(),
            ],
        ]);
    }
}
