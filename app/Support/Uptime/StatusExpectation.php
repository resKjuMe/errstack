<?php

namespace App\Support\Uptime;

/**
 * Die Angabe, welche HTTP-Statuscodes als „erreichbar" gelten.
 *
 * Geschrieben wird sie als Text — `200-299`, `200-299,301`, `200` —, weil das
 * die Form ist, in der jemand sie hinschreibt und wiedererkennt. Diese Klasse
 * ist die einzige Stelle, die diesen Text auslegt: die Eingabeprüfung fragt
 * {@see isValid()}, die Prüfung selbst fragt {@see matches()}. Zwei Stellen mit
 * je einer eigenen Auslegung liefen unweigerlich auseinander, und der Preis
 * dafür wäre ein Ziel, das sich anlegen lässt und danach immer als ausgefallen
 * gilt.
 *
 * Warum kein einzelner erwarteter Code: eine Weiterleitung ist kein Ausfall,
 * und `401` ist für eine geschützte Seite die richtige Antwort. Wer nur `200`
 * zulassen kann, stellt entweder die halbe Anwendung nicht ein oder schaltet
 * die Prüfung ab.
 */
final class StatusExpectation
{
    /**
     * @param  list<array{0: int, 1: int}>  $ranges  Bereiche als Paare
     *                                               (von, bis), beide
     *                                               einschließlich.
     */
    private function __construct(private readonly array $ranges) {}

    /**
     * Legt die Angabe aus. Unlesbare Teile werden übergangen, nicht bemängelt —
     * das Bemängeln ist Sache der Eingabeprüfung, und eine Prüfung, die wegen
     * eines Tippfehlers in der Einstellung eine Ausnahme wirft, wäre ein
     * Ausfall der Überwachung statt einer Meldung darüber.
     */
    public static function parse(string $specification): self
    {
        $ranges = [];

        foreach (explode(',', $specification) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $part, $matches) === 1) {
                $from = (int) $matches[1];
                $to = (int) $matches[2];

                if ($from <= $to) {
                    $ranges[] = [$from, $to];
                }

                continue;
            }

            if (preg_match('/^\d{3}$/', $part) === 1) {
                $ranges[] = [(int) $part, (int) $part];
            }
        }

        return new self($ranges);
    }

    /**
     * Ist die Angabe vollständig lesbar?
     *
     * Strenger als {@see parse()}, und das mit Absicht: beim Eintippen soll ein
     * Tippfehler auffallen, im Betrieb soll er nicht die Überwachung anhalten.
     */
    public static function isValid(string $specification): bool
    {
        $parts = array_filter(array_map('trim', explode(',', $specification)), static fn (string $part): bool => $part !== '');

        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            if (preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $part, $matches) === 1) {
                if ((int) $matches[1] > (int) $matches[2]) {
                    return false;
                }

                continue;
            }

            if (preg_match('/^\d{3}$/', $part) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zählt dieser Statuscode als erreichbar?
     *
     * Eine Angabe ohne einen einzigen lesbaren Bereich lässt nichts durch. Das
     * ist die vorsichtige Seite: eine Überwachung, die im Zweifel „alles in
     * Ordnung" meldet, ist schlimmer als keine.
     */
    public function matches(int $status): bool
    {
        foreach ($this->ranges as [$from, $to]) {
            if ($status >= $from && $status <= $to) {
                return true;
            }
        }

        return false;
    }
}
