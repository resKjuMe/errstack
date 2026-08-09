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
 * angesehen hat. Aufgelöst und auf Mitgliedschaft geprüft wird sie an einer
 * Stelle ({@see ResolveOrganization}); von dort liegt sie an der Anfrage, und
 * hier geht es nur noch darum, sie zu bekommen.
 *
 * Gelesen wird deshalb der Vermerk an der Anfrage und nicht der Routen-Parameter:
 * den nimmt die Middleware bewusst weg, wo der Controller ihn nicht verlangt
 * (siehe dort). Der Parameter bleibt trotzdem als zweiter Weg stehen — für die
 * Seiten, die ihn führen.
 *
 * Der Rückfall auf die zuletzt gewählte gilt für die Seiten, die zu keiner
 * einzelnen Organisation gehören (Organisationsliste, Zugriffstoken,
 * Projektliste): dort gibt es keinen Pfad-Anteil, der die Frage beantworten
 * könnte.
 */
final class CurrentOrganization
{
    /** Schlüssel des Vermerks an der Anfrage. */
    public const ATTRIBUTE = 'errstack.organization';

    public static function for(Request $request): ?Organization
    {
        $resolved = $request->attributes->get(self::ATTRIBUTE);

        if ($resolved instanceof Organization) {
            return $resolved;
        }

        $fromRoute = $request->route()?->parameter('organization');

        if ($fromRoute instanceof Organization) {
            return $fromRoute;
        }

        return $request->user()?->resolveCurrentOrganization();
    }
}
