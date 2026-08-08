<?php

namespace Tests\Unit\Search;

use App\Support\Search\Ast\AllOf;
use App\Support\Search\Ast\AnyOf;
use App\Support\Search\Ast\Condition;
use App\Support\Search\Ast\Expression;
use App\Support\Search\Ast\FreeText;
use App\Support\Search\Ast\Negation;
use App\Support\Search\Comparator;
use App\Support\Search\Parser;
use App\Support\Search\SearchSyntaxException;
use Tests\TestCase;

/**
 * Der Ausdrucksbaum: Rangfolge, Klammern und die Meldungen, wenn nichts davon
 * aufgeht.
 *
 * Die Rangfolge ist der Teil, den niemand nachschlägt und jeder unterstellt —
 * `a b or c` heißt „(a und b) oder c". Stimmte das nicht, lieferte dieselbe
 * Eingabe stillschweigend eine andere Liste, und zwar eine, die plausibel
 * aussieht.
 */
class ParserTest extends TestCase
{
    /**
     * Der Baum als Text, damit sich Rangfolge in einer Zeile prüfen lässt.
     */
    private function shape(?Expression $node): string
    {
        return match (true) {
            $node instanceof AllOf => 'und('.implode(', ', array_map($this->shape(...), $node->children)).')',
            $node instanceof AnyOf => 'oder('.implode(', ', array_map($this->shape(...), $node->children)).')',
            $node instanceof Negation => 'nicht('.$this->shape($node->inner).')',
            $node instanceof Condition => $node->field.$node->comparator->value.':'.$node->value,
            $node instanceof FreeText => '"'.$node->text.'"',
            default => 'nichts',
        };
    }

    public function test_terms_side_by_side_are_an_and(): void
    {
        $this->assertSame(
            'und(is:unresolved, browser:Chrome)',
            $this->shape(Parser::parse('is:unresolved browser:Chrome')),
        );
    }

    public function test_and_binds_tighter_than_or(): void
    {
        $this->assertSame(
            'oder(und(a:1, b:2), c:3)',
            $this->shape(Parser::parse('a:1 b:2 or c:3')),
        );
    }

    public function test_brackets_beat_the_ranking(): void
    {
        $this->assertSame(
            'und(a:1, oder(b:2, c:3))',
            $this->shape(Parser::parse('a:1 (b:2 or c:3)')),
        );
    }

    public function test_negation_binds_tightest(): void
    {
        $this->assertSame(
            'und(nicht(level:info), browser:Chrome)',
            $this->shape(Parser::parse('!level:info browser:Chrome')),
        );
    }

    public function test_a_whole_bracket_can_be_negated(): void
    {
        $this->assertSame(
            'nicht(oder(browser:Chrome, browser:Edge))',
            $this->shape(Parser::parse('!(browser:Chrome or browser:Edge)')),
        );
    }

    public function test_a_word_without_a_field_is_free_text(): void
    {
        $this->assertSame('und("timeout", level:error)', $this->shape(Parser::parse('timeout level:error')));
    }

    public function test_a_comparison_is_read_off_the_value(): void
    {
        $condition = Parser::parse('timesSeen:>=100');

        $this->assertInstanceOf(Condition::class, $condition);
        $this->assertSame(Comparator::GreaterOrEqual, $condition->comparator);
        $this->assertSame('100', $condition->value);
    }

    /**
     * In Anführungszeichen ist ein `>` ein Zeichen und keine Rechnung — die
     * Notbremse für jeden Wert, der zufällig wie Syntax aussieht.
     */
    public function test_a_quoted_value_keeps_its_comparison_sign(): void
    {
        $condition = Parser::parse('browser:">100"');

        $this->assertInstanceOf(Condition::class, $condition);
        $this->assertSame(Comparator::Equals, $condition->comparator);
        $this->assertSame('>100', $condition->value);
    }

    public function test_nothing_in_means_nothing_out(): void
    {
        $this->assertNull(Parser::parse('   '));
    }

    /**
     * Der Fall aus der Aufgabenbeschreibung: bewusst Unsinn eingeben.
     */
    public function test_a_field_without_a_value_says_so_and_where(): void
    {
        try {
            Parser::parse('is:');

            $this->fail('`is:` ist kein vollständiger Begriff.');
        } catch (SearchSyntaxException $error) {
            $this->assertSame(3, $error->position);
            $this->assertStringContainsString('is', $error->getMessage());
        }
    }

    public function test_a_comparison_without_a_value_says_so(): void
    {
        $this->expectException(SearchSyntaxException::class);

        Parser::parse('timesSeen:>');
    }

    public function test_an_unclosed_bracket_points_at_the_opening_one(): void
    {
        try {
            Parser::parse('a:1 (b:2 or c:3');

            $this->fail('Die Klammer ist offen.');
        } catch (SearchSyntaxException $error) {
            $this->assertSame(4, $error->position);
        }
    }

    public function test_a_bracket_that_was_never_opened_is_reported(): void
    {
        $this->expectException(SearchSyntaxException::class);

        Parser::parse('a:1) b:2');
    }

    public function test_empty_brackets_are_reported(): void
    {
        $this->expectException(SearchSyntaxException::class);

        Parser::parse('a:1 ()');
    }

    public function test_a_trailing_or_is_reported(): void
    {
        $this->expectException(SearchSyntaxException::class);

        Parser::parse('a:1 or');
    }

    public function test_too_many_nested_brackets_are_reported(): void
    {
        $this->expectException(SearchSyntaxException::class);

        Parser::parse(str_repeat('(', 25).'a:1'.str_repeat(')', 25));
    }
}
