<?php

namespace App\Http\Controllers;

use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Http\Requests\QuotaRequest;
use App\Models\Organization;
use App\Models\Quota;
use App\Support\QuotaData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Dieselbe Seite eine Ebene höher: die Kontingente der Organisation gelten für
 * **alle** ihre Projekte zusammen.
 *
 * Sie ersetzen die Kontingente der Projekte nicht, sie stehen darüber. Ein
 * Projekt mit großzügiger eigener Grenze wird trotzdem abgewiesen, wenn die
 * Organisation am Ende ist — deshalb verweist die Projektseite hierher, und
 * deshalb steht der Verbrauch der Organisation dort mit auf der Seite.
 */
class OrganizationQuotaController extends Controller
{
    public function index(Request $request, Organization $organization): InertiaResponse
    {
        Gate::authorize('view', $organization);

        return Inertia::render('organizations/Quotas', QuotaData::forOrganization($organization, $request->user()));
    }

    public function update(QuotaRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageQuotas', $organization);

        foreach ($request->quotas() as $category => $values) {
            Quota::set(
                QuotaScope::Organization,
                $organization->id,
                QuotaCategory::from($category),
                $values['per_month'],
                $values['per_minute'],
            );
        }

        return back()->with('status', __('quotas.flash.updated'));
    }
}
