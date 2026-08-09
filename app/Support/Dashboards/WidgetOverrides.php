<?php

namespace App\Support\Dashboards;

use App\Enums\FilterPeriod;
use App\Models\Environment;
use App\Models\Project;
use App\Support\Discover\TimeRange;
use App\Support\Filters\GlobalFilter;

/**
 * Was eine Kachel an der globalen Filterleiste für sich anders sieht.
 *
 * **Die Leiste gilt, solange die Kachel nichts sagt.** Das ist die Reihenfolge
 * und nicht umgekehrt: wer oben den Zeitraum umstellt, will das ganze Dashboard
 * umstellen — sonst wäre die Leiste dort eine Zierde. Eine Kachel, die eine
 * eigene Angabe trägt, behält sie; alles Übrige folgt weiterhin der Leiste.
 * Deshalb sind die drei Felder einzeln überschreibbar und nicht gemeinsam: eine
 * Kachel darf „immer letzte 7 Tage" sagen und beim Projekt trotzdem mitgehen.
 *
 * **Drei Dinge und nicht mehr.** Zeitraum, Umgebung, Projekt — genau das, was
 * die Leiste führt. Eine Kachel, die darüber hinaus etwas überschriebe, wäre
 * keine Ausnahme von der Leiste mehr, sondern eine zweite daneben.
 *
 * **Ein unbekanntes Projekt wird übergangen, nicht abgewiesen.** Ein Projekt
 * kann gelöscht worden sein, und ein Betrachter darf nicht jedes sehen. Beides
 * darf ein Dashboard nicht zerschießen: die Kachel fällt dann auf die Leiste
 * zurück und sagt es (`projectMissing`) — dieselbe Nachsicht wie beim Filter
 * selbst.
 */
final class WidgetOverrides
{
    private function __construct(
        public readonly ?FilterPeriod $period,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?string $environment,
        public readonly ?string $projectSlug,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null, null, null);
    }

    public static function make(
        ?FilterPeriod $period = null,
        ?string $from = null,
        ?string $to = null,
        ?string $environment = null,
        ?string $projectSlug = null,
    ): self {
        // Ein eigener Zeitraum ohne Grenzen ist keiner — dieselbe Regel wie in
        // der Leiste, damit Auswahl und gezeigter Ausschnitt zusammenpassen.
        if ($period === FilterPeriod::Custom && ($from === null || $from === '' || $to === null || $to === '')) {
            $period = null;
        }

        return new self(
            $period,
            self::text($from),
            self::text($to),
            Environment::normalizeName($environment),
            self::text($projectSlug),
        );
    }

    public static function fromArray(mixed $value): self
    {
        $value = is_array($value) ? $value : [];

        return self::make(
            period: is_string($value['period'] ?? null) ? FilterPeriod::tryFrom($value['period']) : null,
            from: is_string($value['from'] ?? null) ? $value['from'] : null,
            to: is_string($value['to'] ?? null) ? $value['to'] : null,
            environment: is_string($value['environment'] ?? null) ? $value['environment'] : null,
            projectSlug: is_string($value['project'] ?? null) ? $value['project'] : null,
        );
    }

    /**
     * @return array{period: string|null, from: string|null, to: string|null, environment: string|null, project: string|null}
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period?->value,
            'from' => $this->from,
            'to' => $this->to,
            'environment' => $this->environment,
            'project' => $this->projectSlug,
        ];
    }

    /**
     * Trägt die Kachel überhaupt eine eigene Angabe? Sonst wird gar nichts
     * gespeichert — ein leeres Objekt in der Spalte wäre eine Aussage, die
     * keine ist.
     */
    public function isEmpty(): bool
    {
        return $this->period === null && $this->environment === null && $this->projectSlug === null;
    }

    /**
     * Der Zeitraum, in dem diese Kachel rechnet.
     */
    public function rangeFor(GlobalFilter $filter): TimeRange
    {
        if ($this->period === null) {
            return TimeRange::of($filter->fromUtc(), $filter->toUtc());
        }

        [$from, $to] = GlobalFilter::resolveRange($this->period, $this->from, $this->to, $filter->timezone);

        return TimeRange::of($from->utc(), $to->utc());
    }

    /**
     * Die Umgebung, auf die diese Kachel schaut — leer heißt „alle".
     */
    public function environmentFor(GlobalFilter $filter): ?string
    {
        return $this->environment ?? $filter->environment;
    }

    /**
     * Das Projekt, über das diese Kachel rechnet.
     *
     * Ohne eigene Angabe das der Leiste — und dort nur, wenn sie genau eines
     * benennt: über mehrere Projekte hinweg lässt sich ein Perzentil nicht
     * zusammenzählen, und die Grenzen des Motors gälten für keine der Abfragen
     * (dieselbe Begründung wie in {@see App\Http\Controllers\DiscoverController}).
     */
    public function projectFor(GlobalFilter $filter): ?Project
    {
        if ($this->projectSlug === null) {
            return $filter->projects->count() === 1 ? $filter->projects->first() : null;
        }

        return $filter->availableProjects
            ->first(fn (Project $project): bool => $project->slug === $this->projectSlug);
    }

    /**
     * Zeigt die Kachel auf ein Projekt, das der Betrachter nicht (mehr) hat?
     */
    public function projectMissing(GlobalFilter $filter): bool
    {
        return $this->projectSlug !== null && $this->projectFor($filter) === null;
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
