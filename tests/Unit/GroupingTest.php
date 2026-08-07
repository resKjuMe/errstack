<?php

namespace Tests\Unit;

use App\Enums\GroupingSource;
use App\Models\FingerprintRule;
use App\Support\Ingest\Grouping\DefaultFingerprint;
use App\Support\Ingest\Grouping\Grouper;
use App\Support\Ingest\Grouping\Variables;
use App\Support\Ingest\Normalization\EventNormalizer;
use App\Support\Ingest\Normalization\NormalizedEvent;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Gruppierung für sich, ohne Warteschlange und ohne Datenbank.
 *
 * Geprüft wird beides, was die Aufgabe verlangt — und die zweite Richtung ist
 * die wichtigere: dass gleichartige Meldungen zusammenfinden, **und** dass
 * verschiedene Ursachen getrennt bleiben. Eine Gruppierung, die alles
 * zusammenwirft, besteht die erste Hälfte mühelos.
 */
class GroupingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<mixed>  $data
     */
    private function normalize(array $data): NormalizedEvent
    {
        return EventNormalizer::make()->normalize($data, str_repeat('a', 32));
    }

    private function grouper(): Grouper
    {
        return new Grouper(new DefaultFingerprint(maxFrames: 30));
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<FingerprintRule>  $rules
     */
    private function hash(array $data, array $rules = []): string
    {
        return $this->grouper()->fingerprint($this->normalize($data), $rules)->hash;
    }

    /**
     * Eine Ausnahme mit Stacktrace, deren Text eine wechselnde Kennung trägt.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<mixed>
     */
    private function exception(string $value, array $overrides = []): array
    {
        return array_replace_recursive([
            'exception' => ['values' => [[
                'type' => 'RuntimeException',
                'value' => $value,
                'stacktrace' => ['frames' => [
                    [
                        'filename' => 'app/Http/Controllers/InvoiceController.php',
                        'function' => 'show',
                        'lineno' => 42,
                        'in_app' => true,
                    ],
                ]],
            ]]],
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Der Kern der Aufgabe
    // ------------------------------------------------------------------

    /**
     * Der eigentliche Punkt: zwanzig Meldungen, in denen nur die Nutzer-Nummer
     * wechselt, sind ein Fehler und nicht zwanzig.
     */
    public function test_the_same_error_with_changing_identifiers_lands_in_one_group(): void
    {
        $hashes = [];

        foreach (range(4711, 4730) as $userId) {
            $hashes[] = $this->hash($this->exception("Nutzer {$userId} nicht gefunden"));
        }

        $this->assertCount(1, array_unique($hashes));
    }

    /**
     * Die Gegenrichtung, und die ist die wichtigere: verschiedene Ursachen
     * bleiben getrennt. Eine Gruppierung, die alles zusammenwirft, besteht die
     * Prüfung darüber mühelos.
     */
    public function test_different_errors_land_in_different_groups(): void
    {
        $sameFile = $this->hash($this->exception('Nutzer 4711 nicht gefunden'));

        $otherFunction = $this->hash($this->exception('Nutzer 4711 nicht gefunden', [
            'exception' => ['values' => [['stacktrace' => ['frames' => [['function' => 'store']]]]]],
        ]));

        $otherType = $this->hash($this->exception('Nutzer 4711 nicht gefunden', [
            'exception' => ['values' => [['type' => 'LogicException']]],
        ]));

        $this->assertNotSame($sameFile, $otherFunction);
        $this->assertNotSame($sameFile, $otherType);
        $this->assertNotSame($otherFunction, $otherType);
    }

    /**
     * Die Zeilennummer geht **nicht** ein.
     *
     * Sonst bekäme derselbe Fehler nach jeder Änderung an der Datei eine neue
     * Gruppe — und seine Zählung begänne bei jedem Deployment von vorn.
     */
    public function test_a_shifted_line_number_does_not_split_the_group(): void
    {
        $before = $this->hash($this->exception('kaputt'));

        $after = $this->hash($this->exception('kaputt', [
            'exception' => ['values' => [['stacktrace' => ['frames' => [['lineno' => 1337]]]]]],
        ]));

        $this->assertSame($before, $after);
    }

    /**
     * Derselbe Fehler von zwei Rechnern mit verschiedenen Bauverzeichnissen.
     */
    public function test_a_different_build_path_does_not_split_the_group(): void
    {
        $one = $this->hash($this->exception('kaputt', [
            'exception' => ['values' => [['stacktrace' => ['frames' => [
                ['filename' => '/home/anna/projekt/app/Jobs/Import.php'],
            ]]]]],
        ]));

        $two = $this->hash($this->exception('kaputt', [
            'exception' => ['values' => [['stacktrace' => ['frames' => [
                ['filename' => 'C:\\build\\42\\app\\Jobs\\Import.php'],
            ]]]]],
        ]));

        $this->assertSame($one, $two);
    }

    /**
     * Nachrichten ohne Ausnahme werden nach der **Vorlage** gruppiert, nicht
     * nach dem ausgefüllten Text.
     */
    public function test_messages_group_by_their_template(): void
    {
        $first = $this->hash([
            'logentry' => ['message' => 'Nutzer %s nicht gefunden', 'params' => ['4711'], 'formatted' => 'Nutzer 4711 nicht gefunden'],
        ]);

        $second = $this->hash([
            'logentry' => ['message' => 'Nutzer %s nicht gefunden', 'params' => ['4712'], 'formatted' => 'Nutzer 4712 nicht gefunden'],
        ]);

        $this->assertSame($first, $second);
    }

    /**
     * Ohne Vorlage bleibt der ausgefüllte Text — dann greift die Ersetzung der
     * wechselnden Anteile.
     */
    public function test_messages_without_a_template_still_group(): void
    {
        $first = $this->hash(['message' => 'Auftrag 100045 abgebrochen']);
        $second = $this->hash(['message' => 'Auftrag 100046 abgebrochen']);

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $this->hash(['message' => 'Auftrag 100045 verschoben']));
    }

    /**
     * Eine Meldung ohne jede Angabe bekommt trotzdem eine Gruppe — und zwar
     * dieselbe wie ihresgleichen. Je Ereignis eine eigene Gruppe wäre genau die
     * Flut, die hier verhindert werden soll.
     */
    public function test_reports_without_anything_to_group_by_share_one_group(): void
    {
        $fingerprint = $this->grouper()->fingerprint($this->normalize([]));

        $this->assertSame(GroupingSource::Empty, $fingerprint->source);
        $this->assertSame($fingerprint->hash, $this->hash([]));
    }

    // ------------------------------------------------------------------
    // Eigene Angabe des SDK
    // ------------------------------------------------------------------

    public function test_a_custom_fingerprint_from_the_sdk_wins_over_the_default(): void
    {
        $first = $this->grouper()->fingerprint($this->normalize(
            $this->exception('kaputt') + ['fingerprint' => ['abrechnung']],
        ));

        $second = $this->grouper()->fingerprint($this->normalize(
            $this->exception('etwas ganz anderes', [
                'exception' => ['values' => [['type' => 'LogicException']]],
            ]) + ['fingerprint' => ['abrechnung']],
        ));

        $this->assertSame(GroupingSource::Custom, $first->source);
        $this->assertSame($first->hash, $second->hash);
    }

    /**
     * `{{ default }}` verfeinert, statt zu ersetzen: gleiche Gruppierung wie
     * sonst, aber je Marke getrennt.
     */
    public function test_the_default_placeholder_refines_instead_of_replacing(): void
    {
        $base = $this->exception('kaputt') + ['fingerprint' => ['{{ default }}', '{{ tags.mandant }}']];

        $one = $this->hash(array_replace($base, ['tags' => ['mandant' => 'a']]));
        $two = $this->hash(array_replace($base, ['tags' => ['mandant' => 'b']]));
        $again = $this->hash(array_replace($base, ['tags' => ['mandant' => 'a']]));

        $this->assertNotSame($one, $two);
        $this->assertSame($one, $again);

        // Und ohne die Marke ist es wieder eine andere Gruppe — nicht dieselbe
        // wie „Mandant a", denn dann hinge die Zuordnung daran, ob eine
        // einzelne Meldung ihre Marke gesetzt hat.
        $this->assertNotSame($one, $this->hash($base));
    }

    /**
     * Ein Fingerabdruck, der **nur** aus `{{ default }}` besteht, ist keine
     * Angabe — manche SDKs schicken ihn als Vorgabewert mit.
     */
    public function test_a_fingerprint_of_only_the_default_placeholder_is_no_choice(): void
    {
        $fingerprint = $this->grouper()->fingerprint($this->normalize(
            $this->exception('kaputt') + ['fingerprint' => ['{{ default }}']],
        ));

        $this->assertSame(GroupingSource::Stacktrace, $fingerprint->source);
        $this->assertSame($this->hash($this->exception('kaputt')), $fingerprint->hash);
    }

    /**
     * Eine einzelne Zeichenkette statt der Liste: mehrere SDKs schreiben das so.
     */
    public function test_a_single_string_fingerprint_is_accepted(): void
    {
        $list = $this->hash($this->exception('kaputt') + ['fingerprint' => ['abrechnung']]);
        $string = $this->hash($this->exception('kaputt') + ['fingerprint' => 'abrechnung']);

        $this->assertSame($list, $string);
    }

    // ------------------------------------------------------------------
    // Projektweite Regeln
    // ------------------------------------------------------------------

    /**
     * @param  list<array{attribute: string, pattern: string, negated?: bool}>  $matchers
     * @param  list<string>  $fingerprint
     */
    private function rule(array $matchers, array $fingerprint): FingerprintRule
    {
        $rule = new FingerprintRule([
            'name' => 'Test',
            'matchers' => $matchers,
            'fingerprint' => $fingerprint,
        ]);

        // Ohne Datenbank: die Kennung wird sonst erst beim Speichern vergeben,
        // und die Begründung am Ereignis soll sie tragen.
        $rule->id = 7;

        return $rule;
    }

    public function test_a_project_rule_replaces_the_default(): void
    {
        $rule = $this->rule(
            [['attribute' => 'error.type', 'pattern' => '*Exception']],
            ['zeitueberschreitung'],
        );

        $fingerprint = $this->grouper()->fingerprint($this->normalize($this->exception('kaputt')), [$rule]);

        $this->assertSame(GroupingSource::Rule, $fingerprint->source);
        $this->assertSame(7, $fingerprint->ruleId);
        $this->assertSame(['zeitueberschreitung'], $fingerprint->values);
    }

    /**
     * Eine Regel gewinnt auch über die Angabe des SDK — sonst wäre sie genau
     * dort wirkungslos, wo sie am häufigsten gebraucht wird.
     */
    public function test_a_project_rule_wins_over_the_sdk(): void
    {
        $rule = $this->rule(
            [['attribute' => 'error.type', 'pattern' => 'RuntimeException']],
            ['aus-der-regel'],
        );

        $fingerprint = $this->grouper()->fingerprint(
            $this->normalize($this->exception('kaputt') + ['fingerprint' => ['aus-dem-sdk']]),
            [$rule],
        );

        $this->assertSame(GroupingSource::Rule, $fingerprint->source);
        $this->assertSame(['aus-der-regel'], $fingerprint->values);
    }

    public function test_the_first_matching_rule_wins(): void
    {
        $first = $this->rule([['attribute' => 'error.type', 'pattern' => '*']], ['erste']);
        $second = $this->rule([['attribute' => 'error.type', 'pattern' => 'RuntimeException']], ['zweite']);

        $fingerprint = $this->grouper()->fingerprint(
            $this->normalize($this->exception('kaputt')),
            [$first, $second],
        );

        $this->assertSame(['erste'], $fingerprint->values);
    }

    /**
     * Alle Bedingungen müssen zutreffen — wer ODER braucht, schreibt zwei
     * Regeln.
     */
    public function test_all_conditions_of_a_rule_must_match(): void
    {
        $rule = $this->rule([
            ['attribute' => 'error.type', 'pattern' => 'RuntimeException'],
            ['attribute' => 'stack.path', 'pattern' => '*abrechnung/*'],
        ], ['abrechnung']);

        $fingerprint = $this->grouper()->fingerprint($this->normalize($this->exception('kaputt')), [$rule]);

        $this->assertSame(GroupingSource::Stacktrace, $fingerprint->source);
    }

    /**
     * Bei mehrwertigen Feldern genügt ein Treffer — und die Umkehrung dreht
     * damit auch die Menge um: `!stack.path:*vendor/*` greift nur, wenn
     * **kein** Rahmen im Vendor-Verzeichnis liegt.
     */
    public function test_a_negated_condition_covers_every_frame(): void
    {
        $rule = $this->rule(
            [['attribute' => 'stack.path', 'pattern' => '*vendor/*', 'negated' => true]],
            ['nur-eigener-code'],
        );

        $own = $this->grouper()->fingerprint($this->normalize($this->exception('kaputt')), [$rule]);

        $withVendor = $this->grouper()->fingerprint($this->normalize($this->exception('kaputt', [
            'exception' => ['values' => [['stacktrace' => ['frames' => [
                ['filename' => 'app/Http/Controllers/InvoiceController.php', 'function' => 'show', 'in_app' => true],
                ['filename' => 'vendor/laravel/framework/src/Router.php', 'function' => 'dispatch'],
            ]]]]],
        ])), [$rule]);

        $this->assertSame(GroupingSource::Rule, $own->source);
        $this->assertSame(GroupingSource::Stacktrace, $withVendor->source);
    }

    /**
     * Das Muster ist ein Platzhalter-Ausdruck und **kein** regulärer Ausdruck.
     * Wer `.` schreibt, meint einen Punkt.
     */
    public function test_a_pattern_is_not_a_regular_expression(): void
    {
        $rule = $this->rule([['attribute' => 'error.type', 'pattern' => 'Runtime.xception']], ['getroffen']);

        $fingerprint = $this->grouper()->fingerprint($this->normalize($this->exception('kaputt')), [$rule]);

        $this->assertSame(GroupingSource::Stacktrace, $fingerprint->source);
    }

    /**
     * Eine Regel ohne brauchbare Bedingung wird übergangen und bringt nicht die
     * Auswertung jeder Meldung dieses Projekts zum Scheitern.
     */
    public function test_an_unusable_rule_is_skipped(): void
    {
        $broken = new FingerprintRule(['name' => 'kaputt', 'matchers' => [], 'fingerprint' => ['x']]);
        $broken->id = 8;

        $fingerprint = $this->grouper()->fingerprint($this->normalize($this->exception('kaputt')), [$broken]);

        $this->assertSame(GroupingSource::Stacktrace, $fingerprint->source);
    }

    // ------------------------------------------------------------------
    // Wechselnde Anteile
    // ------------------------------------------------------------------

    /**
     * Was ersetzt wird — und was ausdrücklich nicht.
     */
    public function test_variable_parts_are_replaced_but_short_numbers_are_kept(): void
    {
        $this->assertSame('Nutzer <n> nicht gefunden', Variables::normalize('Nutzer 4711 nicht gefunden'));
        $this->assertSame('Zeiger <addr> ungültig', Variables::normalize('Zeiger 0x7ffee4 ungültig'));
        $this->assertSame('Auftrag <uuid>', Variables::normalize('Auftrag 3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        $this->assertSame('Stand <datetime>', Variables::normalize('Stand 2026-08-07T12:00:00Z'));
        $this->assertSame('Von <ip> abgelehnt', Variables::normalize('Von 10.1.2.3 abgelehnt'));

        // Kurze Zahlen sind Angaben zum Fehler, keine Kennungen: sie sollen ihn
        // unterscheiden dürfen.
        $this->assertSame('Zeitüberschreitung nach 30 s', Variables::normalize('Zeitüberschreitung nach 30 s'));
        $this->assertSame('HTTP 404', Variables::normalize('HTTP 404'));
    }

    /**
     * Ein Text, der nur noch aus Platzhaltern besteht, trägt keine Aussage mehr
     * — er würde jede Meldung derselben Form zusammenziehen.
     */
    public function test_a_text_of_nothing_but_placeholders_is_no_component(): void
    {
        $this->assertNull(Variables::normalize('3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        $this->assertNull(Variables::normalize('   '));
    }

    public function test_paths_lose_the_build_directory_and_cache_busting_hashes(): void
    {
        $this->assertSame('app/Jobs/Import.php', Variables::path('/home/anna/projekt/app/Jobs/Import.php'));
        $this->assertSame('src/main.js', Variables::path('https://cdn.example.test/src/main.js?v=1699123456'));
        $this->assertSame('dist/app.<hash>.js', Variables::path('/var/www/dist/app.4f3a2b1c.js'));
        $this->assertSame('vendor/paket/<version>/src/Client.php', Variables::path('vendor/paket/2.7.1/src/Client.php'));
    }
}
