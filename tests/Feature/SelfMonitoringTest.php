<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SelfMonitoring\DeployedVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Errstack meldet an Errstack — über dieselben Wege, die eine fremde Anwendung
 * nimmt.
 *
 * Geprüft wird hier das, was der Weg **verlässt**: die Kopfzeile, die der
 * Browser auswertet, und die Angaben, mit denen die Oberfläche ihr SDK
 * einrichtet. Ob am anderen Ende etwas ankommt, ist Sache der Aufnahme und dort
 * geprüft; dass die Selbstüberwachung ohne DSN **nichts** tut, ist der Fall,
 * der im Auslieferungszustand gilt und deshalb hier steht.
 */
class SelfMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        return $user;
    }

    private function withDsn(): void
    {
        config()->set('sentry.dsn', 'https://abc123@errstack.example/7');
    }

    public function test_the_page_tells_the_browser_where_to_report_violations(): void
    {
        $this->withDsn();
        $this->signIn();

        $response = $this->get('/');

        // `Report-Only`: die Regel meldet, sie blockiert nicht. Eine
        // schärfende Richtlinie wäre eine Aussage über die Anwendung und
        // veraltet mit ihr — hier geht es um den Meldeweg.
        $header = $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertNotNull($header);
        $this->assertStringContainsString(
            'report-uri https://errstack.example/api/7/security/?sentry_key=abc123',
            $header,
        );
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    /**
     * Ohne Empfänger keine Richtlinie: eine, die ins Leere berichtet, kostet
     * den Browser Arbeit und bringt niemandem etwas.
     */
    public function test_without_a_dsn_no_policy_is_sent(): void
    {
        config()->set('sentry.dsn', null);
        $this->signIn();

        $this->get('/')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_the_policy_can_be_switched_off(): void
    {
        $this->withDsn();
        config()->set('selfmonitoring.csp.enabled', false);
        $this->signIn();

        $this->get('/')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    /**
     * Die Aufnahme selbst trägt keine Richtlinie: dort antwortet kein Dokument,
     * sondern JSON an ein SDK.
     */
    public function test_the_ingest_answer_carries_no_policy(): void
    {
        $this->withDsn();

        $this->post('/api/1/store', [])
            ->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_the_interface_gets_what_it_needs_to_report_itself(): void
    {
        $this->withDsn();
        config()->set('selfmonitoring.browser.traces_sample_rate', 0.25);
        $this->signIn();

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            // Dieselbe Angabe wie serverseitig und keine zweite: ein Wechsel
            // der Installation ist eine Zeile in der `.env` und kein Build.
            ->where('selfMonitoring.dsn', 'https://abc123@errstack.example/7')
            ->where('selfMonitoring.tracesSampleRate', 0.25)
        );
    }

    public function test_without_a_dsn_the_interface_loads_no_sdk(): void
    {
        config()->set('sentry.dsn', null);
        $this->signIn();

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selfMonitoring', null)
        );
    }

    /**
     * Die Version steht in einer Datei, wenn die Auslieferung keine
     * Umgebungsvariable setzt — und die Umgebungsvariable schlägt die Datei,
     * damit ein Notfall-Deploy nicht an einer alten Datei hängenbleibt.
     */
    public function test_the_deployed_version_comes_from_the_environment_first(): void
    {
        $path = $this->versionFile('aus-der-datei');

        $this->assertSame('aus-der-umgebung', DeployedVersion::resolve('aus-der-umgebung', $path));
        $this->assertSame('aus-der-datei', DeployedVersion::resolve(null, $path));
        $this->assertSame('aus-der-datei', DeployedVersion::resolve('  ', $path));
    }

    public function test_only_the_first_line_of_the_version_file_counts(): void
    {
        $path = $this->versionFile("a1b2c3d\nnoch eine Zeile\n");

        $this->assertSame('a1b2c3d', DeployedVersion::resolve(null, $path));
    }

    /**
     * Ohne Quelle keine Version — und keine erfundene: eine geratene Angabe
     * ordnete Fehler einer Auslieferung zu, die es nie gab.
     */
    public function test_without_any_source_there_is_no_version(): void
    {
        $path = storage_path('framework/testing/selfmonitoring-leer-'.uniqid());

        File::ensureDirectoryExists($path);

        $this->assertNull(DeployedVersion::resolve(null, $path));
    }

    private function versionFile(string $contents): string
    {
        $path = storage_path('framework/testing/selfmonitoring-'.uniqid());

        File::ensureDirectoryExists($path);
        File::put($path.'/VERSION', $contents);

        return $path;
    }
}
