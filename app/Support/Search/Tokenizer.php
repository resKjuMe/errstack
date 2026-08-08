<?php

namespace App\Support\Search;

/**
 * Zerlegt eine Sucheingabe in ihre Bausteine.
 *
 * Die eine Regel, die alles andere nach sich zieht: **ein Begriff endet am
 * Leerzeichen — außer in Anführungszeichen.** Daraus folgt, dass der Zerleger
 * und nicht ein späterer Schritt die Anführungszeichen auflösen muss, und daraus
 * wiederum, dass er sich merkt, ob ein Wert welche hatte: `timesSeen:>100` ist
 * ein Vergleich, `browser:">100"` ist ein Browser, der so heißt.
 *
 * **Getrennt wird am ersten Doppelpunkt außerhalb von Anführungszeichen.**
 * Feldnamen tragen keinen, Werte ständig — `url:https://shop.example/checkout`
 * ist ein Wert mit zwei davon und nicht drei Begriffe.
 *
 * Was hier nicht passiert: nichts wird bewertet. Ob `is:` ein bekanntes Feld ist
 * und `unresolved` ein zulässiger Wert, weiß der Zerleger nicht und soll es
 * nicht wissen — sonst gäbe es die Suchsprache zweimal, einmal hier und einmal
 * dort, wo die Felder tatsächlich aufgelöst werden.
 */
final class Tokenizer
{
    /**
     * Zeichen, die einen Begriff beenden.
     *
     * @var list<string>
     */
    private const BREAKS = [' ', "\t", "\n", "\r", '(', ')'];

    /**
     * @return list<Token>
     *
     * @throws SearchSyntaxException
     */
    public static function tokenize(string $input): array
    {
        $chars = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);
        $chars = $chars === false ? [] : $chars;

        $tokens = [];
        $length = count($chars);
        $i = 0;

        while ($i < $length) {
            $char = $chars[$i];

            if (in_array($char, [' ', "\t", "\n", "\r"], true)) {
                $i++;

                continue;
            }

            if ($char === '(') {
                $tokens[] = new Token(TokenType::OpenParen, '(', $i);
                $i++;

                continue;
            }

            if ($char === ')') {
                $tokens[] = new Token(TokenType::CloseParen, ')', $i);
                $i++;

                continue;
            }

            // Das Ausrufezeichen verneint, was danach kommt — aber nur, wenn es
            // vor einem Begriff steht. Mitten in einem Wert (`title:huch!`)
            // gehört es zum Wert, und dorthin kommt es gar nicht erst, weil der
            // Begriff dann schon gelesen wird.
            if ($char === '!') {
                $tokens[] = new Token(TokenType::Not, '!', $i);
                $i++;

                continue;
            }

            $tokens[] = self::readTerm($chars, $i, $length);
        }

        return $tokens;
    }

    /**
     * Liest einen Begriff ab `$i` und schiebt den Zeiger hinter sein Ende.
     *
     * @param  list<string>  $chars
     *
     * @throws SearchSyntaxException
     */
    private static function readTerm(array $chars, int &$i, int $length): Token
    {
        $start = $i;
        $buffer = '';
        $key = null;
        $valuePosition = $i;

        // Woraus der **Wert** entstanden ist. Nur wenn er ausschließlich aus
        // einem Zitat kam, gilt er als in Anführungszeichen gesetzt — bei
        // `browser:Chrome" "124` wäre das Zitat bloß eine Notlösung für ein
        // Leerzeichen und keine Ansage „nimm das wörtlich".
        $plain = 0;
        $quotedParts = 0;

        while ($i < $length) {
            $char = $chars[$i];

            if (in_array($char, self::BREAKS, true)) {
                break;
            }

            if ($char === '"') {
                $buffer .= self::readQuoted($chars, $i, $length);
                $quotedParts++;

                continue;
            }

            if ($char === ':' && $key === null) {
                $key = $buffer;
                $buffer = '';
                $plain = 0;
                $quotedParts = 0;
                $i++;
                $valuePosition = $i;

                continue;
            }

            $buffer .= $char;
            $plain++;
            $i++;
        }

        $raw = implode('', array_slice($chars, $start, $i - $start));

        if ($key === '') {
            throw new SearchSyntaxException(
                __('search.errors.missing_field'),
                $start,
                $raw,
            );
        }

        $quoted = $quotedParts > 0 && $plain === 0;

        // `and` und `or` verknüpfen — aber nur als bloßes Wort. In
        // Anführungszeichen ist „or" ein Suchbegriff, und `title:or` erst recht.
        if ($key === null && ! $quoted) {
            $word = mb_strtolower($buffer);

            if ($word === 'and') {
                return new Token(TokenType::And, $raw, $start);
            }

            if ($word === 'or') {
                return new Token(TokenType::Or, $raw, $start);
            }
        }

        return new Token(TokenType::Term, $raw, $start, $key, $buffer, $quoted, $valuePosition);
    }

    /**
     * Liest ein Zitat ab dem öffnenden Anführungszeichen und gibt seinen Inhalt
     * zurück.
     *
     * Der Rückstrich flüchtet das nächste Zeichen, damit ein Anführungszeichen im
     * Wert überhaupt schreibbar ist (`title:"sagt \"nein\""`).
     *
     * @param  list<string>  $chars
     *
     * @throws SearchSyntaxException
     */
    private static function readQuoted(array $chars, int &$i, int $length): string
    {
        $opened = $i;
        $i++;
        $content = '';

        while ($i < $length) {
            $char = $chars[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $content .= $chars[$i + 1];
                $i += 2;

                continue;
            }

            if ($char === '"') {
                $i++;

                return $content;
            }

            $content .= $char;
            $i++;
        }

        throw new SearchSyntaxException(
            __('search.errors.unclosed_quote'),
            $opened,
            '"'.$content,
        );
    }
}
