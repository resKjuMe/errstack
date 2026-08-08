<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Der Einstieg unter `/`. Er zeigt keine Seite, sondern entscheidet, wohin es
 * geht: auf die Übersicht der aktiven Organisation — und ohne Mitgliedschaft auf
 * die Organisationsliste, wo sich eine anlegen lässt.
 *
 * Die Adresse bleibt bewusst bestehen, obwohl die Übersicht selbst inzwischen
 * unter der Organisation liegt. Sie ist die einzige Stelle, die auch dann noch
 * antworten kann, wenn es (noch) keine Organisation gibt — genau der Zustand
 * eines frisch angelegten Kontos, das nach der Anmeldung irgendwohin muss.
 *
 * Die Weiterleitung ist **vorübergehend** (302) und nicht dauerhaft: ihr Ziel
 * hängt an der aktiven Organisation und ändert sich mit ihr. Eine dauerhafte
 * Weiterleitung würde der Browser behalten und `/` nach jedem Wechsel weiterhin
 * in die alte Organisation schicken.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $organization = $request->user()?->resolveCurrentOrganization();

        if ($organization === null) {
            return redirect()->route('organizations.index');
        }

        return redirect()->route('dashboard', $organization);
    }
}
