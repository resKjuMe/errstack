<?php

namespace Tests\Unit;

use App\Support\SourceMaps\SourceMap;
use PHPUnit\Framework\TestCase;

/**
 * Der Quellkarten-Leser: Kodierung, Nachschlagen, Quelltext.
 *
 * **Die Prüfwerte sind von Hand gerechnet und nicht mit einem Kodierer erzeugt.**
 * Das ist der ganze Punkt dieser Datei: ein Test, der die Karte mit derselben
 * Umsetzung schreibt, die er prüft, bestätigt nur, dass sie zu sich selbst passt.
 * Die Rechnung steht deshalb im Test.
 *
 * Die verwendete Karte, Zeichen für Zeichen:
 *
 *   mappings = "AAAA,UAEIA,w+BACA"
 *
 * Eine erzeugte Zeile (kein Semikolon), drei Einträge. Jede Zahl steht als
 * „variable length quantity" in Base64 — fünf Nutzbits je Zeichen, das sechste
 * heißt „es geht weiter", das niederwertigste Bit der ersten Gruppe ist das
 * Vorzeichen — und **relativ** zum vorigen Eintrag:
 *
 *   AAAA     → [0, 0, 0, 0]        erzeugte Spalte 0 → Quelle 0, Zeile 0, Spalte 0
 *   UAEIA    → [10, 0, 2, 4, 0]    Spalte 0+10 = 10 → Zeile 0+2 = 2, Spalte 4, Name 0
 *   w+BACA   → [1000, 0, 1, 0]     Spalte 10+1000 = 1010 → Zeile 2+1 = 3, Spalte 4
 *
 * Die Zeichen der zweiten Zahl von „UAEIA": 10 → (10 << 1) = 20 → Index 20 = „U".
 * Und die drei Zeichen von 1000: (1000 << 1) = 2000; 2000 & 31 = 16, mit
 * Fortsetzungs-Bit 48 → „w"; 2000 >> 5 = 62, 62 & 31 = 30, mit Fortsetzungs-Bit
 * 62 → „+"; 62 >> 5 = 1, ohne Fortsetzung → „B".
 */
class SourceMapTest extends TestCase
{
    private const MAPPINGS = 'AAAA,UAEIA,w+BACA';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function map(array $overrides = []): SourceMap
    {
        $map = SourceMap::fromJson((string) json_encode(array_replace([
            'version' => 3,
            'sources' => ['src/warenkorb.ts'],
            'sourcesContent' => ["const a = 1;\nconst b = 2;\nconst c = 3;\nconst d = 4;\nconst e = 5;\n"],
            'names' => ['berechneSumme'],
            'mappings' => self::MAPPINGS,
        ], $overrides)));

        $this->assertNotNull($map);

        return $map;
    }

    public function test_a_position_resolves_to_the_last_entry_before_it(): void
    {
        // Spalte 11 (1-basiert) ist Spalte 10 (0-basiert) und damit genau der
        // zweite Eintrag.
        $location = $this->map()->lookup(1, 11);

        $this->assertNotNull($location);
        $this->assertSame('src/warenkorb.ts', $location->file);
        $this->assertSame(3, $location->line);
        $this->assertSame(5, $location->column);
        $this->assertSame('berechneSumme', $location->function);
    }

    public function test_a_position_between_two_entries_resolves_to_the_earlier_one(): void
    {
        // Eine Karte verzeichnet nicht jedes Zeichen, sondern die Stellen, an
        // denen etwas beginnt. Spalte 6 liegt im Bereich des ersten Eintrags.
        $location = $this->map()->lookup(1, 6);

        $this->assertNotNull($location);
        $this->assertSame(1, $location->line);
        $this->assertSame(1, $location->column);
        // Der erste Eintrag hat keine fünfte Zahl und damit keinen Namen.
        $this->assertNull($location->function);
    }

