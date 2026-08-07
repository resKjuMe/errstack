<?php

namespace Tests\Feature;

use App\Support\Locales;
use App\Support\Translations;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Zwei Sprachen bleiben nur dann zwei vollständige Sprachen, wenn das Fehlen
 * eines Schlüssels wehtut. Ohne diese Prüfung fällt eine Lücke erst im Betrieb
 * auf — als halb übersetzte Seite oder, schlimmer, als roher Schlüssel
 * („projects.settings.name") mitten im Text.
 *
 * Geprüft wird der Schlüssel-Bestand, nicht der Inhalt: dass irgendwo Deutsch
 * statt Englisch steht, kann kein Test wissen. Dass ein Schlüssel fehlt, schon.
 */
class TranslationParityTest extends TestCase
{
    public function test_every_language_has_the_same_files(): void
    {
        $reference = self::filesOf(Locales::SUPPORTED[0]);

        foreach (Locales::SUPPORTED as $locale) {
            $this->assertSame(
                $reference,
                self::filesOf($locale),
                "Die Sprache „{$locale}\" hat nicht dieselben Sprachdateien wie „".Locales::SUPPORTED[0].'".',
            );
        }
    }

    public function test_every_language_has_the_same_keys(): void
    {
        $base = Locales::SUPPORTED[0];

        foreach (self::filesOf($base) as $group) {
            $expected = self::keysOf($base, $group);

            foreach (Locales::SUPPORTED as $locale) {
                $actual = self::keysOf($locale, $group);

                $missing = array_diff($expected, $actual);
                $extra = array_diff($actual, $expected);

                $this->assertSame([], array_values($missing), sprintf(
                    'In lang/%s/%s.php fehlen Schlüssel: %s',
                    $locale,
                    $group,
                    implode(', ', $missing),
                ));

                $this->assertSame([], array_values($extra), sprintf(
                    'In lang/%s/%s.php stehen Schlüssel, die es in „%s" nicht gibt: %s',
                    $locale,
                    $group,
                    $base,
                    implode(', ', $extra),
                ));
            }
        }
    }

    /**
     * Die Oberfläche liest ihre Texte über eine feste Liste von Gruppen. Fehlt
     * eine davon, bleibt die Seite stumm — sie zeigt dann die Schlüssel selbst.
     */
    public function test_the_interface_groups_exist_in_every_language(): void
    {
        foreach (Locales::SUPPORTED as $locale) {
            $files = self::filesOf($locale);

            foreach (Translations::GROUPS as $group) {
                $this->assertContains($group, $files, "lang/{$locale}/{$group}.php fehlt.");
            }
        }
    }

    /**
     * Jeder Schlüssel muss auch über `__()` erreichbar sein. Das ist keine
     * Doppelung der Bestandsprüfung: ein Schlüssel, der selbst einen Punkt
     * enthält (`team.created`), steht zwar in der Datei, wird beim Nachschlagen
     * aber als Pfad in eine tiefere Ebene gelesen — und kommt dann als roher
     * Schlüssel in der Oberfläche an.
     */
    public function test_every_key_can_be_looked_up(): void
    {
        $base = Locales::SUPPORTED[0];

        foreach (Locales::SUPPORTED as $locale) {
            $this->app->setLocale($locale);

            foreach (self::filesOf($base) as $group) {
                foreach (self::keysOf($locale, $group) as $key) {
                    $full = $group.'.'.$key;

                    $this->assertNotSame(
                        $full,
                        __($full),
                        "„{$full}\" ist in „{$locale}\" nicht auffindbar — enthält der Schlüssel einen Punkt?",
                    );
                }
            }
        }
    }

    public function test_the_interface_table_is_filled_in_every_language(): void
    {
        foreach (Locales::SUPPORTED as $locale) {
            $this->app->setLocale($locale);

            $strings = Translations::forInterface();

            $this->assertNotSame([], $strings);

            // Ein unübersetzter Schlüssel kommt als sein eigener Name zurück —
            // in der Oberfläche stünde dann „common.save" statt „Speichern".
            foreach ($strings as $key => $value) {
                $this->assertNotSame($key, $value, "„{$key}\" ist in „{$locale}\" nicht übersetzt.");
            }
        }
    }

    /**
     * Die Schreibweisen für Datum und Zahlen sind Teil der Sprache, nicht des
     * Codes — fehlt eine davon, formatiert der Server plötzlich mit dem
     * Schlüsselnamen als Muster.
     */
    public function test_every_language_brings_its_formats(): void
    {
        foreach (Locales::SUPPORTED as $locale) {
            $this->app->setLocale($locale);

            foreach (['date', 'date_time', 'date_time_seconds', 'intl', 'decimal_separator', 'thousands_separator'] as $key) {
                $value = __("formats.{$key}");

                $this->assertIsString($value);
                $this->assertNotSame("formats.{$key}", $value, "formats.{$key} fehlt in „{$locale}\".");
            }
        }
    }

    /**
     * Namen der Sprachdateien einer Sprache, ohne Endung.
     *
     * @return list<string>
     */
    private static function filesOf(string $locale): array
    {
        $files = glob(lang_path($locale.'/*.php')) ?: [];

        $names = array_map(
            static fn (string $path): string => basename($path, '.php'),
            $files,
        );

        sort($names);

        return $names;
    }

    /**
     * Alle Schlüssel einer Sprachdatei, flach und sortiert.
     *
     * @return list<string>
     */
    private static function keysOf(string $locale, string $group): array
    {
        $path = lang_path($locale.'/'.$group.'.php');

        if (! is_file($path)) {
            return [];
        }

        $lines = require $path;

        $keys = array_keys(Arr::dot(is_array($lines) ? $lines : []));

        sort($keys);

        return $keys;
    }
}
