<?php

namespace Tests\Feature\Api;

use App\Enums\ApiScope;
use App\Enums\ReleaseArtifactKind;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\ReleaseArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Die Bauartefakte einer Version über die Schnittstelle: hochladen, auflisten,
 * löschen.
 *
 * Der Endpunkt wird aus einer Auslieferungs-Pipeline aufgerufen, und das prägt
 * jede Prüfung hier: er muss **wiederholbar** sein, er darf an einem Ersetzen
 * nicht scheitern, und seine Grenzen müssen als Prüffehler herauskommen — ein
 * roter Bauschritt mit einer Antwortform, die niemand auswertet, ist keine
 * Auskunft.
 */
class ReleaseArtifactApiTest extends TestCase
{
    use RefreshDatabase;

    private const MAP = '{"version":3,"sources":["src/a.ts"],"names":[],"mappings":"AAAA"}';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @param  list<ApiScope>  $scopes
     */
    private function bearer(Organization $organization, array $scopes): string
    {
        return ApiToken::issue(
            tokenable: $organization,
            organization: $organization,
            createdBy: null,
            name: 'Test '.uniqid(),
            scopes: $scopes,
        )->plainTextToken;
    }

    /**
     * @return array{Organization, Project, Release}
     */
    private function context(): array
    {
        $organization = Organization::factory()->create(['slug' => 'acme']);
        $project = Project::factory()->for($organization)->create(['slug' => 'webshop']);
        $release = Release::forVersion($project, '1.3.0');

        return [$organization, $project, $release];
    }

    private function url(Organization $organization, Project $project, string $version = '1.3.0', string $suffix = ''): string
    {
        return "/api/0/organizations/{$organization->slug}/projects/{$project->slug}/releases/{$version}/files{$suffix}";
    }

    public function test_a_bundle_and_its_source_map_can_be_uploaded(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $this->withToken($bearer)
            ->post($this->url($organization, $project), [
                'name' => 'https://example.com/static/js/app.js?v=3',
                'file' => UploadedFile::fake()->createWithContent(
                    'app.js',
                    "var a=1;\n//# sourceMappingURL=app.js.map\n"
                ),
            ])
            ->assertStatus(201)
            // Die vollständige Adresse wird zur Tilden-Form, die Abfrage fällt
            // weg: sie gehört zur Adresse, nicht zur Datei.
            ->assertJsonPath('data.name', '~/static/js/app.js')
            ->assertJsonPath('data.kind', ReleaseArtifactKind::Bundle->value)
            // Der Verweis wird beim Hochladen aus dem Inhalt gelesen — später
            // müsste dafür jedes Bundle von der Platte geholt werden.
            ->assertJsonPath('data.source_map_ref', 'app.js.map');

        $this->withToken($bearer)
            ->post($this->url($organization, $project), [
                'name' => '~/static/js/app.js.map',
                'file' => UploadedFile::fake()->createWithContent('app.js.map', self::MAP),
            ])
            ->assertStatus(201)
            // Die Art entscheidet der Inhalt und nicht die Endung.
            ->assertJsonPath('data.kind', ReleaseArtifactKind::SourceMap->value);

        $this->assertSame(2, ReleaseArtifact::query()->count());
    }

    public function test_uploading_the_same_path_twice_replaces_the_artifact(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $upload = fn (string $content): TestResponse => $this->withToken($bearer)
            ->post($this->url($organization, $project), [
                'name' => '~/static/js/app.js',
                'file' => UploadedFile::fake()->createWithContent('app.js', $content),
            ]);

        $upload('var a=1;')->assertStatus(201);

        // Zweiter Lauf derselben Pipeline: kein Konflikt, sondern eine Ergänzung.
        // Der Zustandscode unterscheidet beide Fälle, damit ein Client sie
        // auseinanderhalten kann, ohne sie auseinanderhalten zu müssen.
        $upload('var a=2;')->assertStatus(200);

        $this->assertSame(1, ReleaseArtifact::query()->count());
        $this->assertSame(sha1('var a=2;'), ReleaseArtifact::query()->firstOrFail()->checksum);
    }

    public function test_a_debug_id_is_taken_from_the_source_map(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);
        $id = '5a2b1c3d-4e5f-6071-8293-a4b5c6d7e8f9';

        $this->withToken($bearer)
            ->post($this->url($organization, $project), [
                'name' => '~/app.js.map',
                'file' => UploadedFile::fake()->createWithContent(
                    'app.js.map',
                    (string) json_encode(['version' => 3, 'sources' => [], 'mappings' => 'AAAA', 'debug_id' => $id])
                ),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.debug_id', $id);
    }

    public function test_artifacts_can_be_listed_and_deleted(): void
    {
        [$organization, $project, $release] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite, ApiScope::ProjectRead]);

