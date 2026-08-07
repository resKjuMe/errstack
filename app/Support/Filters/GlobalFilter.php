<?php

namespace App\Support\Filters;

use App\Enums\FilterPeriod;
use App\Http\Requests\GlobalFilterRequest;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Der Zustand der globalen Filterleiste — Projekt, Umgebung und Zeitraum — in
 * aufgelöster Form. Jede Auswertungsseite bekommt ihn über
 * {@see GlobalFilterRequest} und schränkt ihre Abfrage damit
 * über `apply()` ein; dadurch filtern alle Seiten nach denselben Regeln.
 *
 * Relative Zeiträume („letzte 24 Stunden") werden hier serverseitig aufgelöst,
 * und zwar in der Zeitzone des Betrachters: nur so trifft „letzte 7 Tage" auch
 * die Tagesgrenzen, die er auf seiner Uhr sieht.
 */
final class GlobalFilter
{
    /**
     * @param  Collection<int, Project>  $availableProjects  alle Projekte, die der Betrachter wählen darf
     * @param  Collection<int, Project>  $projects  die gewählten — ohne Auswahl alle verfügbaren
     * @param  list<string>  $selectedSlugs  die ausdrücklich gewählten Projekte (leer = „alle")
     * @param  list<string>  $availableEnvironments  sichtbare Umgebungen der wählbaren Projekte
     */
    private function __construct(
        public readonly ?Organization $organization,
        public readonly Collection $availableProjects,
        public readonly Collection $projects,
        public readonly array $selectedSlugs,
        public readonly array $availableEnvironments,
        public readonly ?string $environment,
        public readonly FilterPeriod $period,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $timezone,
    ) {}

    /**
     * Baut den Filter aus den geprüften Werten der Adresszeile. Unbekannte
     * Projekte und Umgebungen werden übergangen statt abgewiesen: ein Link auf
     * ein inzwischen gelöschtes Projekt soll die Seite nicht zerschießen.
     *
     * @param  array{projects?: list<string>|null, environment?: string|null, period?: string|null, from?: string|null, to?: string|null, tz?: string|null}  $input
     */
    public static function resolve(?Organization $organization, User $viewer, array $input): self
    {
        $available = self::availableProjects($organization, $viewer);
        $timezone = self::timezone($input['tz'] ?? null);

        $selected = array_values(array_intersect(
            array_map(fn (mixed $slug): string => (string) $slug, $input['projects'] ?? []),
            $available->pluck('slug')->all(),
        ));

        $projects = $selected === []
            ? $available
            : $available->filter(fn (Project $project): bool => in_array($project->slug, $selected, true))->values();

        $period = FilterPeriod::tryFrom((string) ($input['period'] ?? '')) ?? FilterPeriod::default();
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;

        // Ein eigener Zeitraum ohne Grenzen ist keiner — dann gilt wieder die
        // Voreinstellung, damit Auswahl und angezeigter Zeitraum zusammenpassen.
        if ($period === FilterPeriod::Custom && ($from === null || $from === '' || $to === null || $to === '')) {
            $period = FilterPeriod::default();
        }

        $environments = self::availableEnvironments($available);
        $environment = Environment::normalizeName($input['environment'] ?? null);
        [$start, $end] = self::range($period, $from, $to, $timezone);

        return new self(
            organization: $organization,
            availableProjects: $available,
            projects: $projects,
            selectedSlugs: $selected,
            availableEnvironments: $environments,
            environment: in_array($environment, $environments, true) ? $environment : null,
            period: $period,
            from: $start,
            to: $end,
            timezone: $timezone,
        );
    }

    /**
     * Schränkt eine Abfrage auf die gewählten Projekte, die gewählte Umgebung
     * und den Zeitraum ein. Die Spaltennamen sind angebbar, weil nicht jede
     * Tabelle ihren Zeitstempel gleich nennt (`occurred_at`, `last_seen_at`, …).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(
        Builder $query,
        string $timestampColumn = 'occurred_at',
        string $projectColumn = 'project_id',
        string $environmentColumn = 'environment',
    ): Builder {
        // Ohne Projekt gibt es nichts zu zeigen. Die leere Liste erledigt das
        // von selbst — sie liefert kein Ergebnis, statt stillschweigend alles
        // durchzulassen.
        $query->whereIn($projectColumn, $this->projectIds());

        if ($this->environment !== null) {
            $query->where($environmentColumn, $this->environment);
        }

        return $query->whereBetween($timestampColumn, [$this->fromUtc(), $this->toUtc()]);
    }

    /**
     * @return list<int>
     */
    public function projectIds(): array
    {
        return $this->projects->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    /**
     * Die Werte, wie die Filterleiste sie in ihren Feldern führt — leere Felder
     * als leerer Text, damit die Oberfläche sie unverändert zurückspielen kann.
     *
     * @return array{projects: list<string>, environment: string, period: string, from: string, to: string, tz: string}
     */
    public function formValues(): array
    {
        return [
            'projects' => $this->selectedSlugs,
            'environment' => $this->environment ?? '',
            'period' => $this->period->value,
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'tz' => $this->timezone,
        ];
    }

    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function toUtc(): CarbonImmutable
    {
        return $this->to->utc();
    }

    /**
     * Der aufgelöste Zeitraum in Worten — die Filterleiste zeigt ihn unter der
     * Auswahl, damit sichtbar ist, was „letzte 7 Tage" gerade bedeutet.
     */
    public function rangeLabel(): string
    {
        return $this->from->format('d.m.Y H:i').' – '.$this->to->format('d.m.Y H:i');
    }

    /**
     * Projekte, unter denen der Betrachter wählen darf: die der aktiven
     * Organisation. Ohne Organisation bleibt die Auswahl leer.
     *
     * @return Collection<int, Project>
     */
    private static function availableProjects(?Organization $organization, User $viewer): Collection
    {
        if ($organization === null || ! $organization->hasMember($viewer)) {
            /** @var Collection<int, Project> $empty */
            $empty = new Collection;

            return $empty;
        }

        return $organization->projects()->orderBy('name')->get();
    }

    /**
     * Sichtbare Umgebungen der wählbaren Projekte — die Auswahlliste der
     * Filterleiste. Versteckte Umgebungen fehlen hier; ihre Daten bleiben in den
     * Auswertungen enthalten, solange nicht auf eine Umgebung gefiltert wird.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<string>
     */
    private static function availableEnvironments(Collection $projects): array
    {
        $projectIds = $projects->pluck('id')->all();

        if ($projectIds === []) {
            return [];
        }

        /** @var list<string> $names */
        $names = Environment::query()
            ->visible()
            ->whereIn('project_id', $projectIds)
            ->distinct()
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return $names;
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private static function range(FilterPeriod $period, ?string $from, ?string $to, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $hours = $period->hours();

        if ($hours !== null) {
            return [$now->subHours($hours), $now];
        }

        // Eigener Zeitraum: die Datumsfelder gelten einschließlich — wer den 5.
        // wählt, will den 5. dabeihaben. Fehlt eine Grenze, tritt der
        // Standard-Zeitraum an ihre Stelle, damit die Seite nicht leer bleibt.
        if ($from === null || $to === null) {
            return self::range(FilterPeriod::default(), null, null, $timezone);
        }

        $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $timezone)->endOfDay();

        return $end->lessThan($start) ? [$start, $start->endOfDay()] : [$start, $end];
    }

    /**
     * Zeitzone des Betrachters. Sie kommt aus der Adresszeile (die Oberfläche
     * trägt die Zone des Browsers ein); ist sie unbekannt oder unbrauchbar,
     * gilt die der Anwendung.
     */
    private static function timezone(?string $timezone): string
    {
        $fallback = (string) config('app.timezone', 'UTC');

        if ($timezone === null || $timezone === '') {
            return $fallback;
        }

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : $fallback;
    }
}
