<?php

namespace App\Http\Controllers\Api\V0;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseArtifact;
use App\Support\Api\ApiQuery;
use App\Support\Api\ApiResponse;
use App\Support\SourceMaps\ArtifactStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Bauartefakte einer Version über die öffentliche Schnittstelle: hochladen,
 * auflisten, löschen.
 *
 * **Das ist der einzige Weg hinein**, und mit Absicht: Bundles und Quellkarten
 * entstehen in einer Bauumgebung, und dort steht kein Mensch vor einem Formular.
 * Der Endpunkt ist für den Aufruf aus einer Auslieferungs-Pipeline gedacht — und
 * das prägt jede Entscheidung hier.
 *
 * `store` ist **wiederholbar**: derselbe Pfad noch einmal hochgeladen ersetzt das
 * Artefakt und ist kein Konflikt. Eine Pipeline läuft nach einem Fehlschlag noch
 * einmal, und ein `409` an dieser Stelle wäre ein roter Bauschritt für einen
 * Vorgang, der genau das Richtige tut.
 *
 * Die Grenzen — Dateigröße und Anzahl je Version — werden als **Prüffehler**
 * abgewiesen und nicht mit einer eigenen Antwortform. Ein Client, der Prüffehler
 * auswertet, soll für diesen Fall keinen zweiten Zweig brauchen.
 */
class ReleaseArtifactController extends Controller
{
    public function __construct(
        private readonly ArtifactStore $store,
    ) {}

    public function index(Request $request, Organization $organization, Project $project, string $version): JsonResponse
    {
        $release = self::release($project, $version);

        $query = ReleaseArtifact::query()->where('release_id', $release->id);

        $paginator = ApiQuery::paginate($query, $request, [
            'name' => 'name',
            'size' => 'size',
            'created_at' => 'created_at',
        ], 'name');

        return ApiResponse::paginated(
            $paginator,
            fn (ReleaseArtifact $artifact): array => self::payload($artifact),
        );
    }

