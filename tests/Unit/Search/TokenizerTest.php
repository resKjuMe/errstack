<?php

namespace Tests\Unit\Search;

use App\Support\Search\SearchSyntaxException;
use App\Support\Search\Tokenizer;
use App\Support\Search\TokenType;
use Tests\TestCase;

/**
 * Das Zerlegen für sich — ohne Datenbank und ohne Bedeutung der Felder.
 *
 * Hier entscheidet sich, was später überhaupt noch unterscheidbar ist: ob ein
 * Wert in Anführungszeichen stand, wo ein Begriff anfing, und welcher der drei
 * Doppelpunkte in `url:https://shop.example/x` der trennende war. Ein Fehler an
 * dieser Stelle sieht weiter oben aus wie ein Fehler in der Suche.
 */
class TokenizerTest extends TestCase
{
    /**
     * @return list<array{string, string|null, string, bool}>
     */
    private function terms(string $input): array
    {
        return array_map(
            fn ($token): array => [$token->type->name, $token->key, $token->value, $token->quoted],
            Tokenizer::tokenize($input),
        );
    }

    public function test_a_term_splits_at_the_first_colon(): void
    {
        $this->assertSame(
            [['Term', 'url', 'https://shop.example/checkout', false]],
            $this->terms('url:https://shop.example/checkout'),
        );
    }

    public function test_a_word_without_a_colon_is_free_text(): void
    {
        $this->assertSame([['Term', null, 'datenbank', false]], $this->terms('datenbank'));
    }

    public function test_quotes_hold_a_value_together(): void
    {
        $this->assertSame(
            [['Term', 'browser', 'Chrome 124', true]],
            $this->terms('browser:"Chrome 124"'),
        );
    }

    /**
     * Nur ein Wert, der **ganz** aus einem Zitat kommt, gilt als wörtlich
     * gemeint. Sonst wäre das Anführungszeichen um ein Leerzeichen herum eine
     * ungewollte Ansage, Platzhalter und Vergleiche abzuschalten.
     */
    public function test_a_partly_quoted_value_is_not_a_quoted_one(): void
    {
        $this->assertSame(
            [['Term', 'browser', 'Chrome 124', false]],
            $this->terms('browser:Chrome" "124'),
        );
    }

    public function test_a_quote_may_contain_an_escaped_quote(): void
    {
        $this->assertSame(
            [['Term', 'title', 'sagt "nein"', true]],
            $this->terms('title:"sagt \"nein\""'),
        );
    }

    public function test_brackets_and_negation_stand_for_themselves(): void
    {
        $types = array_map(
            fn ($token): TokenType => $token->type,
            Tokenizer::tokenize('!(a or b)'),
        );

        $this->assertSame(
            [TokenType::Not, TokenType::OpenParen, TokenType::Term, TokenType::Or, TokenType::Term, TokenType::CloseParen],
            $types,
        );
    }

    public function test_and_and_or_are_words_and_no_regard_for_case(): void
    {
        $types = array_map(fn ($token): TokenType => $token->type, Tokenizer::tokenize('a AND b Or c'));

        $this->assertSame(
            [TokenType::Term, TokenType::And, TokenType::Term, TokenType::Or, TokenType::Term],
            $types,
        );
    }

    /**
     * In Anführungszeichen ist „or" ein Suchbegriff — sonst könnte man nach
     * einer Fehlermeldung, in der das Wort vorkommt, nicht suchen.
     */
    public function test_a_quoted_or_is_a_term(): void
    {
        $this->assertSame([['Term', null, 'or', true]], $this->terms('"or"'));
    }

    public function test_a_key_after_a_colon_may_be_empty(): void
    {
        $this->assertSame([['Term', 'is', '', false]], $this->terms('is:'));
    }

    public function test_the_position_counts_characters_and_not_bytes(): void
    {
        $tokens = Tokenizer::tokenize('grüße is:unresolved');

        $this->assertSame(6, $tokens[1]->position);
        $this->assertSame(9, $tokens[1]->valuePosition);
    }

    public function test_a_leading_colon_has_no_field(): void
    {
        $this->expectException(SearchSyntaxException::class);

        Tokenizer::tokenize(':unresolved');
    }

    public function test_an_unclosed_quote_says_where_it_opened(): void
    {
        try {
            Tokenizer::tokenize('is:unresolved browser:"Chrome');

            $this->fail('Ein offenes Anführungszeichen muss auffallen.');
        } catch (SearchSyntaxException $error) {
            $this->assertSame(22, $error->position);
        }
    }
}
