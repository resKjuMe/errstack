<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlobalFilterRequest;
use App\Support\Overviews\OrganizationOverview;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Übersichtsseite der Organisation — der Einstieg nach dem Anmelden.
 *
 * **Sie liefert das Raster, nicht die Zahlen.** Jede Kachel holt ihre Zahlen
 * über eine eigene Adresse; fünf Kacheln sind fünf Anfragen, die der Browser
 * nebeneinander stellt, und die Seite steht schon, während sie laufen. Eine
 * Antwort, die alle fünf Auswertungen enthielte, wäre serverseitig eine
 * Schleife — und der Bildschirm bliebe so lange leer wie ihre Summe dauert.
 * Dieselbe Aufteilung wie bei den Dashboards (D4), und aus demselben Grund.
 *
 * **Sie rechnet nicht selbst.** Was hier steht, kommt aus dem Motor (D1) über
 * {@see OrganizationOverview}; eine zweite Auswertungslogik neben ihm wäre
 * genau die Stelle, an der Übersicht und Detailansicht anfangen, sich zu
 * widersprechen.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly OrganizationOverview $overview = new OrganizationOverview) {}

    public function index(GlobalFilterRequest $request): InertiaResponse
    {
        return Inertia::render('Dashboard', OrganizationOverview::frame($request->filter()));
    }

    /**
     * Die Zahlen einer Kachel.
     *
     * JSON und keine Inertia-Antwort: es geht um einen Ausschnitt einer
     * stehenden Seite und nicht um einen Seitenwechsel.
     */
    public function panel(GlobalFilterRequest $request, string $panel): JsonResponse
    {
        return response()->json([
            'panel' => $this->overview->panel($panel, $request->filter()),
        ]);
    }
}
