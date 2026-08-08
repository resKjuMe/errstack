<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * „Mach diese Suche zum Einstieg für dieses Projekt" — und das Gegenstück.
 *
 * Das Projekt steht im Rumpf und nicht in der Adresszeile, weil die Fehlerliste
 * nicht unter einem Projekt liegt: welche Projekte gemeint sind, sagt die
 * globale Filterleiste (siehe routes/issues.php). Ein Pfad
 * `projekte/{projekt}/…` hätte an dieser Stelle eine zweite Wahrheit darüber
 * aufgemacht, welches Projekt gerade gilt.
 *
 * Aufgelöst wird der Slug in der **aktiven Organisation des Betrachters** und
 * nirgends sonst. Slugs sind nur innerhalb einer Organisation eindeutig; ohne
 * diese Einschränkung wäre „webshop" die Einladung, den Einstieg in ein fremdes
 * Projekt zu setzen — und damit die Auskunft, dass es dort eines gibt.
 */
class SavedSearchDefaultRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Das gemeinte Projekt. Unbekannt heißt „gibt es für dich nicht" — dieselbe
     * Antwort wie für ein Projekt einer fremden Organisation, damit die eine
     * Antwort nicht die andere verrät.
     */
    public function project(): Project
    {
        $organization = $this->user()->resolveCurrentOrganization();

        $project = $organization?->projects()
            ->where('slug', (string) $this->validated('project'))
            ->first();

        if (! $project instanceof Project) {
            throw new NotFoundHttpException;
        }

        return $project;
    }
}
