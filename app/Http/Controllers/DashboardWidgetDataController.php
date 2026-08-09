<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlobalFilterRequest;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Support\Dashboards\WidgetData;
use App\Support\Discover\DiscoverLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Zahlen einer einzelnen Kachel.
 *
 * **Eine Adresse je Kachel — das ist die Parallelität.** Ein Dashboard mit
 * zwanzig Kacheln macht zwanzig Anfragen, und der Browser führt sie
 * nebeneinander aus; die Seite selbst ist längst da. Die Alternative — eine
 * Antwort, die alle zwanzig Abfragen enthält — wäre serverseitig eine Schleife:
 * zwanzig Abfragen nacheinander, und der Bildschirm bliebe so lange leer wie
 * ihre Summe dauert. PHP rechnet sie in beiden Fällen einzeln; der Unterschied
 * ist, ob sie sich auf zwanzig gleichzeitige Anfragen verteilen oder in einer
 * aufreihen.
 *
 * **Der Zeitraum steht in der Adresse**, wie überall: dieselben Parameter wie
 * an der Seite selbst (Projekt, Umgebung, Zeitraum, Zeitzone). Die Kachel kann
 * sie für sich überschreiben — das steht an ihr und nicht im Aufruf, damit die
 * Ausnahme in der Datenbank steht und nicht in einem Link.
 *
 * **JSON und keine Inertia-Antwort**: es geht um einen Ausschnitt einer
 * stehenden Seite und nicht um einen Seitenwechsel.
 */
class DashboardWidgetDataController extends Controller
{
    public function __construct(private readonly WidgetData $data = new WidgetData) {}

    public function __invoke(GlobalFilterRequest $request, Dashboard $dashboard, DashboardWidget $widget): JsonResponse
    {
        Gate::authorize('view', $dashboard);

        if ($widget->dashboard_id !== $dashboard->id) {
            throw new NotFoundHttpException;
        }

        return response()->json([
            'widget' => $this->data->resolve($widget, $request->filter(), DiscoverLimits::fromConfig()),
        ]);
    }
}
