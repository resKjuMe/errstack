<?php

namespace Tests\Feature\Uptime;

use App\Enums\HttpMethod;
use App\Enums\UptimeCheckOutcome;
use App\Models\UptimeMonitor;
use App\Support\Uptime\StatusExpectation;
use App\Support\Uptime\UptimeProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Die Bewertung einer einzelnen Prüfung — und die Wiederholung zur Bestätigung.
 *
 * Der Kern ist nicht, dass HTTP funktioniert, sondern **was als erreichbar
 * gilt**: eine Fehlerseite mit Status 200 ist der Ausfall, den eine reine
 * Statusprüfung übersieht, und ein verlorenes Paket ist der Fehlalarm, den eine
 * Überwachung ohne Bestätigung dauernd meldet.
 */
class UptimeProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Die Wartezeit vor der Bestätigung ist eine echte Sekunde. Sie gehört
        // zur Sache, nicht in die Laufzeit der Testsuite.
        Sleep::fake();
    }

    private function probe(): UptimeProbe
    {
        return app(UptimeProbe::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function monitor(array $attributes = []): UptimeMonitor
    {
        return UptimeMonitor::factory()->create($attributes + ['url' => 'https://ziel.test/health']);
    }

    public function test_an_expected_answer_counts_as_reachable(): void
    {
        Http::fake(['ziel.test/*' => Http::response('ok', 200)]);

        $result = $this->probe()->run($this->monitor());

        $this->assertSame(UptimeCheckOutcome::Up, $result->outcome);
        $this->assertSame(200, $result->httpStatus);
        $this->assertSame(1, $result->attempts);
        $this->assertNotNull($result->responseTimeMs);
    }

    /**
     * Eine Weiterleitung ist kein Ausfall — deshalb ein Bereich statt eines
     * einzelnen erwarteten Codes.
     */
    public function test_a_status_outside_the_expectation_is_an_outage(): void
    {
        Http::fake(['ziel.test/*' => Http::response('kaputt', 503)]);

        $result = $this->probe()->run($this->monitor());

        $this->assertSame(UptimeCheckOutcome::StatusMismatch, $result->outcome);
        $this->assertSame(503, $result->httpStatus);
        $this->assertStringContainsString('503', (string) $result->error);
    }

    public function test_a_status_inside_a_configured_range_is_accepted(): void
    {
        Http::fake(['ziel.test/*' => Http::response('', 301)]);

        $result = $this->probe()->run($this->monitor([
            'expected_status_codes' => '200-299,301',
            'follow_redirects' => false,
        ]));

        $this->assertSame(UptimeCheckOutcome::Up, $result->outcome);
    }

    /**
     * Der Fall, um den es bei der Inhaltsprüfung geht: der Webserver antwortet,
     * die Anwendung nicht.
     */
    public function test_a_missing_expected_text_is_an_outage_despite_status_200(): void
    {
        Http::fake(['ziel.test/*' => Http::response('<h1>Wartungsarbeiten</h1>', 200)]);

        $result = $this->probe()->run($this->monitor(['expected_content' => 'Willkommen']));

        $this->assertSame(UptimeCheckOutcome::ContentMismatch, $result->outcome);
        $this->assertSame(200, $result->httpStatus);
    }

    public function test_a_present_expected_text_is_accepted(): void
    {
        Http::fake(['ziel.test/*' => Http::response('<h1>Willkommen im Webshop</h1>', 200)]);

        $result = $this->probe()->run($this->monitor(['expected_content' => 'Willkommen']));

        $this->assertSame(UptimeCheckOutcome::Up, $result->outcome);
    }

    /**
     * Eine Zeitüberschreitung wird von einem fehlenden Kontakt unterschieden:
     * „die Gegenstelle ist weg" und „sie steht" sind zwei verschiedene
     * Auskünfte, und die erste Rückfrage nach einer Meldung ist genau die.
     */
    public function test_a_timeout_is_recorded_as_such(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->assertSame(UptimeCheckOutcome::Timeout, $this->probe()->run($this->monitor())->outcome);
    }

    /**
     * Zweiter Test statt einer zweiten Zusicherung im ersten: `Http::fake()`
     * **ergänzt** seine Stubs, statt sie zu ersetzen — der erste bliebe stehen
     * und würde weiter zuerst greifen.
     */
    public function test_a_dead_host_is_told_apart_from_a_timeout(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->assertSame(UptimeCheckOutcome::ConnectionFailed, $this->probe()->run($this->monitor())->outcome);
    }

    /**
     * **Der wichtigste Test dieser Datei.** Ein einzelner Aussetzer darf keinen
     * Ausfall ergeben — die Bestätigung fängt ihn ab, und die Prüfung gilt als
     * erfolgreich.
     */
    public function test_a_single_hiccup_is_caught_by_the_confirmation(): void
    {
        Http::fakeSequence('ziel.test/*')
            ->push('', 500)
            ->push('ok', 200);

        $result = $this->probe()->run($this->monitor(['confirmation_retries' => 1]));

        $this->assertSame(UptimeCheckOutcome::Up, $result->outcome);
        // Die Zahl der Anläufe bleibt am Ergebnis stehen: ein wackelndes Ziel
        // soll im Verlauf auffallen, bevor es ausfällt.
        $this->assertSame(2, $result->attempts);
    }

    public function test_a_real_outage_survives_the_confirmation(): void
    {
        Http::fake(['ziel.test/*' => Http::response('', 500)]);

        $result = $this->probe()->run($this->monitor(['confirmation_retries' => 2]));

        $this->assertSame(UptimeCheckOutcome::StatusMismatch, $result->outcome);
        $this->assertSame(3, $result->attempts);

        Http::assertSentCount(3);
    }

    public function test_without_confirmation_only_one_request_goes_out(): void
    {
        Http::fake(['ziel.test/*' => Http::response('', 500)]);

        $this->probe()->run($this->monitor(['confirmation_retries' => 0]));

        Http::assertSentCount(1);
    }

    /**
     * Die eingestellten Kopfzeilen und das Verfahren kommen bei der Gegenstelle
     * an — sonst prüfte die Überwachung etwas anderes als eingestellt.
     */
    public function test_method_and_headers_are_sent(): void
    {
        Http::fake(['ziel.test/*' => Http::response('ok', 200)]);

        $this->probe()->run($this->monitor([
            'method' => HttpMethod::Post,
            'headers' => [['name' => 'Authorization', 'value' => 'Bearer geheim']],
            'body' => '{"ping":true}',
        ]));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer geheim')
            && $request->body() === '{"ping":true}');
    }

    /**
     * Die Auslegung der Statuscode-Angabe — die einzige Stelle, die den Text
     * liest, und damit die, an der ein Tippfehler nicht zu einer stillschweigend
     * anderen Erwartung werden darf.
     */
    public function test_the_status_expectation_is_read_the_same_way_everywhere(): void
    {
        $expectation = StatusExpectation::parse('200-299, 301,404');

        $this->assertTrue($expectation->matches(200));
        $this->assertTrue($expectation->matches(299));
        $this->assertTrue($expectation->matches(301));
        $this->assertTrue($expectation->matches(404));
        $this->assertFalse($expectation->matches(300));
        $this->assertFalse($expectation->matches(500));

        $this->assertTrue(StatusExpectation::isValid('200-299,301'));
        $this->assertFalse(StatusExpectation::isValid('2xx'));
        $this->assertFalse(StatusExpectation::isValid('299-200'));
        $this->assertFalse(StatusExpectation::isValid(''));

        // Eine unlesbare Angabe lässt im Betrieb nichts durch, statt eine
        // Ausnahme zu werfen: eine Überwachung, die im Zweifel „alles in
        // Ordnung" meldet, wäre schlimmer als keine.
        $this->assertFalse(StatusExpectation::parse('kaputt')->matches(200));
    }
}
