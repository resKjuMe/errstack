<?php

namespace App\Support\Ingest\Grouping;

/**
 * Ein einzelner Bestandteil eines Fingerabdrucks: woher er kommt und was drin
 * steht.
 *
 * Der Name ist nicht Beiwerk für die Anzeige, sondern geht **in den Hash ein**.
 * Der Grund ist ein Zusammenstoß, der sonst unvermeidlich wäre: ein Meldungstext
 * „Zeitüberschreitung" und ein Ausnahme-Typ „Zeitüberschreitung" ergäben
 * denselben Fingerabdruck, obwohl das eine eine Notiz und das andere ein Absturz
 * ist. Mit dem Namen davor sind es zwei.
 */
final class Component
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
    ) {}

    /**
     * Die Form, in der der Bestandteil in den Hash eingeht.
     */
    public function signature(): string
    {
        return $this->name.'='.$this->value;
    }

    /**
     * @return array{name: string, value: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'value' => $this->value];
    }
}
