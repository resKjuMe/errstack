<?php

namespace App\Support\Ingest\Normalization;

/**
 * Das Protokoll der Normalisierung: was gekürzt und was verworfen wurde.
 *
 * Ohne diese Notizen wäre beides unsichtbar. Ein abgeschnittener Stacktrace
 * sieht aus wie ein kurzer, ein weggelassener Abschnitt wie einer, den das SDK
 * nie geschickt hat — und wer den Fehler sucht, sucht dann an der falschen
 * Stelle. Die Zusage „überlange Werte werden gekürzt und als gekürzt markiert"
 * hängt an dieser Klasse.
 *
 * Vermerkt wird der **Pfad**, nicht der Wert: `exception.0.value`,
 * `breadcrumbs`, `request.headers.cookie`. Der Wert wäre entweder zu groß
 * (deshalb wurde er ja gekürzt) oder ungültig (deshalb wurde er verworfen);
 * beides gehört nicht in eine Notiz, die neben jeder Meldung liegt.
 */
final class Notes
{
    /**
     * Pfade, deren Wert gekürzt wurde — der Inhalt ist echt, aber unvollständig.
     *
     * @var array<string, true>
     */
    private array $truncated = [];

    /**
     * Pfade, deren Wert verworfen wurde, weil er nicht die erwartete Form
     * hatte — der Inhalt fehlt.
     *
     * @var array<string, true>
     */
    private array $invalid = [];

    public function truncated(string $path): void
    {
        $this->truncated[$path] = true;
    }

    public function invalid(string $path): void
    {
        $this->invalid[$path] = true;
    }

    public function hasTruncations(): bool
    {
        return $this->truncated !== [];
    }

    public function hasInvalid(): bool
    {
        return $this->invalid !== [];
    }

    /**
     * Die Notizen in der Form, in der sie neben der Meldung abgelegt werden.
     *
     * Leere Listen fallen weg statt als `[]` dazustehen: der Regelfall ist die
     * unauffällige Meldung, und die soll keine leeren Fächer mit sich tragen.
     * Sind beide Listen leer, ist das Ergebnis `null` — dann steht in der
     * Spalte nichts, und „nichts" heißt genau: unverändert übernommen.
     *
     * @return array{truncated?: list<string>, invalid?: list<string>}|null
     */
    public function toArray(): ?array
    {
        $notes = [];

        if ($this->truncated !== []) {
            $notes['truncated'] = array_keys($this->truncated);
        }

        if ($this->invalid !== []) {
            $notes['invalid'] = array_keys($this->invalid);
        }

        return $notes === [] ? null : $notes;
    }
}
