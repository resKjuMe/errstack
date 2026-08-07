<?php

namespace Tests\Unit;

use App\Support\Ingest\IngestAuth;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Das Lesen der Zugangsdaten einer eingehenden Meldung.
 *
 * Eigener Test neben dem des Endpunkts, weil hier die Sonderfälle stecken:
 * Leerzeichen, Anführungszeichen, Reihenfolge der Paare und fremde
 * Anmeldeverfahren in derselben Kopfzeile. Über den Endpunkt wären das ein
 * Dutzend Aufrufe für eine Zeichenkette.
 */
class IngestAuthTest extends TestCase
{
    /**
     * @param  array<string, string>  $headers
     */
    private function request(array $headers = [], string $uri = '/api/1/store/'): Request
    {
        $request = Request::create($uri, 'POST');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    public function test_it_reads_the_key_from_the_sentry_auth_header(): void
    {
        $request = $this->request([
            'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=sentry.php/4.0.0, sentry_key=abc123',
        ]);

        $this->assertSame('abc123', IngestAuth::publicKey($request));
        $this->assertSame('sentry.php/4.0.0', IngestAuth::client($request));
    }

    /**
     * Die Paare stehen nicht in fester Reihenfolge, und einzelne SDKs setzen
     * Anführungszeichen oder zusätzliche Leerzeichen.
     */
    public function test_it_reads_the_key_regardless_of_order_quotes_and_spacing(): void
    {
        $request = $this->request([
            'X-Sentry-Auth' => 'Sentry  sentry_key="abc123" ,sentry_version=7',
        ]);

        $this->assertSame('abc123', IngestAuth::publicKey($request));
    }

    public function test_it_reads_the_key_from_the_query_string(): void
    {
        $request = $this->request(uri: '/api/1/store/?sentry_key=abc123');

        $this->assertSame('abc123', IngestAuth::publicKey($request));
    }

    public function test_the_sentry_auth_header_wins_over_the_query_string(): void
    {
        $request = $this->request(
            ['X-Sentry-Auth' => 'Sentry sentry_key=aus-der-kopfzeile'],
            '/api/1/store/?sentry_key=aus-der-adresse',
        );

        $this->assertSame('aus-der-kopfzeile', IngestAuth::publicKey($request));
    }

    public function test_it_reads_the_key_from_a_bearer_header(): void
    {
        $request = $this->request(['Authorization' => 'Bearer abc123']);

        $this->assertSame('abc123', IngestAuth::publicKey($request));
        $this->assertNull(IngestAuth::client($request));
    }

    public function test_it_reads_the_key_from_a_sentry_style_authorization_header(): void
    {
        $request = $this->request(['Authorization' => 'Sentry sentry_version=7, sentry_key=abc123']);

        $this->assertSame('abc123', IngestAuth::publicKey($request));
    }

    /**
     * Ein fremdes Anmeldeverfahren darf hier nicht mitgelesen werden, bloß weil
     * es zufällig ein `=` enthält.
     */
    public function test_an_unrelated_authorization_header_yields_nothing(): void
    {
        $request = $this->request(['Authorization' => 'Basic sentry_key=abc123']);

        $this->assertNull(IngestAuth::publicKey($request));
    }

    public function test_without_credentials_there_is_no_key(): void
    {
        $this->assertNull(IngestAuth::publicKey($this->request()));
        $this->assertNull(IngestAuth::client($this->request()));
    }

    public function test_an_empty_key_counts_as_none(): void
    {
        $this->assertNull(IngestAuth::publicKey($this->request(['X-Sentry-Auth' => 'Sentry sentry_key='])));
        $this->assertNull(IngestAuth::publicKey($this->request(uri: '/api/1/store/?sentry_key=')));
    }

    /**
     * Die SDK-Angabe kommt vom Client und landet in einer Spalte mit fester
     * Breite — sie wird gekürzt, nicht abgewiesen.
     */
    public function test_an_overlong_client_is_shortened(): void
    {
        $request = $this->request([
            'X-Sentry-Auth' => 'Sentry sentry_key=abc123, sentry_client='.str_repeat('x', 400),
        ]);

        $this->assertSame(255, mb_strlen((string) IngestAuth::client($request)));
    }
}
