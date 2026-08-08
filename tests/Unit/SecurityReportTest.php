<?php

namespace Tests\Unit;

use App\Enums\SecurityReportType;
use App\Support\Ingest\Security\ExtensionNoise;
use App\Support\Ingest\Security\SecurityReport;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Die Umwandlung eines Browser-Berichts in ein Ereignis — ohne HTTP, ohne
 * Datenbank.
 *
 * Der Unterschied zum Test des Endpunkts (Tests\Feature\Ingest\SecurityEndpointTest)
 * ist die Frage: dort, ob ein Bericht ankommt und als Fehler-Eintrag erscheint —
 * hier, ob **das Richtige** drinsteht. Überschrift, Fehlerstelle und vor allem
 * der Fingerabdruck entscheiden darüber, ob aus zehntausend Verstößen ein
 * Eintrag wird oder zehntausend.
 */
class SecurityReportTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function csp(array $overrides = []): array
    {
        return ['csp-report' => $overrides + [
            'document-uri' => 'https://shop.example/kasse',
            'referrer' => 'https://shop.example/',
            'violated-directive' => "script-src 'self'",
            'effective-directive' => 'script-src',
            'original-policy' => "default-src 'self'; report-uri /api/1/security/",
            'disposition' => 'enforce',
            'blocked-uri' => 'https://werbung.example/tracker.js?v=17',
            'status-code' => 200,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function event(array $data): array
    {
        $report = SecurityReport::from($data);

        $this->assertNotNull($report);

        return $report->toEvent('0123456789abcdef0123456789abcdef', Carbon::parse('2026-08-08T10:00:00Z'));
    }

    public function test_a_csp_report_becomes_an_event_with_the_blocked_source_as_its_culprit(): void
    {
        $event = $this->event($this->csp());

        $this->assertSame('0123456789abcdef0123456789abcdef', $event['event_id']);
        $this->assertSame('javascript', $event['platform']);
        $this->assertSame('warning', $event['level']);
        $this->assertSame('csp', $event['logger']);

        // Auf den Ursprung gekürzt: der Pfad ist bei derselben Erweiterung, der
        // eingeklinkten Werbung, dem Virenscanner jedes Mal ein anderer.
        $this->assertSame('https://werbung.example', $event['culprit']);

        $this->assertSame(
            'Sicherheitsrichtlinie verletzt: script-src blockierte https://werbung.example',
            $event['logentry']['formatted'],
        );

        // Die betroffene Seite steht als Anfrage daneben — sie ist nicht die
        // Fehlerstelle, aber die Auskunft, wo es aufgefallen ist.
        $this->assertSame('https://shop.example/kasse', $event['request']['url']);

        // Der Bericht im Original bleibt erhalten: `original-policy` ist die
        // einzige Stelle, an der die ganze Richtlinie steht.
        $this->assertSame(
            "default-src 'self'; report-uri /api/1/security/",
            $event['extra']['csp-report']['original-policy'],
        );
    }

    public function test_csp_reports_group_by_directive_and_blocked_origin(): void
    {
        $first = $this->event($this->csp(['blocked-uri' => 'https://werbung.example/a.js']));
        $second = $this->event($this->csp(['blocked-uri' => 'https://werbung.example/b.js?x=1#top']));

        // Derselbe Ursprung, andere Datei: ein Befund und nicht zwei.
        $this->assertSame(['csp', 'script-src', 'https://werbung.example'], $first['fingerprint']);
        $this->assertSame($first['fingerprint'], $second['fingerprint']);

        // Anderer Anschluss ist ein anderer Ursprung — die Richtlinie sieht das
        // genauso.
        $other = $this->event($this->csp(['blocked-uri' => 'https://werbung.example:8443/a.js']));
        $this->assertSame(['csp', 'script-src', 'https://werbung.example:8443'], $other['fingerprint']);

        // Andere Direktive, gleiche Quelle: zwei Befunde. Ein blockiertes Bild
        // ist etwas anderes als ein blockiertes Skript.
        $image = $this->event($this->csp([
            'effective-directive' => 'img-src',
            'blocked-uri' => 'https://werbung.example/a.js',
        ]));
        $this->assertNotSame($first['fingerprint'], $image['fingerprint']);
    }

    public function test_the_directive_falls_back_to_the_first_word_of_the_violated_directive(): void
    {
        // Ältere Browser kennen `effective-directive` nicht. Die Quellenliste
        // dahinter darf nicht in den Fingerabdruck: sonst gäbe es bei jeder
        // Änderung der Richtlinie eine neue Gruppe.
        $event = $this->event($this->csp([
            'effective-directive' => null,
            'violated-directive' => "script-src 'self' https://cdn.example",
        ]));

        $this->assertSame(['csp', 'script-src', 'https://werbung.example'], $event['fingerprint']);
        $this->assertSame('script-src', $event['tags']['directive']);
    }

    public function test_keywords_in_the_blocked_uri_survive_as_they_are(): void
    {
        // `inline`, `eval` und `self` sind keine Adressen, sondern die Art des
        // Verstoßes. Durch die Adress-Zerlegung geschickt blieben sie auf der
        // Strecke — und übrig wäre die Auskunft, um die es geht.
        foreach (['inline', 'eval', 'self', 'data'] as $keyword) {
            $event = $this->event($this->csp(['blocked-uri' => $keyword]));

            $this->assertSame($keyword, $event['culprit']);
            $this->assertSame(['csp', 'script-src', $keyword], $event['fingerprint']);
        }
    }

    public function test_a_report_without_an_envelope_is_still_recognised(): void
    {
        // Einzelne Browser und die meisten Werkzeuge, mit denen jemand einen
        // Bericht von Hand nachstellt, lassen den Umschlag weg.
        $report = SecurityReport::from([
            'document-uri' => 'https://shop.example/',
            'effective-directive' => 'style-src',
            'blocked-uri' => 'https://fonts.example/stil.css',
        ]);

        $this->assertNotNull($report);
        $this->assertSame(SecurityReportType::Csp, $report->type());
    }

    public function test_an_expect_ct_report_groups_by_hostname(): void
    {
        $event = $this->event(['expect-ct-report' => [
            'date-time' => '2026-08-08T09:59:00Z',
            'hostname' => 'shop.example',
            'port' => 443,
            'scheme' => 'https',
            'failure-mode' => 'enforce',
            'served-certificate-chain' => ['-----BEGIN CERTIFICATE-----'],
        ]]);

        $this->assertSame('expect-ct', $event['logger']);
        $this->assertSame('shop.example:443', $event['culprit']);
        $this->assertSame(['expect-ct', 'shop.example'], $event['fingerprint']);
        $this->assertSame('Certificate Transparency verletzt: shop.example:443', $event['logentry']['formatted']);
        $this->assertSame('enforce', $event['tags']['failure_mode']);
    }

    public function test_an_expect_staple_report_groups_by_hostname_and_finding(): void
    {
        $missing = $this->event(['expect-staple-report' => [
            'hostname' => 'shop.example',
            'port' => 443,
            'response-status' => 'MISSING',
        ]]);

        $expired = $this->event(['expect-staple-report' => [
            'hostname' => 'shop.example',
            'port' => 443,
            'response-status' => 'GOOD',
            'cert-status' => 'EXPIRED',
        ]]);

        // Eine fehlende Antwort ist ein Problem der Auslieferung, eine
        // abgelaufene eines des Zertifikats — zwei Einträge.
        $this->assertSame(['expect-staple', 'shop.example', 'MISSING'], $missing['fingerprint']);
        $this->assertSame(['expect-staple', 'shop.example', 'EXPIRED'], $expired['fingerprint']);
    }

    public function test_anything_else_is_not_a_security_report(): void
    {
        $this->assertNull(SecurityReport::from(['message' => 'Etwas ist kaputt.']));
        $this->assertNull(SecurityReport::from([]));

        // Der Umschlag ist da, sein Inhalt aber keine Felder: eine Liste hat
        // keine, und daraus einen Bericht zu bauen hieße, überall `null` zu
        // finden.
        $this->assertNull(SecurityReport::from(['csp-report' => ['a', 'b']]));
    }

    public function test_reports_from_browser_extensions_are_recognised_as_noise(): void
    {
        $extension = SecurityReport::from($this->csp([
            'blocked-uri' => 'chrome-extension://mihcahmgecmbnbcchbopgniflfhgnkff/inject.js',
        ]));

        $this->assertNotNull($extension);
        $this->assertSame('chrome-extension://', ExtensionNoise::match($extension));

        // Auch dann, wenn die Erweiterung nur ein Skript in die Seite schreibt:
        // in `blocked-uri` steht dann `inline`, und das sähe aus wie ein Befund
        // über die Anwendung.
        $injected = SecurityReport::from($this->csp([
            'blocked-uri' => 'inline',
            'source-file' => 'moz-extension://a1b2c3/content.js',
        ]));

        $this->assertNotNull($injected);
        $this->assertSame('moz-extension://', ExtensionNoise::match($injected));

        // Chromes eigene Oberfläche meldet schlicht `about` — ohne `:` und ohne
        // `//`, weshalb der Vergleich mit den Schemata daran vorbeigeht.
        $bare = SecurityReport::from($this->csp(['blocked-uri' => 'about']));

        $this->assertNotNull($bare);
        $this->assertSame('about', ExtensionNoise::match($bare));
    }

    public function test_a_real_violation_is_not_mistaken_for_noise(): void
    {
        $report = SecurityReport::from($this->csp());

        $this->assertNotNull($report);
        $this->assertNull(ExtensionNoise::match($report));

        // Ein Wirt, der bloß `about` im Namen trägt, ist keine Erweiterung —
        // verglichen wird auf den ganzen Wert.
        $about = SecurityReport::from($this->csp(['blocked-uri' => 'https://about.example/a.js']));

        $this->assertNotNull($about);
        $this->assertNull(ExtensionNoise::match($about));
    }
}
