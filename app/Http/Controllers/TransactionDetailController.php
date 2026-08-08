<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionDetailRequest;
use App\Support\Performance\TransactionDetail;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Detailanalyse einer Transaktion: warum diese Seite langsam ist.
 *
 * Wie die Übersicht rechnet der Controller nichts — er liest die Adresszeile
 * und reicht sie an {@see TransactionDetail} weiter. Die Zusage einer festen
 * Zahl beschränkter Abfragen hängt daran, dass hier keine zweite Auskunft
 * nachgeschlagen wird.
 *
 * Eine Transaktion, für die es im Zeitraum keine Messung gibt, ist **kein**
 * Fehler 404: der Name ist kein Datensatz, sondern eine Gruppierung, und ein
 * Link auf „letzte 24 Stunden" ist morgen leer, ohne falsch zu sein. Die Seite
 * zeigt dann ihren Leerzustand samt Filterleiste, mit der sich der Zeitraum
 * ändern lässt.
 */
class TransactionDetailController extends Controller
{
    public function __invoke(TransactionDetailRequest $request): InertiaResponse
    {
        $filter = $request->filter();

        $detail = (new TransactionDetail($filter, $request->name(), $request->op()))->result();

        return Inertia::render('performance/Transaction', [
            'detail' => $detail->toArray(),
            // Der Weg zurück in die Übersicht, mit derselben Projektauswahl und
            // demselben Zeitraum. Ohne ihn führte der Zurück-Link auf die
            // Voreinstellung, und der Zeitraum, wegen dessen man hier ist, wäre
            // beim Zurückgehen weg.
            'overviewHref' => route('performance.index', $filter->formValues()),
        ]);
    }
}
