<?php

namespace Tests\Unit;

use App\Support\Releases\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Das Zerlegen einer Versionsangabe.
 *
 * Der Test hängt an keiner Datenbank, weil die Zerlegung an keiner hängt — und
 * er ist der einzige Ort, an dem die Randfälle vollständig durchgegangen
 * werden: was eine Nummer ist, was keine, und was passiert, wenn jemand einen
 * Zeitstempel als Version schickt.
 */
class ReleaseVersionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int|null, 2: int|null, 3: int|null, 4: string|null}>
     */
    public static function versions(): array
    {
        return [
            'drei Zahlen' => ['1.4.2', 1, 4, 2, null],
            'zwei Zahlen werden aufgefüllt' => ['1.4', 1, 4, 0, null],
            'eine Zahl wird aufgefüllt' => ['7', 7, 0, 0, null],
            'führendes v' => ['v2.0.0', 2, 0, 0, null],
            'Vorabteil' => ['1.0.0-rc.1', 1, 0, 0, 'rc.1'],
            'Bauteil zählt nicht zur Rangfolge' => ['1.0.0+abc123', 1, 0, 0, null],
            'Vorab- und Bauteil' => ['1.0.0-beta+abc', 1, 0, 0, 'beta'],
            'Paket vor der Nummer' => ['mein-dienst@3.1.0', 3, 1, 0, null],
            'Paket mit eigenem @' => ['@meine-firma/web@1.2.3', 1, 2, 3, null],
            'Leerraum außen herum' => ['  1.2.3  ', 1, 2, 3, null],
            'Commit-Hash hat keine Rangfolge' => ['a1b2c3d4', null, null, null, null],
            'Text hat keine Rangfolge' => ['nightly', null, null, null, null],
            'leer' => ['', null, null, null, null],
            'vier Stellen sind keine Nummer mehr' => ['1.2.3.4', null, null, null, null],
            'unfassbar große Zahl' => ['999999999999999999999.0.0', null, null, null, null],
        ];
    }

    #[DataProvider('versions')]
    public function test_it_splits_a_version(string $version, ?int $major, ?int $minor, ?int $patch, ?string $prerelease): void
    {
        $parsed = Version::parse($version);

        $this->assertSame($major, $parsed->major, $version);
        $this->assertSame($minor, $parsed->minor, $version);
        $this->assertSame($patch, $parsed->patch, $version);
        $this->assertSame($prerelease, $parsed->prerelease, $version);
    }

    public function test_only_a_readable_number_has_an_order(): void
    {
        $this->assertTrue(Version::parse('1.0.0')->isOrdered());
        $this->assertFalse(Version::parse('a1b2c3d4')->isOrdered());
    }

    public function test_the_columns_carry_the_split_parts(): void
    {
        $this->assertSame([
            'sort_major' => 1,
            'sort_minor' => 2,
            'sort_patch' => 3,
            'sort_prerelease' => 'rc.1',
        ], Version::parse('1.2.3-rc.1')->columns());
    }

    /**
     * Der Vorabteil wird gekürzt und nicht abgewiesen: eine ungewöhnlich lange
     * Angabe soll ihre Meldung nicht verlieren.
     */
    public function test_a_long_prerelease_is_shortened(): void
    {
        $parsed = Version::parse('1.0.0-'.str_repeat('a', 250));

        $this->assertNotNull($parsed->prerelease);
        $this->assertSame(100, mb_strlen($parsed->prerelease));
    }
}
