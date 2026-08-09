<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlobalFilterRequest;
use App\Support\Tags\TagAggregates;
use App\Support\Tags\TagFacets;
use App\Support\Tags\TagLinks;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Merkmale der gewählten Projekte — dieselbe Auswertung wie am einzelnen
 * Fehler, eine Ebene höher.
 *
 * Sie beantwortet die Frage, die am einzelnen Fehler nicht zu stellen ist:
 * „welche Browser kommen hier überhaupt vor?" Erst davor wird die Verteilung
 * eines Fehlers zur Aussage — 80 % Chrome sind nichts Besonderes, wenn 80 % der
 * Meldungen aus Chrome kommen, und ein Befund, wenn es sonst 5 % sind.
 *
 * Wie die Fehlerliste hängt sie nicht an einem Projekt in der Adresszeile:
 * welche Projekte gemeint sind, sagt die globale Filterleiste, und die kann
 * eines, mehrere oder alle meinen. Über mehrere Projekte hinweg werden die
 * Zähler summiert.
 */
class TagController extends Controller
{
    public function index(GlobalFilterRequest $request): InertiaResponse
    {
        $filter = $request->filter();
        $projectIds = $filter->projectIds();

        return Inertia::render('tags/Index', [
            'facets' => TagLinks::decorate(
                TagFacets::forProjects($projectIds),
                $filter,
                fn (string $key): string => route('tags.show', [$key] + $filter->formValues()),
            ),
            'detail' => null,
            'overviewHref' => route('tags.index', $filter->formValues()),
            'valueLimit' => TagAggregates::MAX_VALUES_PER_KEY,
        ]);
    }

    public function show(GlobalFilterRequest $request, string $key): InertiaResponse|Response
    {
        $filter = $request->filter();
        $detail = TagFacets::forProjectsKey($filter->projectIds(), $key);

        if ($detail === null) {
            abort(404);
        }

        return Inertia::render('tags/Index', [
            'facets' => [],
            'detail' => TagLinks::decorateOne($detail, $filter),
            'overviewHref' => route('tags.index', $filter->formValues()),
            'valueLimit' => TagAggregates::MAX_VALUES_PER_KEY,
        ]);
    }
}
