<?php

namespace App\Http\Controllers;

use App\Models\Release;
use App\Support\Releases\ReleaseDetail;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Detailseite einer Auslieferung — was in ihr steckt.
 *
 * Anders als die Liste hängt sie an einer Kennung in der Adresszeile und hat
 * damit keine Vorauswahl über die Filterleiste. Deshalb steht die Rechteprüfung
 * hier ausdrücklich, wie bei der Fehler-Detailseite.
 *
 * Der Controller rechnet nichts; was auf der Seite steht, stellt
 * {@see ReleaseDetail} zusammen. Der Vergleich zur Vorversion kommt mit R8 dazu.
 */
class ReleaseDetailController extends Controller
{
    public function __invoke(Release $release): InertiaResponse
    {
        Gate::authorize('view', $release);

        return Inertia::render('releases/Show', ReleaseDetail::present($release));
    }
}