    /**
     * Lädt ein Artefakt hoch.
     *
     * Der Pfad (`name`) ist die eigentliche Angabe und nicht der Dateiname: unter
     * ihm wird die Datei später gesucht, wenn ein Stacktrace `~/static/js/app.js`
     * meldet. Er wird vereinheitlicht ({@see ReleaseArtifact::normalizeName()}),
     * damit eine vollständige Adresse und ihre Tilden-Form dasselbe Artefakt
     * ergeben.
     *
     * Art, Debug-Kennung und Kartenverweis werden aus dem **Inhalt** gelesen
     * ({@see ArtifactStore::put()}) — eine ausdrücklich mitgeschickte Kennung
     * gewinnt, denn sie kommt von dem Werkzeug, das den Bauvorgang kennt.
     */
    public function store(Request $request, Organization $organization, Project $project, string $version): JsonResponse
    {
        $release = self::release($project, $version);

        // Die Größe steht in der Prüfung in Kilobyte, die Grenze in Byte: die
        // Regel `max` rechnet in Kilobyte, und aufgerundet wird, damit eine
        // Datei, die genau auf der Grenze liegt, nicht abgewiesen wird.
        $maxBytes = (int) config('sourcemaps.max_file_bytes');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.ReleaseArtifact::NAME_LIMIT],
            'file' => ['required', 'file', 'max:'.(int) ceil($maxBytes / 1024)],
            'debug_id' => ['nullable', 'string', 'uuid'],
            // Der Verweis auf die Quellkarte, wie ihn sentry-cli als Kopfzeile
            // mitgibt. Er ist die Rettung für ein Bundle, in dem
            // `sourceMappingURL` beim Bauen entfernt wurde — ohne beides bleibt
            // nur die Vermutung „derselbe Name mit .map".
            'source_map_ref' => ['nullable', 'string', 'max:500'],
        ], [], [
            'name' => 'Pfad',
            'file' => 'Datei',
            'debug_id' => 'Debug-Kennung',
            'source_map_ref' => 'Verweis auf die Quellkarte',
        ]);

        $name = ReleaseArtifact::normalizeName($validated['name']);

        if ($name === null) {
            // Eine Angabe, von der nach dem Vereinheitlichen nichts übrig bleibt,
            // hat die Prüfung oben bestanden und ist trotzdem kein Pfad.
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => 'Pfad']),
            ]);
        }

        $existing = ReleaseArtifact::query()
            ->where('release_id', $release->id)
            ->where('name', $name)
            ->exists();

        // Das Mengenlimit gilt für **neue** Pfade. Ein Ersetzen darf nie an ihm
        // scheitern: sonst hätte eine Version, die die Grenze erreicht hat, keine
        // Möglichkeit mehr, eine falsch hochgeladene Datei zu berichtigen.
        if (! $existing) {
            $limit = (int) config('sourcemaps.max_files_per_release');

            if ($this->store->countFor($release) >= $limit) {
                throw ValidationException::withMessages([
                    'file' => __('sourcemaps.errors.too_many_files', ['limit' => $limit]),
                ]);
            }
        }

        $file = $request->file('file');
        $content = $file === null || is_array($file) ? '' : (string) file_get_contents($file->getRealPath());

        $artifact = $this->store->put(
            release: $release,
            name: $name,
            content: $content,
            debugId: $validated['debug_id'] ?? null,
            sourceMapRef: $validated['source_map_ref'] ?? null,
        );

        // Was an fehlenden Artefakten gescheitert ist, wird jetzt neu gerechnet —
        // beim nächsten Aufschlagen der Fehlerseite. Der Aufruf steht hier und
        // nicht in der Ablage, weil er zur **Auslieferung** gehört und nicht zum
        // Schreiben einer Datei: erst wenn jemand etwas hochlädt, ist ein früheres
        // „keine Quellkarte gefunden" überholt.
        $this->store->invalidateSymbolications($release);

        return ApiResponse::data(self::payload($artifact), $artifact->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Organization $organization, Project $project, string $version, ReleaseArtifact $artifact): JsonResponse
    {
        $release = self::release($project, $version);

        // Die Kennung in der Adresse ist projektweit eindeutig, die Version
        // daneben nicht — eine vertauschte Zeile darf kein fremdes Artefakt
        // löschen.
        if ($artifact->release_id !== $release->id) {
            throw new NotFoundHttpException;
        }

        $this->store->delete($artifact);

        return ApiResponse::noContent();
    }

    /**
     * Die Version aus der Adresse.
     *
     * Sie steht roh dort und ist kein Modell-Parameter: eine Versionsangabe ist
     * nur innerhalb ihres Projekts eindeutig — dieselbe Überlegung wie bei den
     * Versionen selbst ({@see ReleaseController::show()}).
     */
    private static function release(Project $project, string $version): Release
    {
        $release = Release::query()
            ->where('project_id', $project->id)
            ->where('version', Release::normalizeVersion($version) ?? '')
            ->first();

        if ($release === null) {
            throw new NotFoundHttpException;
        }

        return $release;
    }

    /**
     * Das Artefakt nach außen.
     *
     * Der Ablagepfad steht **nicht** darin: er ist eine Eigenschaft unserer
     * Ablage und keine Auskunft. Was ein Client braucht, ist die Prüfsumme — mit
     * ihr kann eine Pipeline entscheiden, ob sie eine Datei überhaupt hochladen
     * muss.
     *
     * @return array<string, mixed>
     */
    private static function payload(ReleaseArtifact $artifact): array
    {
        return [
            'id' => $artifact->id,
            'name' => $artifact->name,
            'kind' => $artifact->kind->value,
            'debug_id' => $artifact->debug_id,
            'source_map_ref' => $artifact->source_map_ref,
            'size' => $artifact->size,
            'checksum' => $artifact->checksum,
            'created_at' => $artifact->created_at->toIso8601String(),
        ];
    }
}
