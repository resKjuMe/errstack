<?php

namespace App\Http\Controllers;

use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Http\Requests\QuotaRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Quota;
use App\Support\QuotaData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Kontingente eines Projekts: was je Datenart im Monat und in der Minute
 * hereinkommen darf — und was davon verbraucht ist.
 *
 * Ansehen darf jedes Mitglied, und das ist hier keine Höflichkeit: ein
 * gerissenes Kontingent ist die häufigste Erklärung dafür, dass eine Anwendung
 * plötzlich stumm ist. Wäre die Seite der Verwaltung vorbehalten, suchte der
 * Rest der Belegschaft den Fehler in der eigenen Anwendung.
 *
 * Ändern darf die Verwaltung (ProjectPolicy `manageQuotas`) — es ist die
 * schärfste Einstellung des Projekts: was ein aufgebrauchtes Kontingent
 * abweist, kommt nicht nachträglich herein.
 */
class ProjectQuotaController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Quotas', QuotaData::forProject($project, $request->user()));
    }

    public function update(QuotaRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageQuotas', $project);

        foreach ($request->quotas() as $category => $values) {
            Quota::set(
                QuotaScope::Project,
                $project->id,
                QuotaCategory::from($category),
                $values['per_month'],
                $values['per_minute'],
            );
        }

        return back()->with('status', __('quotas.flash.updated'));
    }
}