    public function test_a_multi_character_number_is_decoded(): void
    {
        // Der dritte Eintrag steht bei Spalte 1010 und ist der einzige, dessen
        // Zahl über mehrere Zeichen läuft. Wäre die Fortsetzung falsch gelesen,
        // stünde er irgendwo bei Spalte 16.
        $location = $this->map()->lookup(1, 1011);

        $this->assertNotNull($location);
        $this->assertSame(4, $location->line);
    }

    public function test_a_position_before_the_first_entry_is_not_guessed(): void
    {
        // Spalte 1 ist der erste Eintrag selbst — das ist noch kein Fall davor.
        $this->assertNotNull($this->map()->lookup(1, 1));

        // Eine Karte, deren erster Eintrag nicht bei Spalte 0 steht, kennt den
        // Code davor nicht. Den ersten Eintrag trotzdem zu nehmen wäre eine
        // Angabe über etwas, das nicht verzeichnet ist.
        $shifted = $this->map(['mappings' => 'UAAAA']);

        $this->assertNull($shifted->lookup(1, 3));
        $this->assertNotNull($shifted->lookup(1, 11));
    }

    public function test_a_line_without_entries_resolves_to_nothing(): void
    {
        $this->assertNull($this->map()->lookup(2, 1));
        $this->assertNull($this->map()->lookup(99, 1));
    }

    public function test_the_first_entry_of_a_line_is_used_without_a_column(): void
    {
        // Rahmen ohne Spaltenangabe kommen von älteren Browsern. „Irgendwo in
        // dieser Datei" ist mehr als nichts.
        $location = $this->map()->lookup(1);

        $this->assertNotNull($location);
        $this->assertSame(1, $location->line);
    }

    public function test_empty_generated_lines_shift_the_following_ones(): void
    {
        // Zwei Semikolons ohne Inhalt: die Einträge gehören zur dritten erzeugten
        // Zeile. Wer leere Zeilen überspringt statt sie zu zählen, liest hier
        // Zeile 1 — und liegt im ganzen Bundle um zwei Zeilen daneben.
        $map = $this->map(['mappings' => ';;'.self::MAPPINGS]);

        $location = $map->lookup(3, 11);

        $this->assertNull($map->lookup(1, 1));
        $this->assertNotNull($location);
        $this->assertSame(3, $location->line);
    }

    public function test_a_negative_delta_moves_backwards(): void
    {
        // „J" ist -4: (4 << 1) | 1 = 9 → Index 9. Der zweite Eintrag zeigt damit
        // auf Spalte 4 - 4 = 0 der Quelle.
        $map = $this->map(['mappings' => 'AAAI,UAAJ']);

        $this->assertSame(5, $map->lookup(1, 1)?->column);
        $this->assertSame(1, $map->lookup(1, 11)?->column);
    }

    public function test_an_entry_without_a_source_is_skipped(): void
    {
        // Ein Eintrag mit nur einer Zahl sagt „hier beginnt etwas, zu dem ich
        // keine Quelle habe". Ihn mit der vorherigen Quelle zu füllen wäre eine
        // erfundene Zuordnung — der Eintrag davor gilt weiter.
        $map = $this->map(['mappings' => 'AAAA,U']);

        $this->assertSame(1, $map->lookup(1, 11)?->line);
    }

    public function test_the_source_root_is_prepended_and_build_prefixes_fall_away(): void
    {
        $map = $this->map([
            'sourceRoot' => 'webpack:///./',
            'sources' => ['src/warenkorb.ts'],
        ]);

        // `webpack:///./src/…` ist eine Angabe über das Bauwerkzeug. Wer die Datei
        // in seinem Editor sucht, sucht ohne sie.
        $this->assertSame('src/warenkorb.ts', $map->lookup(1, 1)?->file);
    }

