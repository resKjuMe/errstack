<?php

namespace App\Support\Search;

/**
 * Ein Baustein der Eingabe, samt seiner Stelle im Text.
 *
 * Die Stelle wird durchgereicht, weil sie am Ende gebraucht wird und sich später
 * nicht mehr herleiten lässt: `is:` zweimal in derselben Eingabe sieht im
 * Ausdrucksbaum gleich aus, und der Hinweis soll auf das gemeinte zeigen.
 *
 * **`quoted` ist keine Nebensache.** Ob ein Wert in Anführungszeichen stand,
 * entscheidet über seine Bedeutung: `timesSeen:>100` ist ein Vergleich,
 * `browser:">100"` ist ein Wert, der so heißt, und `route:GET*` sucht mit
 * Platzhalter, wo `route:"GET*"` einen Stern meint. Wer das nach dem Zerlegen
 * nicht mehr weiß, muss raten.
 */
final class Token
{
    public function __construct(
        public readonly TokenType $type,
        /** Der Text, wie er dastand — für Meldungen. */
        public readonly string $raw,
        /** Der Versatz des Bausteins in Zeichen. */
        public readonly int $position,
        /** Der Schlüssel eines `schlüssel:wert`, sonst `null`. */
        public readonly ?string $key = null,
        /** Der Wert bzw. bei freiem Text der Begriff selbst. */
        public readonly string $value = '',
        /** Stand der Wert in Anführungszeichen? */
        public readonly bool $quoted = false,
        /** Der Versatz des Wertes — die Stelle, auf die ein Werte-Hinweis zeigt. */
        public readonly int $valuePosition = 0,
    ) {}

    /**
     * Ein Begriff mit Schlüssel — `is:unresolved`, nicht `datenbank`.
     */
    public function hasKey(): bool
    {
        return $this->key !== null;
    }
}
