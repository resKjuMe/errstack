<?php

namespace App\Http\Controllers;

use App\Enums\TransactionSort;
use App\Http\Requests\PerformanceOverviewRequest;
use App\Support\Performance\TransactionOverview;
use App\Support\Performance\TransactionOverviewRow;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Performance-Übersicht: welche Seiten und Endpunkte im gewählten Zeitraum
 * langsam oder fehlerhaft waren.
 *
 * Der Controller rechnet nichts. Er liest die Adresszeile, reicht sie an
 * {@see TransactionOverview} weiter und gibt das Ergebnis aus — die Zusage
 * „feste Zahl an Abfragen" hängt daran, dass hier keine zweite Auskunft
 * nachgeschlagen wird.
 *
 * Die Detailansicht einer einzelnen Transaktion ist eine eigene Aufgabe (PF3);
 * diese Seite beantwortet ausschließlich die Frage, wohin man überhaupt schauen
 * soll.
 */
class PerformanceController extends Controller
{
    public function __invoke(PerformanceOverviewRequest $request): InertiaResponse
    {
        $filter = $request->filter();
        $sort = $request->sort();
        $descending = $request->descending();

        $result = (new TransactionOverview($filter, $request->search(), $sort, $descending))
            ->page($request->page());

        return Inertia::render('Performance', [
            'rows' => array_map(
                fn (TransactionOverviewRow $row): array => $row->toArray(),
                $result->rows,
            ),
            // Die Spalten kommen vom Server, damit die anklickbaren
            // Spaltenköpfe genau die Sortierschlüssel tragen, die er auch
            // annimmt — eine Spalte, die sich anklicken lässt und abgewiesen
            // wird, wäre die Folge zweier getrennter Listen.
            'columns' => TransactionSort::columns(),
            'sort' => $sort->value,
            'direction' => $descending ? 'desc' : 'asc',
            'q' => $request->searchInput(),
            'pagination' => $result->pagination(),
            'truncated' => $result->truncated,
            'groupLimit' => TransactionOverview::GROUP_LIMIT,
        ]);
    }
}
