<?php

namespace App\Support\Search;

use App\Support\Search\Ast\AllOf;
use App\Support\Search\Ast\AnyOf;
use App\Support\Search\Ast\Condition;
use App\Support\Search\Ast\Expression;
use App\Support\Search\Ast\FreeText;
use App\Support\Search\Ast\Negation;

/**
 * Setzt die Bausteine zu einem Ausdrucksbaum zusammen.
 *
 * Ein Verfahren des rekursiven Abstiegs, drei Ebenen, in dieser Rangfolge:
 * **Verneinung vor Und vor Oder**. `!a b or c` heißt also „(nicht a und b) oder
 * c" — dieselbe Rangfolge wie in jeder Programmiersprache und die, die man beim
 * Tippen unterstellt. Wer es anders meint, klammert.
 *
 * **Nebeneinander stehende Begriffe sind ein Und**, ohne dass jemand `and`
 * schreibt. Das ist die Schreibweise, die eine Suchleiste erwartet; das
 * ausdrückliche `and` gibt es trotzdem, weil ein Ausdruck mit `or` und Klammern
 * ohne sein Gegenstück schief aussieht.
 */
final class Parser
{
    /**
     * Wie tief Klammern geschachtelt sein dürfen.
     *
     * Nicht gegen Angreifer — die Eingabe ist ohnehin auf wenige hundert Zeichen
     * begrenzt —, sondern damit ein versehentlich eingefügter Klammerwald eine
     * Meldung ergibt und keinen Abbruch tief im Aufrufstapel.
     */
    private const MAX_DEPTH = 20;

    /** @var list<Token> */
    private array $tokens;

    private int $at = 0;

    /**
     * @param  list<Token>  $tokens
     */
    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * Zerlegt eine Eingabe — `null`, wenn nichts darin steht.
     *
     * @throws SearchSyntaxException
     */
    public static function parse(string $input): ?Expression
    {
        $parser = new self(Tokenizer::tokenize($input));

        if ($parser->tokens === []) {
            return null;
        }

        $expression = $parser->any(0);

        $rest = $parser->peek();

        if ($rest !== null) {
            throw new SearchSyntaxException(
                $rest->type === TokenType::CloseParen
                    ? __('search.errors.unopened_paren')
                    : __('search.errors.unexpected', ['term' => $rest->raw]),
                $rest->position,
                $rest->raw,
            );
        }

        return $expression;
    }

    /**
     * Die Oder-Ebene.
     *
     * @throws SearchSyntaxException
     *
     * @phpstan-impure schiebt den Lesezeiger weiter
     */
    private function any(int $depth): Expression
    {
        $children = [$this->all($depth)];

        while ($this->peek()?->type === TokenType::Or) {
            $this->next();
            $children[] = $this->all($depth);
        }

        return count($children) === 1 ? $children[0] : new AnyOf($children);
    }

    /**
     * Die Und-Ebene — mit und ohne ausgeschriebenes `and`.
     *
     * @throws SearchSyntaxException
     *
     * @phpstan-impure schiebt den Lesezeiger weiter
     */
    private function all(int $depth): Expression
    {
        $children = [$this->unary($depth)];

        while (true) {
            $token = $this->peek();

            if ($token === null) {
                break;
            }

            if ($token->type === TokenType::And) {
                $this->next();
                $children[] = $this->unary($depth);

                continue;
            }

            // Zwei Begriffe nebeneinander: das stillschweigende Und.
            if (in_array($token->type, [TokenType::Term, TokenType::Not, TokenType::OpenParen], true)) {
                $children[] = $this->unary($depth);

                continue;
            }

            break;
        }

        return count($children) === 1 ? $children[0] : new AllOf($children);
    }

    /**
     * Verneinung, Klammer oder einzelner Begriff.
     *
     * @throws SearchSyntaxException
     *
     * @phpstan-impure schiebt den Lesezeiger weiter
     */
    private function unary(int $depth): Expression
    {
        $token = $this->peek();

        if ($token === null) {
            $last = $this->tokens[count($this->tokens) - 1];

            throw new SearchSyntaxException(
                __('search.errors.unexpected_end', ['term' => $last->raw]),
                $last->position,
                $last->raw,
            );
        }

        if ($token->type === TokenType::Not) {
            $this->next();

            return new Negation($this->unary($depth));
        }

        if ($token->type === TokenType::OpenParen) {
            if ($depth + 1 > self::MAX_DEPTH) {
                throw new SearchSyntaxException(__('search.errors.too_deep'), $token->position, '(');
            }

            $this->next();

            if ($this->peek()?->type === TokenType::CloseParen) {
                throw new SearchSyntaxException(__('search.errors.empty_paren'), $token->position, '()');
            }

            $inner = $this->any($depth + 1);

            if ($this->peek()?->type !== TokenType::CloseParen) {
                throw new SearchSyntaxException(__('search.errors.unclosed_paren'), $token->position, '(');
            }

            $this->next();

            return $inner;
        }

        if ($token->type !== TokenType::Term) {
            throw new SearchSyntaxException(
                __('search.errors.unexpected', ['term' => $token->raw]),
                $token->position,
                $token->raw,
            );
        }

        $this->next();

        return self::term($token);
    }

    /**
     * Aus einem Baustein wird eine Aussage.
     *
     * @throws SearchSyntaxException
     */
    private static function term(Token $token): Expression
    {
        if (! $token->hasKey()) {
            if ($token->value === '') {
                throw new SearchSyntaxException(__('search.errors.empty_term'), $token->position, $token->raw);
            }

            return new FreeText($token->value, $token->quoted, $token->position);
        }

        if ($token->value === '') {
            throw new SearchSyntaxException(
                __('search.errors.missing_value', ['field' => (string) $token->key]),
                $token->valuePosition,
                $token->raw,
            );
        }

        [$comparator, $value] = self::comparator($token);

        if ($value === '') {
            throw new SearchSyntaxException(
                __('search.errors.missing_comparison_value', [
                    'field' => (string) $token->key,
                    'comparator' => $comparator->value,
                ]),
                $token->valuePosition,
                $token->raw,
            );
        }

        return new Condition(
            (string) $token->key,
            $comparator,
            $value,
            $token->quoted,
            $token->position,
            $token->valuePosition,
        );
    }

    /**
     * Trennt einen vorangestellten Vergleich vom Wert.
     *
     * **Nur bei unquotierten Werten.** `browser:">100"` ist ein Browser, der so
     * heißt, und keine Rechnung — die Anführungszeichen sind die Notbremse für
     * jeden Wert, der zufällig wie Syntax aussieht.
     *
     * @return array{Comparator, string}
     */
    private static function comparator(Token $token): array
    {
        if ($token->quoted) {
            return [Comparator::Equals, $token->value];
        }

        foreach (Comparator::prefixes() as $comparator) {
            if (str_starts_with($token->value, $comparator->value)) {
                return [$comparator, mb_substr($token->value, mb_strlen($comparator->value))];
            }
        }

        return [Comparator::Equals, $token->value];
    }

    private function peek(): ?Token
    {
        return $this->tokens[$this->at] ?? null;
    }

    /**
     * @phpstan-impure schiebt den Lesezeiger weiter
     */
    private function next(): void
    {
        $this->at++;
    }
}
