<?php

namespace App\Http\Controllers;

use App\Enums\TrendDirection;
use App\Enums\TrendListSort;
use App\Http\Requests\GlobalFilterRequest;
use App\Http\Requests\TrendListRequest;
use App\Models\TransactionTrendDetection;
use App\Support\FilterData;
use App\Support\Formats;
use App\Support\Performance\Trends\BreakpointScan;
use App\Support\Performance\Trends\TrendList;
use App\Support\Performance\Trends\TrendScan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Trend-Liste: was in diesem Zeitraum umgeschlagen ist.
 *
 * **Eine eigene Ansicht neben der Performance-Übersicht.** Die Übersicht zeigt
 * alle Transaktionen mit einem Pfeil daneben; sie beantwortet „wie steht es
 * gerade". Diese Liste zeigt nur die, bei denen sich etwas geändert hat, und
 * beantwortet „was hat sich geändert und wann". Der Unterschied ist der Grund
 * für die Seite: in einer Übersicht mit fünfhundert Zeilen ist eine
 * Verschlechterung ein Pfeil unter fünfhundert Pfeilen, und genau deshalb fällt
 * sie monatelang niemandem auf.
 *
 * Der Controller rechnet nichts. Die Feststellungen stehen bereits in der
 * Datenbank — gerechnet hat sie der stündliche Durchlauf
 * ({@see TrendScan}). Das ist keine
 * Bequemlichkeit, sondern die Bedingung: eine Bruchpunkt-Suche über eine Woche
 * Verlauf je Transaktion ist nichts, was während eines Seitenaufrufs
 * stattfinden kann.
 */
class PerformanceTrendController extends Controller
{
    public function index(TrendListRequest $request): InertiaResponse
    {
        $filter = $request->filter();

        $trends = TrendList::paginate(
            $filter,
            $request->sort(),
            $request->direction(),
            $request->seen(),
        );

        return Inertia::render('performance/Trends', [
            'filter' => FilterData::bar($filter),
            'trends' => $trends,
            'list' => $request->listValues(),
            'totalLabel' => Formats::number($trends->total()),
            'sortOptions' => TrendListSort::options(),
            'directionOptions' => self::directionOptions(),
            'seenOptions' => self::seenOptions(),
            // Die Schwellen stehen in der Oberfläche, weil die Liste sonst eine
            // Behauptung ohne Maßstab wäre: „warum steht das hier nicht drin"
            // ist die zweite Frage nach dem Aufschlagen, und die Antwort ist
            // eine dieser vier Zahlen.
            'thresholds' => [
                'change' => Formats::number(BreakpointScan::MINIMUM_CHANGE * 100, 0).' %',
                'samples' => Formats::number(BreakpointScan::MINIMUM_SIDE_SAMPLES),
                'windows' => Formats::number(BreakpointScan::MINIMUM_SIDE_WINDOWS),
                'confidence' => Formats::number(BreakpointScan::MINIMUM_Z, 1),
            ],
            'overviewHref' => route('performance.index', $filter->formValues()),
        ]);
    }

    /**
     * „Gesehen" setzen.
     *
     * Wer das Recht hat, das Projekt zu sehen, darf auch abhaken. Ein eigenes
     * Recht dafür wäre eine Hürde ohne Schutzwirkung: die Markierung ändert
     * nichts an den Messwerten und lässt sich mit einem Klick zurücknehmen.
     */
    public function store(GlobalFilterRequest $request, TransactionTrendDetection $trend): RedirectResponse
    {
        Gate::authorize('view', $trend->project);

        $trend->markSeen($request->user());

        return back()->with('status', __('performance_trends.flash.seen', ['transaction' => $trend->name]));
    }

    /**
     * „Gesehen" wieder aufheben.
     */
    public function destroy(GlobalFilterRequest $request, TransactionTrendDetection $trend): RedirectResponse
    {
        Gate::authorize('view', $trend->project);

        $trend->markUnseen();

        return back()->with('status', __('performance_trends.flash.unseen', ['transaction' => $trend->name]));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function directionOptions(): array
    {
        return [
            ['value' => TrendListRequest::DIRECTION_ANY, 'label' => __('performance_trends.filter.any_direction')],
            ['value' => TrendDirection::Worse->value, 'label' => __('performance_trends.filter.worse')],
            ['value' => TrendDirection::Better->value, 'label' => __('performance_trends.filter.better')],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function seenOptions(): array
    {
        return [
            ['value' => TrendListRequest::SEEN_OPEN, 'label' => __('performance_trends.filter.open')],
            ['value' => TrendListRequest::SEEN_DONE, 'label' => __('performance_trends.filter.done')],
            ['value' => TrendListRequest::SEEN_ANY, 'label' => __('performance_trends.filter.any_seen')],
        ];
    }
}
