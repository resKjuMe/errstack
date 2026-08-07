<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\PrivacyData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Organisationsweite Datenschutz-Regeln.
 *
 * Die Ebene über dem Projekt, und sie ist der eigentliche Grund, dass es zwei
 * gibt: eine Kundennummer heißt in allen Anwendungen einer Organisation gleich,
 * und sie in jedem Projekt erneut einzutragen hieße, sie irgendwann in einem zu
 * vergessen. Die Regeln von hier gelten in allen Projekten mit und sind dort
 * sichtbar, aber nicht änderbar.
 *
 * Die Schalter (IP-Adressen, Nutzerdaten, Anhänge) gibt es hier bewusst nicht:
 * sie hängen daran, was eine einzelne Anwendung meldet, und ein
 * organisationsweiter Schalter wäre entweder wirkungslos oder eine Vorgabe, die
 * ein Projekt nicht mehr abwählen kann.
 */
class OrganizationPrivacyController extends Controller
{
    public function index(Request $request, Organization $organization): InertiaResponse
    {
        Gate::authorize('view', $organization);

        return Inertia::render('privacy/Index', PrivacyData::forOrganization($organization, $request->user()));
    }
}
