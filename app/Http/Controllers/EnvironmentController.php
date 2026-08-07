<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Umgebungen eines Projekts. Anlegen und Löschen gibt es hier nicht — Umgebungen
 * entstehen aus den eingehenden Meldungen und verschwinden mit dem Projekt.
 * Einstellbar ist nur, ob eine Umgebung in der Filterleiste erscheint.
 */
class EnvironmentController extends Controller
{
    public function update(Request $request, Organization $organization, Project $project, Environment $environment): RedirectResponse
    {
        Gate::authorize('update', $project);

        // Ausdrücklich verlangt: ein fehlendes Feld würde als „nicht versteckt"
        // gelesen und eine ausgeblendete Umgebung stillschweigend zurückholen.
        $request->validate(['hidden' => ['required', 'boolean']]);

        $environment->is_hidden = $request->boolean('hidden');
        $environment->save();

        $status = $environment->is_hidden
            ? "Umgebung „{$environment->name}“ wird nicht mehr angeboten."
            : "Umgebung „{$environment->name}“ wird wieder angeboten.";

        return back()->with('status', $status);
    }
}
