<?php

namespace App\Support\Discover;

use App\Support\Search\SearchExpression;
use RuntimeException;

/**
 * Eine Abfrage, die nicht ausgeführt wird — und der Grund dafür in einer Form,
 * mit der eine Oberfläche etwas anfangen kann.
 *
 * **Der Grund ist ein Kennzeichen und kein Satz.** Der Motor hat keine Sprache:
 * ob „Zu viele Zeilen" auf Deutsch oder Englisch dasteht und wie der Satz lautet,
 * entscheidet die Oberfläche (D2–D5), die auch weiß, welche Schaltfläche daneben
 * gehört. Sie bekommt deshalb {@see self::$reason} und die Zahlen dazu
 * ({@see self::$context}); die Meldung im Ausnahmetext ist für Protokolle und
 * Tests.
 *
 * **Grenzen sind keine Fehler der Eingabe, sondern Auskunft.** Wer 50.000 Zeilen
 * anfordert, hat sich nicht vertippt — er soll erfahren, wo die Grenze liegt und
 * dass sie überschritten wurde, nicht eine stillschweigend gekürzte Antwort
 * bekommen, die aussieht wie das Ganze. Genau deshalb steht in `context` immer
 * beides: das Erlaubte und das Verlangte.
 *
 * Eine **unverständliche Suchbedingung** ist dagegen keine Ausnahme: sie ist ein
 * Hinweis am Ergebnis ({@see SearchExpression}), damit die
 * Auswertung ungefiltert dasteht statt leer.
 */
final class DiscoverException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Eine Grenze der Konfiguration ist überschritten.
     */
    public static function limit(string $limit, int|float $allowed, int|float $given): self
    {
        return new self(
            sprintf('Grenze %s überschritten: %s erlaubt, %s verlangt.', $limit, $allowed, $given),
            'limit',
            ['limit' => $limit, 'allowed' => $allowed, 'given' => $given],
        );
    }

    /**
     * Die Datenbank hat die Abfrage nach der erlaubten Zeit abgebrochen.
     */
    public static function timeout(int $milliseconds): self
    {
        return new self(
            sprintf('Die Abfrage wurde nach %d ms abgebrochen.', $milliseconds),
            'timeout',
            ['timeout_ms' => $milliseconds],
        );
    }

    /**
     * Die Quelle kann diese Kennzahl nicht rechnen — nicht „noch nicht", sondern
     * „nicht sinnvoll": eine Fehlerquote über Fehlermeldungen wäre keine Zahl,
     * sondern ein Missverständnis.
     */
    public static function unsupported(Dataset $dataset, string $what): self
    {
        return new self(
            sprintf('Die Quelle %s kann %s nicht rechnen.', $dataset->value, $what),
            'unsupported',
            ['dataset' => $dataset->value, 'what' => $what],
        );
    }

    /**
     * Ein Feld, nach dem sich nicht gruppieren oder rechnen lässt.
     */
    public static function unknownField(Dataset $dataset, string $field): self
    {
        return new self(
            sprintf('Das Feld %s gibt es in der Quelle %s nicht.', $field, $dataset->value),
            'unknown_field',
            ['dataset' => $dataset->value, 'field' => $field],
        );
    }

    /**
     * Eine Abfrage, die in sich nicht stimmt.
     */
    public static function invalid(string $message): self
    {
        return new self($message, 'invalid');
    }

    /**
     * @return array{reason: string, message: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
