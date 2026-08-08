<?php

namespace App\Http\Controllers\Api\V0;

use App\Http\Controllers\Controller;
use App\Models\Deploy;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Support\Api\ApiResponse;
use App\Support\Releases\DeployRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Die Auslieferungen einer Version über die öffentliche Schnittstelle.
 *
 * Das ist der Aufruf am Ende einer Auslieferungs-Pipeline: „diese Version ist
 * jetzt in dieser Umgebung". Er ist der **einzige** Weg, an diese Angabe zu
 * kommen — aus einer Meldung geht sie nicht hervor, und ohne sie beantwortet
 * die Fehlerseite die Frage nicht, mit der nach jeder Störung jemand kommt.
 *
 * **Anders als beim Ankündigen einer Version ist `store` nicht wiederholbar
 * im Sinne von „ändert nichts".** Jeder Aufruf legt einen Deploy an, und das
 * ist Absicht: zweimal auszuliefern ist zweimal ausgeliefert. Wer nach einem
 * Rollback erneut ausliefert, will genau diese zweite Zeile — sie ist die
 * Auskunft darüber, dass es zwei Zeitpunkte gab.
 */
class DeployController extends Controller
{
    /**
     * Die Auslieferungen dieser Version, neueste zuerst.
     *
     * Ohne Blätterwerk, wie die Commit-Liste: es sind die Auslieferungen
     * **einer** Version und damit eine Handvoll.
     */
    public function index(Organization $organization, Project $project, string $version): JsonResponse
    {
        $release = self::release($project, $version);

        $deploys = $release->deploys()->with('environment')->get();

        return ApiResponse::data(
            $deploys->map(fn (Deploy $deploy): array => self::payload($deploy))->all(),
        );
    }

    public function store(Request $request, Organization $organization, Project $project, string $version, DeployRecorder $recorder): JsonResponse
    {
        $release = self::release($project, $version);

        $validated = $request->validate([
            // Freiwillig: eine Pipeline, die nur eine Umgebung kennt, soll sie
            // nicht bei jedem Aufruf wiederholen müssen. Ohne Angabe gilt die
            // Standard-Umgebung des Projekts — und damit die, in der eine
            // Auslieferung „draußen" bedeutet.
            'environment' => ['nullable', 'string', 'max:'.Environment::NAME_LIMIT],
            'name' => ['nullable', 'string', 'max:'.Deploy::NAME_LIMIT],
            'url' => ['nullable', 'string', 'url', 'max:500'],
            'started_at' => ['nullable', 'date'],
            // Ohne Angabe gilt der Zeitpunkt des Aufrufs; er steht am Ende der
            // Pipeline, und das ist die richtige Antwort.
            'finished_at' => ['nullable', 'date'],
        ], [], [
            'environment' => 'Umgebung',
            'name' => 'Bezeichnung',
            'url' => 'Adresse',
            'started_at' => 'Beginn der Auslieferung',
            'finished_at' => 'Ende der Auslieferung',
        ]);

        $deploy = $recorder->record(
            $release,
            is_string($validated['environment'] ?? null) ? $validated['environment'] : null,
            is_string($validated['name'] ?? null) ? $validated['name'] : null,
            is_string($validated['url'] ?? null) ? $validated['url'] : null,
            self::time($validated['started_at'] ?? null),
            self::time($validated['finished_at'] ?? null),
        );

        return ApiResponse::data(self::payload($deploy), 201);
    }

    private static function release(Project $project, string $version): Release
    {
        return Release::query()
            ->where('project_id', $project->id)
            ->where('version', Release::normalizeVersion($version) ?? '')
            ->firstOrFail();
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    /**
     * Eine Auslieferung nach außen.
     *
     * Die Umgebung steht als Name darin und nicht als Kennung: sie ist das,
     * womit ein Client sie angelegt hat, und eine Zahl aus unserer Ablage wäre
     * für ihn keine Auskunft.
     *
     * @return array<string, mixed>
     */
    private static function payload(Deploy $deploy): array
    {
        return [
            'id' => $deploy->id,
            'environment' => $deploy->environment?->name,
            'name' => $deploy->name,
            'url' => $deploy->url,
            'started_at' => $deploy->started_at?->toIso8601String(),
            'finished_at' => $deploy->finished_at->toIso8601String(),
        ];
    }
}
