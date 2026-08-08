<?php

namespace App\Http\Controllers\Api\V0;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Support\Api\ApiQuery;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Die ausgelieferten Versionen eines Projekts über die öffentliche
 * Schnittstelle.
 *
 * Versionen entstehen von selbst aus den Meldungen — dieser Endpunkt ist der
 * **zweite** Weg und nicht der einzige. Er wird gebraucht, weil die Reihenfolge
 * im Betrieb umgekehrt ist: erst wird ausgeliefert, dann kommt (hoffentlich
 * lange) keine Meldung. Wer beim Ausliefern Bescheid gibt, hat die Version in
 * der Liste, bevor der erste Fehler daraus eintrifft — samt Zeitpunkt der
 * Auslieferung, den aus einer Meldung niemand ableiten kann.
 *
 * `store` ist **wiederholbar**: dieselbe Version noch einmal anzulegen ist kein
 * Fehler, sondern eine Ergänzung. Das ist keine Nachsicht, sondern die
 * Anforderung — der Aufruf steht in einer Auslieferungs-Pipeline, und die läuft
 * bei einem Fehlschlag noch einmal. Ein `409` an dieser Stelle wäre ein roter
 * Bauschritt für einen Vorgang, der längst erledigt ist.
 */
class ReleaseController extends Controller
{
    public function index(Request $request, Organization $organization, Project $project): JsonResponse
    {
        $query = Release::query()->where('project_id', $project->id);

        $paginator = ApiQuery::paginate($query, $request, [
            'version' => 'version',
            'released_at' => 'released_at',
            'first_event' => 'first_event_at',
            'last_event' => 'last_event_at',
            'created_at' => 'created_at',
        ], '-created_at');

        return ApiResponse::paginated(
            $paginator,
            fn (Release $release): array => self::payload($release),
        );
    }

    public function show(Organization $organization, Project $project, string $version): JsonResponse
    {
        $release = Release::query()
            ->where('project_id', $project->id)
            ->where('version', Release::normalizeVersion($version) ?? '')
            ->firstOrFail();

        return ApiResponse::data(self::payload($release));
    }

    public function store(Request $request, Organization $organization, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:'.Release::VERSION_LIMIT],
            'ref' => ['nullable', 'string', 'max:250'],
            'url' => ['nullable', 'string', 'url', 'max:500'],
            'released_at' => ['nullable', 'date'],
        ], [], [
            'version' => 'Version',
            'ref' => 'Stand im Repository',
            'url' => 'Adresse',
            'released_at' => 'Zeitpunkt der Auslieferung',
        ]);

        $version = Release::normalizeVersion($validated['version']);

        if ($version === null) {
            // Eine Angabe, von der nach dem Vereinheitlichen nichts übrig
            // bleibt, hat die Prüfung oben bestanden und ist trotzdem keine
            // Version. Als Prüffehler abweisen und nicht mit einer eigenen
            // Antwortform: ein Client, der Prüffehler auswertet, soll für
            // diesen Fall keinen zweiten Zweig brauchen.
            throw ValidationException::withMessages([
                'version' => __('validation.required', ['attribute' => 'Version']),
            ]);
        }

        $release = Release::forVersion($project, $version);
        $created = $release->wasRecentlyCreated;

        // Nur ausdrücklich mitgeschickte Felder werden geschrieben. Der
        // Unterschied zeigt sich beim zweiten Aufruf: ein `null` aus einem
        // weggelassenen Feld würde eine bereits gesetzte Auslieferungszeit
        // wieder leeren.
        $changes = array_intersect_key($validated, array_flip(['ref', 'url', 'released_at']));

        if ($changes !== []) {
            $release->fill($changes)->save();
        }

        return ApiResponse::data(self::payload($release), $created ? 201 : 200);
    }

    /**
     * Die Version nach außen.
     *
     * Die Sortierfelder stehen nicht darin: sie sind die Zerlegung der
     * Versionsangabe und damit eine Eigenschaft unserer Ablage, keine Auskunft.
     * Was ein Client davon braucht, ist die Frage, **ob** die Angabe eine
     * Rangfolge hat — und die beantwortet `is_semver`.
     *
     * @return array<string, mixed>
     */
    private static function payload(Release $release): array
    {
        return [
            'version' => $release->version,
            'ref' => $release->ref,
            'url' => $release->url,
            'is_semver' => $release->sort_major !== null,
            'released_at' => $release->released_at?->toIso8601String(),
            'first_event' => $release->first_event_at?->toIso8601String(),
            'last_event' => $release->last_event_at?->toIso8601String(),
            'created_at' => $release->created_at?->toIso8601String(),
        ];
    }
}
