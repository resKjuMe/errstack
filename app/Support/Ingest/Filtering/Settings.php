<?php

namespace App\Support\Ingest\Filtering;

use App\Enums\InboundFilterKind;
use App\Models\InboundFilterRule;
use App\Models\Project;

/**
 * Was für ein Projekt gilt: welche Filterarten an sind und welche Einträge sie
 * verwenden.
 *
 * Ein Gegenstand statt zweier Parameter, aus demselben Grund wie beim
 * Scrubbing: die Aufnahme und die Vorschau in der Oberfläche sollen dieselbe
 * Zusammenstellung benutzen. Zwei Wege, sie zu bilden, wären zwei
 * Gelegenheiten, sie unterschiedlich zu bilden — und die Vorschau würde dann
 * etwas anderes zeigen als das, was mit einer Meldung wirklich passiert.
 *
 * **Ist nichts eingeschaltet, wird nichts geladen.** Das ist keine
 * Feinoptimierung: der Regelfall ist ein Projekt ohne Filter, und der darf für
 * die Zusage „Filter kosten nichts, wenn man sie nicht braucht" keine Abfrage
 * je Meldung bezahlen.
 */
final class Settings
{
    /**
     * @param  array<string, bool>  $enabled  je Filterart, geschlüsselt mit ihrem Wert
     * @param  array<string, list<string>>  $expressions  die Einträge je Filterart
     */
    private function __construct(
        private readonly array $enabled,
        private readonly array $expressions,
    ) {}

    /**
     * Die Einstellungen eines Projekts samt seiner Einträge.
     */
    public static function forProject(Project $project): self
    {
        $enabled = [];

        foreach (InboundFilterKind::cases() as $kind) {
            $enabled[$kind->value] = (bool) $project->getAttribute($kind->column());
        }

        return new self($enabled, self::rulesFor($project, $enabled));
    }

    /**
     * Baut die Einstellungen ohne Datenbank — der Weg der Tests.
     *
     * @param  list<InboundFilterKind>  $enabled
     * @param  array<string, list<string>>  $expressions
     */
    public static function of(array $enabled = [], array $expressions = []): self
    {
        $switches = [];

        foreach (InboundFilterKind::cases() as $kind) {
            $switches[$kind->value] = in_array($kind, $enabled, strict: true);
        }

        return new self($switches, $expressions);
    }

    public function isEnabled(InboundFilterKind $kind): bool
    {
        return $this->enabled[$kind->value] ?? false;
    }

    /**
     * Greift überhaupt irgendein Filter?
     *
     * Die Frage, die sich der Verarbeitungsschritt zuerst stellt: lautet die
     * Antwort nein, ist er ohne einen einzigen Vergleich wieder draußen.
     */
    public function isActive(): bool
    {
        return in_array(true, $this->enabled, strict: true);
    }

    /**
     * Die Einträge einer Filterart, in der Reihenfolge ihrer Anlage.
     *
     * @return list<string>
     */
    public function expressionsFor(InboundFilterKind $kind): array
    {
        return $this->expressions[$kind->value] ?? [];
    }

    /**
     * Die aktiven Einträge des Projekts, nach Filterart sortiert.
     *
     * Eine Abfrage für alle Arten, und nur dann, wenn wenigstens eine Art mit
     * Liste eingeschaltet ist. Die Alternative — je Art eine Abfrage — wäre bei
     * einer Fehlerflut vier Abfragen je Meldung für eine Auskunft, die in eine
     * passt.
     *
     * @param  array<string, bool>  $enabled
     * @return array<string, list<string>>
     */
    private static function rulesFor(Project $project, array $enabled): array
    {
        $wanted = array_filter(
            InboundFilterKind::cases(),
            fn (InboundFilterKind $kind): bool => $kind->usesRules() && ($enabled[$kind->value] ?? false),
        );

        if ($wanted === []) {
            return [];
        }

        $expressions = [];

        $rules = InboundFilterRule::query()
            ->where('project_id', $project->id)
            ->active()
            ->whereIn('kind', array_map(fn (InboundFilterKind $kind): string => $kind->value, $wanted))
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $expressions[$rule->kind->value][] = $rule->expression;
        }

        return $expressions;
    }
}
