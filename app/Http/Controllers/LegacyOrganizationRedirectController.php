<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Die alten, organisationslosen Adressen der Fachseiten (`/fehler`,
 * `/versionen`, …). Sie führen auf dieselbe Seite unter der aktiven
 * Organisation, damit vorhandene Lesezeichen und schon verschickte Links nicht
 * ins Leere laufen.
 *
 * Weitergeleitet wird auf der Ebene des Pfades und nicht Route für Route: die
 * neue Adresse ist die alte mit `organisationen/{slug}/` davor, und das gilt für
 * jeden Unterpfad — auch für die, die erst später dazukommen. Eine Liste
 * einzelner Weiterleitungen wäre genau die Liste, die man beim nächsten neuen
 * Unterpfad vergisst.
 *
 * Die Abfrage-Parameter bleiben unverändert daran hängen: in ihnen steckt der
 * Zustand der Filterleiste, und ein Link ohne sie zeigt eine andere Auswahl als
 * der, den jemand verschickt hat.
 *
 * Dauerhaft (301), wie für abgelöste Adressen üblich. Das hat einen Preis, der
 * hier bewusst in Kauf genommen wird: das Ziel hängt an der aktiven
 * Organisation, und der Browser merkt sich die Weiterleitung. Wer nach dem
 * Wechsel die alte Adresse noch einmal eintippt, landet deshalb in der alten
 * Organisation. Der Weg dorthin ist die Adresse von gestern — die von heute
 * trägt die Organisation bei sich.
 */
class LegacyOrganizationRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $organization = $request->user()?->resolveCurrentOrganization();

        // Ohne Organisation gibt es keine Fachseite, auf die weitergeleitet
        // werden könnte — dann in die Liste, wo sich eine anlegen lässt.
        if ($organization === null) {
            return redirect()->route('organizations.index');
        }

        $target = URL::to('organisationen/'.$organization->getRouteKey().'/'.$request->path());
        $query = $request->getQueryString();

        return redirect()->to($query === null ? $target : $target.'?'.$query, 301);
    }
}
