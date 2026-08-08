<?php

namespace App\Support;

use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use Illuminate\Http\Request;

/**
 * Welche Organisation eine Anfrage meint.
 *
 * Die Antwort steht seit U5 in der Adresse: die Fachseiten liegen unter
 * `/organisationen/{organisation}/…`, und damit meint ein Aufruf genau die
 * Organisation, die im Pfad steht — nicht die, die dieses Konto zuletzt
 * angesehen hat. Dass sie auch angehört wird, ist an einer Stelle geprüft
 * ({@see ResolveOrganization}); hier geht es nur noch darum, sie zu bekommen.
 *
 * Der Rückfall auf die zuletzt gewählte gilt für die Seiten, die zu keiner
 * einzelnen Organisation gehören (Organisationsliste, Zugriffstoken,
 * Projektliste): dort gibt es keinen Pfad-Anteil, der die Frage beantworten
 * könnte.
 */
final class CurrentOrganization
{
    public static function for(Request $request): ?Organization
    {
        $fromRoute = $request->route()?->parameter('organization');

        if ($fromRoute instanceof Organization) {
            return $fromRoute;
        }

        return $request->user()?->resolveCurrentOrganization();
    }
}
