<?php

namespace App\Http\Controllers;

use App\Enums\IssueSort;
use App\Enums\IssueStatus;
use App\Events\IssueCreated;
use App\Http\Requests\IssueListRequest;
use App\Support\FilterData;
use App\Support\Formats;
use App\Support\Issues\IssueList;
use App\Support\Issues\IssueSeries;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Fehlerliste — die Arbeitsansicht dieser Anwendung.
 *
 * Sie steht bewusst nicht unter einem einzelnen Projekt in der Adresszeile:
 * welche Projekte gemeint sind, sagt die globale Filterleiste (F7), und die kann
 * eines, mehrere oder alle meinen. Eine Route `/projekte/{projekt}/fehler`
 * daneben hätte eine zweite Wahrheit darüber aufgemacht, welches Projekt gerade
 * gilt.
 */
class IssueController extends Controller
{
    public function __invoke(IssueListRequest $request): InertiaResponse
    {
        $filter = $request->filter();
        $period = IssueSeries::periodFor($filter);

        $search = $request->search();

        $issues = IssueList::paginate($filter, $request->sort(), $request->status(), $search);

        return Inertia::render('issues/Index', [
            'filter' => FilterData::bar($filter),
            'issues' => $issues,
            'list' => $request->listValues(),
            // Die Gesamtzahl auch geschrieben: „12.480" gegen „12480" — wie eine
            // Zahl aussieht, entscheidet die Sprache, und die kennt der Server.
            'totalLabel' => Formats::number($issues->total()),
            'sortOptions' => IssueSort::options(),
            'statusOptions' => self::statusOptions(),
            // Begriffe, die die Suche (noch) nicht auswertet. Sie stillschweigend
            // zu übergehen wäre die schlechtere Wahl: die Liste sähe aus, als
            // hätte sie den Begriff berücksichtigt. Die vollständige Suchsprache
            // ist S4.
            'unsupportedTerms' => $search->unsupported,
            'series' => [
                'period' => $period->value,
                'periodLabel' => $period->label(),
            ],
            // Woran die Oberfläche die Live-Aktualisierung anmeldet: **ein** Kanal
            // für die ganze Organisation. Welche Projekte davon gerade zählen,
            // steht daneben — die Meldung trägt ihr Projekt mit, und die Ansicht
            // wirft aus, was nicht in der Auswahl liegt.
            'live' => [
                'channel' => $filter->organization === null
                    ? null
                    : IssueCreated::channelName($filter->organization->id),
                'projectIds' => $filter->projectIds(),
            ],
            // Der Zeitraum wirkt, die Umgebung nicht: der Eintrag ist über alle
            // Umgebungen hinweg gezählt, und ihn danach zu trennen ginge nur über
            // die Einzelereignisse. Statt die Auswahl still zu übergehen, sagt
            // die Seite es.
            'environmentIgnored' => $filter->environment !== null,
        ]);
    }

    /**
     * Die Zustände zur Auswahl, „alle" voran.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function statusOptions(): array
    {
        return [
            ['value' => IssueListRequest::STATUS_ANY, 'label' => __('issues.filter.any_status')],
            ...IssueStatus::options(),
        ];
    }
}
