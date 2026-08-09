<?php

namespace Tests\Unit;

use App\Enums\OwnershipMatcher;
use App\Models\OwnershipRule;
use App\Support\Ownership\Ownership;
use App\Support\Ownership\OwnershipSubjects;
use Tests\TestCase;

/**
 * Die Auswertung der Zuständigkeits-Regeln — ohne Datenbank.
 *
 * Das ist möglich, weil {@see Ownership::matching()} die Regeln übergeben
 * bekommt und nicht nachschlägt, und es ist der Grund für diesen Schnitt: die
 * beiden Aussagen, an denen alles hängt — „die letzte zutreffende gewinnt" und
 * „ein repository-relativer Pfad trifft auch den ausgelieferten" —, lassen sich
 * so an einer Handvoll Zeilen prüfen statt an einem aufgebauten Projekt.
 */
class OwnershipRulesTest extends TestCase
{
    /**
     * @param  list<string>  $owners
     */
    private function rule(
        OwnershipMatcher $matcher,
        string $pattern,
        array $owners,
        ?string $tagKey = null,
        bool $active = true,
    ): OwnershipRule {
        return new OwnershipRule([
            'matcher' => $matcher,
            'pattern' => $pattern,
            'owners' => $owners,
            'tag_key' => $tagKey,
            'is_active' => $active,
        ]);
    }

    public function test_a_path_rule_matches_a_repository_relative_path(): void
    {
        $rules = [$this->rule(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'])];

        $winner = Ownership::winner(OwnershipSubjects::of(path: 'src/billing/Invoice.php'), $rules);

        $this->assertSame(['#Kasse'], $winner?->owners);
    }

    /**
     * Der Fall, an dem ein wörtlicher Vergleich scheitern würde: im Stacktrace
     * steht der Pfad auf dem Server, in der Regel der aus dem Repository.
     */
    public function test_a_path_rule_matches_a_deployed_absolute_path(): void
    {
        $rules = [$this->rule(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'])];

        $winner = Ownership::winner(
            OwnershipSubjects::of(path: '/var/www/releases/17/src/billing/Invoice.php'),
            $rules,
        );

        $this->assertNotNull($winner);
    }

    public function test_a_windows_path_matches_the_same_rule(): void
    {
        $rules = [$this->rule(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'])];

        $winner = Ownership::winner(
            OwnershipSubjects::of(path: 'C:\\projekte\\shop\\src\\billing\\Invoice.php'),
            $rules,
        );

        $this->assertNotNull($winner);
    }

    /**
     * Ein Muster mit führendem Platzhalter oder Schrägstrich gilt unverändert —
     * das ist der Weg, die Bequemlichkeit oben abzuwählen.
     */
    public function test_an_anchored_pattern_is_not_loosened(): void
    {
        $rules = [$this->rule(OwnershipMatcher::Path, '/src/billing/*', ['#Kasse'])];

        $this->assertNull(Ownership::winner(
            OwnershipSubjects::of(path: '/var/www/src/billing/Invoice.php'),
            $rules,
        ));
    }

    public function test_the_last_matching_rule_wins(): void
    {
        $rules = [
            $this->rule(OwnershipMatcher::Path, 'src/*', ['#Plattform']),
            $this->rule(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse']),
        ];

        $subjects = OwnershipSubjects::of(path: 'src/billing/Invoice.php');

        $this->assertSame(['#Kasse'], Ownership::winner($subjects, $rules)?->owners);
        // Beide Treffer bleiben erhalten: die Vorschau zeigt, was überstimmt
        // wurde.
        $this->assertCount(2, Ownership::matching($subjects, $rules));
    }

    public function test_a_disabled_rule_does_not_count(): void
    {
        $rules = [
            $this->rule(OwnershipMatcher::Path, 'src/*', ['#Plattform']),
            $this->rule(OwnershipMatcher::Path, 'src/billing/*', ['#Kasse'], active: false),
        ];

        $winner = Ownership::winner(OwnershipSubjects::of(path: 'src/billing/Invoice.php'), $rules);

        $this->assertSame(['#Plattform'], $winner?->owners);
    }

    public function test_a_url_rule_matches_the_request_url(): void
    {
        $rules = [$this->rule(OwnershipMatcher::Url, '*/checkout/*', ['#Kasse'])];

        $this->assertNotNull(Ownership::winner(
            OwnershipSubjects::of(url: 'https://example.com/checkout/summe'),
            $rules,
        ));

        // Die Adresse ist **nicht** der Pfad: eine Regel auf die eine darf nicht
        // durch die andere ausgelöst werden.
        $this->assertNull(Ownership::winner(
            OwnershipSubjects::of(path: 'app/checkout/Total.php'),
            $rules,
        ));
    }

    public function test_a_module_rule_matches_the_frame_module(): void
    {
        $rules = [$this->rule(OwnershipMatcher::Module, 'com.acme.billing.*', ['#Kasse'])];

        $this->assertNotNull(Ownership::winner(
            OwnershipSubjects::of(module: 'com.acme.billing.Invoice'),
            $rules,
        ));
    }

    /**
     * Eine Merkmalsregel vergleicht **nur** den benannten Schlüssel. Ohne ihn
     * hätte sie keinen Wert, gegen den sie prüfen könnte — und „irgendein
     * Merkmal ist web-01" wäre keine Zuständigkeit, sondern ein Zufall.
     */
    public function test_a_tag_rule_only_looks_at_its_own_key(): void
    {
        $rules = [$this->rule(OwnershipMatcher::Tag, 'web-*', ['#Betrieb'], tagKey: 'server_name')];

        $this->assertNotNull(Ownership::winner(
            OwnershipSubjects::of(tags: ['server_name' => 'web-01']),
            $rules,
        ));

        $this->assertNull(Ownership::winner(
            OwnershipSubjects::of(tags: ['hostname' => 'web-01']),
            $rules,
        ));
    }

    public function test_an_event_without_anything_to_match_is_recognized_as_empty(): void
    {
        $rules = [$this->rule(OwnershipMatcher::Path, '*', ['#Plattform'])];

        $this->assertTrue(OwnershipSubjects::of()->isEmpty());
        $this->assertSame([], Ownership::matching(OwnershipSubjects::of(), $rules));
    }
}
