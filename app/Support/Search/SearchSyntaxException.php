<?php

namespace App\Support\Search;

use RuntimeException;

/**
 * Eine Eingabe, die keine Suche ergibt.
 *
 * Der Ausnahmefall trägt **die Stelle** mit, an der die Suche hängen geblieben
 * ist, und nicht nur den Grund. Das ist der Unterschied zwischen „Ungültige
 * Eingabe" und einem Hinweis, mit dem sich etwas anfangen lässt: die Oberfläche
 * kann den Ausschnitt zeigen, an dem es klemmt, und der Suchende muss nicht
 * raten, welche der drei Klammern die falsche ist.
 *
 * Die Stelle ist ein Versatz in **Zeichen** und nicht in Bytes — die Eingabe
 * kommt aus einem Textfeld, und ein Umlaut davor darf den Zeiger nicht
 * verschieben.
 */
final class SearchSyntaxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $position,
        public readonly string $excerpt = '',
    ) {
        parent::__construct($message);
    }

    /**
     * Die Meldung, wie die Oberfläche sie braucht.
     *
     * @return array{message: string, position: int, excerpt: string}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'position' => $this->position,
            'excerpt' => $this->excerpt,
        ];
    }
}
