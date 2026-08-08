<?php

namespace Tests\Unit;

use App\Support\SelfMonitoring\Dsn;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aus einer Angabe werden vier Meldewege. Geht das Zerlegen schief, meldet die
 * Anwendung entweder nichts oder — schlimmer — an die falsche Installation,
 * und beides fällt erst auf, wenn jemand die erwarteten Fehler vermisst.
 */
class SelfMonitoringDsnTest extends TestCase
{
    public function test_a_dsn_is_taken_apart_into_host_key_and_project(): void
    {
        $dsn = Dsn::parse('https://abc123@errstack.example/7');

        $this->assertNotNull($dsn);
        $this->assertSame('abc123', $dsn->publicKey);
        $this->assertSame('7', $dsn->projectId);
        $this->assertSame('https://errstack.example', $dsn->baseUrl());
    }

    /**
     * Der Schlüssel steht im Abfrageteil und nicht in einer Kopfzeile:
     * `report-uri` nimmt eine Adresse und sonst nichts.
     */
    public function test_the_security_endpoint_carries_the_key_in_the_query(): void
    {
        $dsn = Dsn::parse('https://abc123@errstack.example/7');

        $this->assertNotNull($dsn);
        $this->assertSame(
            'https://errstack.example/api/7/security/?sentry_key=abc123',
            $dsn->securityReportUrl(),
        );
    }

    public function test_the_check_in_address_names_monitor_and_key(): void
    {
        $dsn = Dsn::parse('https://abc123@errstack.example/7');

        $this->assertNotNull($dsn);
        $this->assertSame(
            'https://errstack.example/api/7/cron/zeitplan/abc123',
            $dsn->cronCheckInUrl('zeitplan'),
        );
    }

    /**
     * Liegt die Installation in einem Unterverzeichnis, gehört das vor `/api`
     * und nicht hinter die Projekt-Nummer — sonst zeigt jede abgeleitete
     * Adresse am Einstiegspunkt vorbei.
     */
    public function test_a_subdirectory_stays_in_front_of_the_endpoints(): void
    {
        $dsn = Dsn::parse('https://abc123@example.test:8443/werkzeuge/errstack/12');

        $this->assertNotNull($dsn);
        $this->assertSame('https://example.test:8443/werkzeuge/errstack', $dsn->baseUrl());
        $this->assertSame(
            'https://example.test:8443/werkzeuge/errstack/api/12/cron/zeitplan/abc123',
            $dsn->cronCheckInUrl('zeitplan'),
        );
    }

    /**
     * Die Fassung für den Browser wird aus den zerlegten Teilen wieder
     * zusammengesetzt und nicht durchgereicht: dort soll dieselbe Angabe
     * ankommen, die auch hier gelesen wurde.
     */
    public function test_the_dsn_survives_the_round_trip(): void
    {
        foreach (['https://abc123@errstack.example/7', 'http://k@localhost:8000/1'] as $original) {
            $dsn = Dsn::parse($original);

            $this->assertNotNull($dsn);
            $this->assertSame($original, $dsn->toString());
        }
    }

    /**
     * Eine halb ausgefüllte Zeile in der `.env` darf die Anwendung nicht am
     * Starten hindern. Was nicht zu lesen ist, meldet nichts.
     *
     * @param  string|null  $dsn  die unbrauchbare Angabe
     */
    #[DataProvider('unusableDsns')]
    public function test_an_unusable_dsn_is_no_dsn(?string $dsn): void
    {
        $this->assertNull(Dsn::parse($dsn));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unusableDsns(): array
    {
        return [
            'leer' => [''],
            'fehlt' => [null],
            'nur Leerzeichen' => ['   '],
            'ohne Schlüssel' => ['https://errstack.example/7'],
            'ohne Projekt' => ['https://abc123@errstack.example'],
            'Projekt ist keine Nummer' => ['https://abc123@errstack.example/sieben'],
        ];
    }
}
