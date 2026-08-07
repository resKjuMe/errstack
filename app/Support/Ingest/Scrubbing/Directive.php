<?php

namespace App\Support\Ingest\Scrubbing;

use App\Enums\ScrubRuleType;

/**
 * Eine einzelne Anweisung des Scrubbings — die eingebauten wie die selbst
 * angelegten.
 *
 * Absichtlich getrennt vom Model `ScrubRule`: die eingebauten
 * Standardregeln stehen in keiner Tabelle, sollen aber genau dasselbe tun.
 * Hätten sie einen eigenen Weg, gäbe es zwei Auswertungen desselben Gedankens,
 * und die eine würde beim nächsten Sonderfall anders entscheiden als die andere.
 *
 * Ein Ausdruck wird **einmal** in einen fertigen regulären Ausdruck übersetzt und
 * nicht bei jedem Feld erneut: eine Meldung hat leicht tausend Felder, und die
 * Regeln stehen für die ganze Meldung fest.
 */
final class Directive
{
    /**
     * Der übersetzte Ausdruck, oder `null`, wenn er sich nicht übersetzen ließ.
     *
     * Eine unbrauchbare Regel wird übersprungen und bringt die Aufnahme nicht zu
     * Fall. Beim Anlegen wird sie geprüft (`ScrubRuleRequest` nimmt dieselbe
     * Übersetzung), aber eine Regel überlebt Jahre und PHP-Fassungen — und eine
     * Meldung zu verlieren, weil ein alter Ausdruck nicht mehr übersetzt, wäre
     * der schlechtere Ausgang.
     */
    private readonly ?string $compiled;

    /**
     * Der Abschnitt, auf den die Regel beschränkt ist, in Kleinschreibung und
     * ohne Punkt am Ende.
     */
    private readonly ?string $scope;

    public function __construct(
        public readonly ScrubRuleType $type,
        public readonly string $expression,
        ?string $path = null,
    ) {
        $this->compiled = self::compile($type, $expression);

        $path = $path === null ? null : trim(strtolower($path), " \t.");

        $this->scope = $path === null || $path === '' ? null : $path;
    }

    /**
     * Greift die Regel in diesem Abschnitt?
     *
     * `$path` ist der Weg zum Feld ohne Listenzähler (`request.data.password`).
     * Ohne Einschränkung gilt die Regel überall; mit Einschränkung auch für alles
     * **unterhalb** des angegebenen Abschnitts — wer `request.data` schreibt,
     * meint den Rumpf samt allem, was darin steckt, und nicht nur seine oberste
     * Ebene.
     */
    public function appliesTo(string $path): bool
    {
        if ($this->scope === null) {
            return true;
        }

        $path = strtolower($path);

        return $path === $this->scope || str_starts_with($path, $this->scope.'.');
    }

    /**
     * Gilt die Regel überall in der Meldung?
     */
    public function isGlobal(): bool
    {
        return $this->scope === null;
    }

    /**
     * Trifft die Regel diesen Feldnamen? Nur Feld-Regeln tun das.
     */
    public function matchesField(string $key): bool
    {
        return $this->type === ScrubRuleType::Field
            && $this->compiled !== null
            && preg_match($this->compiled, $key) === 1;
    }

    /**
     * Ersetzt die Treffer in einem Wert — oder gibt ihn unverändert zurück.
     *
     * Nur Muster-Regeln tun das. Der Rückgabewert ist bewusst der (womöglich
     * unveränderte) Text und nicht ein Wahrheitswert samt zweitem Aufruf: der
     * Aufrufer müsste sonst denselben Ausdruck zweimal laufen lassen.
     */
    public function filterValue(string $value, string $replacement): string
    {
        if ($this->type !== ScrubRuleType::Pattern || $this->compiled === null) {
            return $value;
        }

        $filtered = preg_replace($this->compiled, $replacement, $value);

        // `null` heißt: der Ausdruck ist mitten im Lauf gescheitert (etwa an
        // einer Rekursionsgrenze). Dann bleibt der Wert, wie er war — was hier
        // sonst zurückkäme, wäre nicht „gefiltert", sondern leer.
        return $filtered ?? $value;
    }

    /**
     * Ist die Regel überhaupt anwendbar?
     */
    public function isUsable(): bool
    {
        return $this->compiled !== null;
    }

    /**
     * Übersetzt einen Ausdruck in seine PCRE-Form — dieselbe Übersetzung, die
     * die Eingabeprüfung benutzt, damit eine angenommene Regel auch läuft.
     */
    public static function compile(ScrubRuleType $type, string $expression): ?string
    {
        $expression = trim($expression);

        if ($expression === '') {
            return null;
        }

        $pattern = $type === ScrubRuleType::Field
            ? '/^'.str_replace('\*', '.*', preg_quote($expression, '/')).'$/i'
            : '/'.self::escapeDelimiter($expression).'/i';

        // Kein `u`: die Rohdaten einer abstürzenden Anwendung sind nicht
        // verlässlich UTF-8, und mit dieser Kennung gäbe `preg_match` auf einer
        // kaputten Bytefolge stillschweigend „kein Treffer" zurück — die Regel
        // würde also genau dort nicht greifen, wo am wenigsten hinzusehen ist.
        return @preg_match($pattern, '') === false ? null : $pattern;
    }

    /**
     * Schützt die Begrenzung im selbst geschriebenen Ausdruck.
     *
     * Ein `/` mitten in einer Regel (`^/kunden/\d+$`) würde den Ausdruck sonst
     * vorzeitig beenden. Ein bereits geschütztes bleibt unangetastet — sonst
     * hätte, wer es richtig gemacht hat, hinterher zwei Rückstriche.
     */
    private static function escapeDelimiter(string $expression): string
    {
        return (string) preg_replace('#(?<!\\\\)/#', '\\\\/', $expression);
    }
}