        $this->withToken($bearer)->post($this->url($organization, $project), [
            'name' => '~/app.js',
            'file' => UploadedFile::fake()->createWithContent('app.js', 'var a=1;'),
        ])->assertStatus(201);

        $this->withToken($bearer)
            ->getJson($this->url($organization, $project))
            ->assertOk()
            ->assertJsonPath('data.0.name', '~/app.js')
            ->assertJsonPath('meta.total', 1);

        $artifact = ReleaseArtifact::query()->firstOrFail();
        $path = $artifact->path;

        $this->withToken($bearer)
            ->deleteJson($this->url($organization, $project, suffix: '/'.$artifact->id))
            ->assertStatus(204);

        $this->assertSame(0, ReleaseArtifact::query()->count());
        // Zeile weg, Inhalt weg — es zeigt keine andere Zeile mehr darauf.
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(0, $release->artifacts()->count());
    }

    public function test_the_content_survives_the_deletion_of_a_second_path_pointing_at_it(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        foreach (['~/app.js', '~/app.min.js'] as $name) {
            $this->withToken($bearer)->post($this->url($organization, $project), [
                'name' => $name,
                'file' => UploadedFile::fake()->createWithContent('app.js', 'var a=1;'),
            ])->assertStatus(201);
        }

        $artifacts = ReleaseArtifact::query()->orderBy('id')->get();

        // Derselbe Inhalt, ein Ablagepfad: die Prüfsumme ist der Pfad.
        $this->assertSame($artifacts[0]->path, $artifacts[1]->path);

        $this->withToken($bearer)
            ->deleteJson($this->url($organization, $project, suffix: '/'.$artifacts[0]->id))
            ->assertStatus(204);

        // Den Inhalt hier wegzuwerfen würde das zweite Artefakt still entwerten.
        Storage::disk('local')->assertExists($artifacts[1]->path);
    }

    public function test_a_file_over_the_size_limit_is_rejected_as_a_validation_error(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        config()->set('sourcemaps.max_file_bytes', 1024);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'name' => '~/app.js',
                'file' => UploadedFile::fake()->create('app.js', 4),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_a_new_path_over_the_count_limit_is_rejected_but_a_replacement_is_not(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        config()->set('sourcemaps.max_files_per_release', 1);

        $this->withToken($bearer)->post($this->url($organization, $project), [
            'name' => '~/app.js',
            'file' => UploadedFile::fake()->createWithContent('app.js', 'var a=1;'),
        ])->assertStatus(201);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'name' => '~/zwei.js',
                'file' => UploadedFile::fake()->createWithContent('zwei.js', 'var b=1;'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        // Eine Version an der Grenze muss eine falsch hochgeladene Datei noch
        // berichtigen können — sonst ist sie nicht mehr zu retten.
        $this->withToken($bearer)
            ->post($this->url($organization, $project), [
                'name' => '~/app.js',
                'file' => UploadedFile::fake()->createWithContent('app.js', 'var a=2;'),
            ])
            ->assertStatus(200);
    }

    public function test_an_unknown_release_is_not_found(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectRead]);

        $this->withToken($bearer)
            ->getJson($this->url($organization, $project, '9.9.9'))
            ->assertStatus(404);
    }

    public function test_an_artifact_of_another_release_cannot_be_deleted(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectWrite]);

        $other = Release::forVersion($project, '1.2.0');
        $foreign = ReleaseArtifact::query()->create([
            'project_id' => $project->id,
            'release_id' => $other->id,
            'name' => '~/app.js',
            'kind' => ReleaseArtifactKind::Bundle,
            'size' => 8,
            'checksum' => sha1('var a=1;'),
            'path' => 'release-artifacts/x',
        ]);

        // Beide Angaben stehen in der Adresse, und eine vertauschte Zeile darf
        // kein fremdes Artefakt löschen.
        $this->withToken($bearer)
            ->deleteJson($this->url($organization, $project, suffix: '/'.$foreign->id))
            ->assertStatus(404);

        $this->assertSame(1, ReleaseArtifact::query()->count());
    }

    public function test_writing_requires_the_write_scope(): void
    {
        [$organization, $project] = $this->context();
        $bearer = $this->bearer($organization, [ApiScope::ProjectRead]);

        $this->withToken($bearer)
            ->postJson($this->url($organization, $project), [
                'name' => '~/app.js',
                'file' => UploadedFile::fake()->createWithContent('app.js', 'var a=1;'),
            ])
            ->assertStatus(403);
    }
}
