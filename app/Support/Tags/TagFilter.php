<?php

namespace App\Support\Tags;

use App\Models\Issue;
use App\Models\IssueTag;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Einschränkung der Fehlerliste auf einen Merkmalswert — „nur Chrome 124".
 *
 * Sie steht als **ein** Feld in der Adresszeile (`?tag=browser:Chrome 124`) und
 * nicht als zwei. Der Grund ist die Suchsprache, die in S4 folgt: dort ist
 * `browser:Chrome` ein Ausdruck, den jemand tippt, und derselbe Ausdruck soll
 * entstehen, wenn er stattdessen in der Verteilung auf den Balken klickt. Zwei
 * getrennte Felder wären eine zweite Schreibweise für dieselbe Aussage — und die
 * müsste in S4 wieder eingesammelt werden.
 *
 * Getrennt wird am **ersten** Doppelpunkt: Merkmalsnamen tragen keinen, Werte
 * dagegen ständig (`url:https://shop.example/checkout`).
 */
final class TagFilter
{
    private function __construct(
        public readonly string $key,
        public readonly string $value,
    ) {}

    /**
     * Liest die Angabe aus der Adresszeile — oder `null`, wenn keine brauchbare
     * dasteht.
     *
     * Unbrauchbares wird übergangen und nicht abgewiesen: ein von Hand
     * verkürzter Link soll die Liste ungefiltert zeigen und nicht mit einem
     * Fehler antworten.
     */
    public static function parse(?string $input): ?self
    {
        if ($input === null) {
            return null;
        }

        $cut = strpos($input, ':');

        if ($cut === false || $cut === 0) {
            return null;
        }

        $key = trim(substr($input, 0, $cut));
        $value = trim(substr($input, $cut + 1));

        if ($key === '' || $value === '') {
            return null;
        }

        return new self($key, $value);
    }

    public static function make(string $key, string $value): self
    {
        return new self($key, $value);
    }

    /**
     * Die Schreibweise für die Adresszeile.
     */
    public function toQuery(): string
    {
        return $this->key.':'.$this->value;
    }

    /**
     * Schränkt eine Abfrage auf Einträge ein, die diesen Merkmalswert tragen.
     *
     * `whereExists` und keine Verknüpfung: ein Fehler hat je Merkmalswert
     * höchstens eine Zeile, ein `join` würde die Liste also nicht vervielfachen
     * — er würde aber die Zähler-Spalten mitschleppen und die Sortierung der
     * Fehlerliste um eine Tabelle erweitern. Die Existenzprüfung greift
     * stattdessen auf den eindeutigen Index `(issue_id, tag_key, tag_value)` zu
     * und ist mit der ersten gefundenen Zeile fertig.
     *
     * @param  Builder<Issue>  $query
     */
    public function apply(Builder $query): void
    {
        $table = (new IssueTag)->getTable();

        $query->whereExists(function ($sub) use ($table): void {
            $sub->select(1)
                ->from($table)
                ->whereColumn($table.'.issue_id', 'issues.id')
                ->where($table.'.tag_key', $this->key)
                ->where($table.'.tag_value', $this->value);
        });
    }
}