    public function test_the_embedded_source_text_becomes_a_context(): void
    {
        $context = $this->map()->context(0, 3, 2);

        $this->assertNotNull($context);
        $this->assertSame(['const a = 1;', 'const b = 2;'], $context['pre']);
        $this->assertSame('const c = 3;', $context['current']);
        $this->assertSame(['const d = 4;', 'const e = 5;'], $context['post']);
    }

    public function test_carriage_returns_do_not_end_up_in_the_context(): void
    {
        $map = $this->map(['sourcesContent' => ["eins\r\nzwei\r\ndrei\r\n"]]);

        $context = $map->context(0, 2, 1);

        $this->assertNotNull($context);
        $this->assertSame('zwei', $context['current']);
        $this->assertSame(['eins'], $context['pre']);
    }

    public function test_a_map_without_embedded_sources_has_no_context(): void
    {
        // Kein Defekt: die Karte sagt, wo der Fehler steckt, nur nicht, was dort
        // steht. Der Unterschied gehört in die Diagnose und nicht in einen leeren
        // Ausschnitt.
        $this->assertNull($this->map(['sourcesContent' => []])->context(0, 3, 2));
    }

    public function test_something_that_is_not_a_map_is_rejected(): void
    {
        $this->assertNull(SourceMap::fromJson('<html>404</html>'));
        $this->assertNull(SourceMap::fromJson('{"version":3,"sources":[]}'));
        $this->assertNull(SourceMap::fromJson('{"version":2,"mappings":"AAAA"}'));

        // Fehlt die Fassung, wird 3 angenommen: manche Werkzeuge lassen sie weg.
        $this->assertNotNull(SourceMap::fromJson('{"mappings":"AAAA","sources":["a.ts"]}'));
    }

    public function test_a_source_map_is_recognised_by_its_content(): void
    {
        $this->assertTrue(SourceMap::looksLikeSourceMap('{"version":3,"mappings":"AAAA"}'));

        // `app.js.map` ist eine Gewohnheit, keine Zusage — entschieden wird am
        // Inhalt.
        $this->assertFalse(SourceMap::looksLikeSourceMap('var a=1;//# sourceMappingURL=app.js.map'));
        $this->assertFalse(SourceMap::looksLikeSourceMap('<html>404</html>'));
    }

    public function test_the_reference_to_a_source_map_is_read_from_the_end(): void
    {
        $this->assertSame(
            'app.4f2c1e.js.map',
            SourceMap::referenceFrom("var a=1;\n//# sourceMappingURL=app.4f2c1e.js.map\n")
        );

        // Die ältere Schreibweise mit `@` steht in älteren Bundles und ist nicht
        // falsch.
        $this->assertSame('a.map', SourceMap::referenceFrom('var a=1;/*@ sourceMappingURL=a.map */'));

        // Der letzte Verweis gilt: mehrere kommen bei einem zusammengesetzten
        // Bundle vor, gemeint ist der des Ganzen.
        $this->assertSame(
            'ganz.map',
            SourceMap::referenceFrom("//# sourceMappingURL=teil.map\nvar a=1;\n//# sourceMappingURL=ganz.map")
        );

        // Eine eingebettete Karte ist kein Verweis auf eine hochzuladende Datei.
        $this->assertNull(SourceMap::referenceFrom('var a=1;//# sourceMappingURL=data:application/json;base64,e30='));
        $this->assertNull(SourceMap::referenceFrom('var a=1;'));
    }

    public function test_a_debug_id_is_read_from_map_and_bundle(): void
    {
        $id = '5a2b1c3d-4e5f-6071-8293-a4b5c6d7e8f9';

        $this->assertSame($id, $this->map(['debug_id' => $id])->debugId());
        $this->assertSame($id, $this->map(['debugId' => $id])->debugId());
        $this->assertSame($id, SourceMap::debugIdFrom('var a=1;//# debugId='.strtoupper($id)));
        $this->assertNull(SourceMap::debugIdFrom('var a=1;'));
    }
}
