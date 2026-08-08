<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueSearchSuggestionRequest;
use App\Support\Issues\IssueSearchSuggestions;
use Illuminate\Http\JsonResponse;

/**
 * Die Vorschläge des Suchfeldes.
 *
 * Eine eigene Adresse und kein Teil der Fehlerliste: sie wird beim **Tippen**
 * abgefragt, also oft und mit kurzer Erwartung, während die Liste eine ganze
 * Seite baut. Als Teilaufruf der Seite würde jeder Tastenanschlag die Zähler,
 * die Verlaufsgrafiken und die Filterleiste mitschleppen.
 *
 * Antwort ist deshalb schlichtes JSON und keine Inertia-Seite — es gibt hier
 * nichts zu blättern, nichts zu verlinken und nichts, das in der Adresszeile
 * stehen müsste.
 */
class IssueSearchController extends Controller
{
    public function suggest(IssueSearchSuggestionRequest $request): JsonResponse
    {
        return response()->json(IssueSearchSuggestions::for(
            $request->filter(),
            $request->term(),
            $request->cursor(),
        ));
    }
}
