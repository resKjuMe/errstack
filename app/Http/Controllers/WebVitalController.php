<?php

namespace App\Http\Controllers;

use App\Enums\WebVital;
use App\Http\Requests\WebVitalDetailRequest;
use App\Http\Requests\WebVitalOverviewRequest;
use App\Support\Performance\Vitals\WebVitalDetail;
use App\Support\Performance\Vitals\WebVitalOverview;
use App\Support\Performance\Vitals\WebVitalPageRow;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Das Ladeerlebnis im Browser: die Übersicht der schlechtesten Seiten und die
 * Detailansicht einer einzelnen.
 *
 * Wie bei den Antwortzeiten rechnet der Controller nichts. Er liest die
 * Adresszeile, reicht sie an die Auswertung weiter und gibt das Ergebnis aus —
 * die Zusage „feste Zahl an Abfragen" hängt daran, dass hier keine zweite
 * Auskunft nachgeschlagen wird.
 *
 * Zwei Handlungen in einem Controller und nicht zwei Klassen, anders als bei den
 * Antwortzeiten: Übersicht und Detailseite zeigen hier **dieselben** Kennzahlen,
 * einmal je Seite und einmal für eine Seite. Sie auseinanderzuziehen hieße,
 * denselben Satz an Messwerten an zwei Stellen zusammenzustellen.
 */
class WebVitalController extends Controller
{
    public function index(WebVitalOverviewRequest $request): InertiaResponse
    {
        $filter = $request->filter();

        $result = (new WebVitalOverview($filter, $request->search()))->page($request->page());

        return Inertia::render('performance/WebVitals', [
            'rows' => array_map(
                static fn (WebVitalPageRow $row): array => $row->toArray(),
                $result->rows,
            ),
            // Die Spalten kommen vom Server, weil die Reihenfolge der Messwerte
            // eine fachliche ist ({@see WebVital}) — erst die Kernwerte, dann
            // die erklärenden. Sie in der Oberfläche noch einmal
            // hinzuschreiben hieße, zwei Listen zu pflegen, die auseinanderlaufen
            // können.
            'vitals' => self::columns(),
            'q' => $request->searchInput(),
            'pagination' => $result->pagination(),
            'truncated' => $result->truncated,
            'groupLimit' => WebVitalOverview::GROUP_LIMIT,
        ]);
    }

    public function show(WebVitalDetailRequest $request): InertiaResponse
    {
        $filter = $request->filter();

        $detail = (new WebVitalDetail($filter, $request->name(), $request->vital()))->result();

        return Inertia::render('performance/WebVital', [
            'detail' => $detail->toArray(),
            'vitals' => self::columns(),
            // Der Weg zurück in die Übersicht, mit derselben Projektauswahl und
            // demselben Zeitraum. Ohne ihn führte der Zurück-Link auf die
            // Voreinstellung, und der Zeitraum, wegen dessen man hier ist, wäre
            // beim Zurückgehen weg.
            'overviewHref' => route('web-vitals.index', $filter->formValues()),
        ]);
    }

    /**
     * Die Messwerte als Spalten: Schlüssel, Beschriftung und Erklärung.
     *
     * @return list<array{key: string, label: string, description: string, core: bool, score: bool}>
     */
    private static function columns(): array
    {
        $core = array_map(static fn (WebVital $vital): string => $vital->value, WebVital::core());

        return array_map(static fn (WebVital $vital): array => [
            'key' => $vital->value,
            'label' => $vital->label(),
            'description' => $vital->description(),
            // Die Kernwerte werden hervorgehoben: sie entscheiden über die
            // Bewertung der Seite, die übrigen erklären sie nur.
            'core' => in_array($vital->value, $core, true),
            'score' => $vital->isScore(),
        ], WebVital::cases());
    }
}
