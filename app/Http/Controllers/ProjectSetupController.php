<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Support\Setup\SetupData;
use App\Support\Setup\SetupGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Der Einrichtungs-Assistent: von einem frisch angelegten Projekt zum ersten
 * Fehler.
 *
 * **Er hat keinen eigenen Zustand.** Welche Anleitung gewählt ist, steht in der
 * Adresszeile; wie weit die Einrichtung ist, steht in den Daten selbst (ist
 * eine Meldung angekommen?). Ein Fortschritt in der Datenbank wäre eine zweite
 * Wahrheit daneben, die veraltet, sobald jemand das Projekt neu anschließt — und
 * er würde genau das verhindern, was die Aufgabe ausdrücklich verlangt: dass der
 * Ablauf **jederzeit erneut** aufrufbar ist.
 *
 * Das Recht ist dasselbe wie für die Client-Schlüssel: die Seite zeigt die DSN
 * im Klartext, und wer sie nicht verwalten darf, soll sie auch hier nicht
 * ablesen können.
 */
class ProjectSetupController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('manageKeys', $project);

        return Inertia::render('projects/Setup', SetupData::index($project, $this->guide($request, $project)));
    }

    /**
     * Der Stand für den Wartebildschirm.
     *
     * Eine eigene, schlanke Antwort statt eines Inertia-Neuladens der ganzen
     * Seite: sie wird im Sekundentakt abgefragt, solange jemand wartet, und die
     * Anleitung samt Beispielcode noch einmal mitzuschicken wäre bei jeder
     * Abfrage dieselbe Seite für dieselbe unveränderte Antwort.
     */
    public function status(Organization $organization, Project $project): JsonResponse
    {
        Gate::authorize('manageKeys', $project);

        return response()->json(SetupData::state($project));
    }

    /**
     * Die gewählte Anleitung. Ein unbekannter Wert in der Adresszeile ist kein
     * Fehler, sondern eine alte Verknüpfung — dann gilt die Vorauswahl aus der
     * Plattform des Projekts.
     */
    private function guide(Request $request, Project $project): SetupGuide
    {
        $wanted = $request->query('anleitung');

        return (is_string($wanted) ? SetupGuide::tryFrom($wanted) : null)
            ?? SetupGuide::defaultFor($project->platform);
    }
}
