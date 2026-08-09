<?php

namespace App\Support\Releases\Health;

use App\Models\ReleaseSessionCount;

/**
 * Eine Strichliste über Sitzungen: wie viele, und wie viele davon sind schlecht
 * ausgegangen.
 *
 * Der gemeinsame Nenner der beiden Wege, auf denen Sitzungen hereinkommen. Ein
 * `session`-Element ist eine einzelne Sitzung und ergibt eine Liste mit genau
 * einem Strich; ein `sessions`-Element bringt bereits gebündelte Zahlen mit und
 * ergibt eine Liste mit vielen. Ab hier sind beide dasselbe — und deshalb gibt
 * es die Fortschreibung der Zähler nur einmal statt zweimal fast gleich.
 *
 * **Warum Differenzen und nicht nur Summen.** Ein SDK meldet dieselbe Sitzung
 * mehrfach: erst „läuft", später „abgestürzt". Beim zweiten Mal darf nicht noch
 * eine Sitzung dazukommen, sondern die vorhandene wechselt ihren Ausgang — und
 * das ist eine Differenz aus dem, was sie vorher war, und dem, was sie jetzt
 * ist ({@see minus()}). Ohne diesen Schritt zählte jede Zwischenmeldung als
 * eigene Sitzung, und die Crash-Free-Rate einer gesunden Version fiele mit
 * jedem Lebenszeichen weiter.
 *
 * Unveränderlich, weil dieselbe Liste an mehrere Zähler geht (Version,
 * Umgebung, Nutzer) und ein `+=` an einer Stelle sonst die andere veränderte.
 */
final class SessionTally
{
    public function __construct(
        public readonly int $sessions = 0,
        public readonly int $errored = 0,
        public readonly int $crashed = 0,
        public readonly int $abnormal = 0,
    ) {}

    /**
     * Die Sitzungen, die ohne Fehler, Absturz und Abbruch durchgelaufen sind.
     *
     * Abgeleitet und nicht gespeichert: die drei schlechten Ausgänge schließen
     * einander aus, und eine fünfte Zahl daneben wäre eine, die irgendwann
     * nicht mehr zu den anderen passt.
     */
    public function healthy(): int
    {
        return max(0, $this->sessions - $this->errored - $this->crashed - $this->abnormal);
    }

    public function isEmpty(): bool
    {
        return $this->sessions === 0 && $this->errored === 0 && $this->crashed === 0 && $this->abnormal === 0;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->sessions + $other->sessions,
            $this->errored + $other->errored,
            $this->crashed + $other->crashed,
            $this->abnormal + $other->abnormal,
        );
    }

    public function minus(self $other): self
    {
        return new self(
            $this->sessions - $other->sessions,
            $this->errored - $other->errored,
            $this->crashed - $other->crashed,
            $this->abnormal - $other->abnormal,
        );
    }

    /**
     * Die Zahlen unter den Spaltennamen der Zähler-Tabellen.
     *
     * Beide Tabellen — die je Version und die je Nutzer — tragen dieselben vier
     * Spalten, und das ist Absicht: dieselbe Strichliste geht an beide, und die
     * Fortschreibung ({@see ReleaseSessionCount::apply()}) muss nicht wissen,
     * an welcher sie gerade arbeitet.
     *
     * @return array<string, int>
     */
    public function columns(): array
    {
        return [
            'session_count' => $this->sessions,
            'errored_count' => $this->errored,
            'crashed_count' => $this->crashed,
            'abnormal_count' => $this->abnormal,
        ];
    }
}
