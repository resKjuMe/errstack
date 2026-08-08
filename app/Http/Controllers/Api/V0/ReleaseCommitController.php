<?php

namespace App\Http\Controllers\Api\V0;

use App\Http\Controllers\Controller;
use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Support\Api\ApiResponse;
use App\Support\Releases\CommitImport;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Die Commits einer Auslieferung über die öffentliche Schnittstelle.
 *
 * Das ist der Weg für eine Bauumgebung **ohne** Anbindung: sie kennt die
 * Commits ihres Bereichs ohnehin — sie hat gerade daraus gebaut — und übergibt
 * sie beim Ausliefern. Eine Anbindung an GitHub oder GitLab (X1/X2) holt später
 * dasselbe ab und schreibt es an dieselbe Stelle.
 *
 * **Die Übergabe setzt die Liste, sie ergänzt sie nicht** (siehe
 * {@see CommitImport}): der Aufruf steht in einer Pipeline, und die läuft bei
 * einem Fehlschlag noch einmal.
 */
class ReleaseCommitController extends Controller
{
    /**
     * Die Commits, die in dieser Version stecken.
     *
     * Ohne Blätterwerk: die Liste ist der Inhalt **einer** Auslieferung und
     * damit von Natur aus begrenzt — wer sie abruft, will sie ganz. Ein Aufruf,
     * der die Antwort in Seiten zerlegt, wäre für einen Auslieferungs-Bericht
     * nur ein zweiter Aufruf mit demselben Ergebnis.
     */
    public function index(Organization $organization, Project $project, string $version): JsonResponse
    {
        $release = self::release($project, $version);

        $commits = $release->commits()->with(['repository', 'files'])->get();

        return ApiResponse::data(
            $commits->map(fn (Commit $commit): array => self::payload($commit))->all(),
        );
    }

    public function store(Request $request, Organization $organization, Project $project, string $version): JsonResponse
    {
        $release = self::release($project, $version);

        $validated = $request->validate(
            // Eine **leere** Liste ist erlaubt und bedeutet „diese Auslieferung
            // enthält keine Commits mehr" — der Weg, eine falsche Übergabe
            // zurückzunehmen, ohne die Version zu löschen. `present` und nicht
            // `required`: `required` wiese genau diese leere Liste ab.
            ['commits' => ['present', 'array']] + CommitImport::rules(),
            [],
            CommitImport::attributes(),
        );

        $commits = CommitImport::into(
            $release,
            $validated['commits'],
            // Der Name gilt für alle Einträge, die keinen eigenen mitbringen.
            // Der übliche Fall ist genau der: ein Baulauf kommt aus **einem**
            // Repository, und ihn an jedem der dreihundert Commits zu
            // wiederholen wäre eine Angabe, die nur schiefgehen kann.
            is_string($validated['repository'] ?? null) ? $validated['repository'] : null,
        );

        // Die Beziehungen einmal für alle nachladen: die Antwort nennt je
        // Commit sein Repository und seine Dateien, und ohne das wären es zwei
        // Abfragen je Zeile — bei dreihundert Commits der teuerste Teil eines
        // Aufrufs, der sonst nur schreibt.
        $loaded = EloquentCollection::make($commits)->load(['repository', 'files']);

        return ApiResponse::data(
            $loaded->map(fn (Commit $commit): array => self::payload($commit))->all(),
        );
    }

    private static function release(Project $project, string $version): Release
    {
        return Release::query()
            ->where('project_id', $project->id)
            ->where('version', Release::normalizeVersion($version) ?? '')
            ->firstOrFail();
    }

    /**
     * Ein Commit nach außen.
     *
     * Der Autor steht als Name und Adresse darin, wie das Repository ihn führt
     * — nicht als Konto: wer hier ein Konto hat, geht einen Client nichts an,
     * und die Zuordnung ist eine Eigenschaft dieser Anwendung und keine
     * Auskunft über den Commit.
     *
     * @return array<string, mixed>
     */
    private static function payload(Commit $commit): array
    {
        return [
            'id' => $commit->sha,
            'repository' => $commit->repository?->name,
            'message' => $commit->message,
            'author_name' => $commit->author_name,
            'author_email' => $commit->author_email,
            'timestamp' => $commit->committed_at?->toIso8601String(),
            'patch_set' => $commit->files
                ->map(fn (CommitFile $file): array => [
                    'path' => $file->path,
                    'type' => $file->change_type->value,
                ])->values()->all(),
        ];
    }
}
