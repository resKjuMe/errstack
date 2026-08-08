<?php

namespace App\Support\Issues;

use App\Enums\EventLevel;
use App\Enums\IssuePriority;
use App\Models\ProjectTag;
use App\Models\ProjectTagKey;
use App\Models\Release;
use App\Models\Team;
use App\Models\User;
use App\Support\Filters\GlobalFilter;
use App\Support\Search\SearchQuery;
use App\Support\Tags\TagFacets;

/**
 * Was das Suchfeld an der Stelle des Schreibmarkers vorschlagen kann.
 *
 * Ohne Vorschläge ist eine Suchsprache eine Sprache, die man auswendig können
 * muss. Mit ihnen ist sie eine Liste, durch die man tippt — und das ist der
 * Unterschied zwischen „für Eingeweihte" und „benutzbar". Vorgeschlagen wird
 * deshalb beides: die **Felder** (`is:`, `browser:`) und, sobald eines steht,
 * die **Werte**, die es in den gewählten Projekten tatsächlich gibt.
 *
 * **Der Schreibmarker und nicht die ganze Eingabe entscheidet.** Wer in
 * `is:unresolved browser:Chr` hinter `Chr` steht, will Browser vorgeschlagen
 * bekommen und nicht Zustände. Der Ausschnitt davor wird deshalb von hinten
 * gelesen, bis ein Leerzeichen oder eine Klammer kommt — und zwar von Hand und
 * nicht mit dem Zerleger der Sprache: eine halb getippte Eingabe wie
 * `browser:"Chr` ist für ihn ein Fehler und für das Suchfeld der Normalfall.
 */
final class IssueSearchSuggestions
{
    /** Wie viele Vorschläge höchstens zurückkommen. */
    public const LIMIT = 12;

    /**
     * Zeichen, an denen ein Begriff beginnt.
     *
     * @var list<string>
     */
    private const BOUNDARIES = [' ', "\t", "\n", '(', ')', '!'];

    /**
     * Die Vorschläge zu einer Eingabe an einer bestimmten Stelle.
     *
     * `from` und `to` sagen, welcher Ausschnitt der Eingabe durch den gewählten
     * Vorschlag ersetzt wird — die Oberfläche muss dafür nicht ein zweites Mal
     * dieselbe Grenze suchen und dabei womöglich zu einem anderen Ergebnis
     * kommen.
     *
     * @return array{context: string, field: string|null, from: int, to: int, suggestions: list<array{value: string, label: string, hint: string|null}>}
     */
    public static function for(GlobalFilter $filter, string $input, int $cursor): array
    {
        $length = mb_strlen($input);
        $cursor = max(0, min($cursor, $length));
        $from = self::wordStart($input, $cursor);
        $word = mb_substr($input, $from, $cursor - $from);

        $colon = mb_strpos($word, ':');

        if ($colon === false) {
            return [
                'context' => 'field',
                'field' => null,
                'from' => $from,
                'to' => $cursor,
                'suggestions' => self::fields($filter, $word),
            ];
        }

        $field = mb_substr($word, 0, $colon);
        $prefix = self::unquote(mb_substr($word, $colon + 1));

        return [
            'context' => 'value',
            'field' => $field,
            'from' => $from,
            'to' => $cursor,
            'suggestions' => self::values($filter, $field, $prefix),
        ];
    }

    /**
     * Der Anfang des Begriffs, in dem der Schreibmarker steht.
     *
     * Anführungszeichen zählen nicht als Grenze: in `browser:"Chrome 12` gehört
     * das Leerzeichen zum Wert, und der Vorschlag soll den ganzen Begriff
     * ersetzen und nicht bei „12" ansetzen.
     */
    private static function wordStart(string $input, int $cursor): int
    {
        $quotes = 0;
        $start = 0;

        for ($i = 0; $i < $cursor; $i++) {
            $char = mb_substr($input, $i, 1);

            if ($char === '"') {
                $quotes++;

                continue;
            }

            if ($quotes % 2 === 0 && in_array($char, self::BOUNDARIES, true)) {
                $start = $i + 1;
                $quotes = 0;
            }
        }

        return $start;
    }

    /**
     * Vorschläge für den Feldnamen.
     *
     * Erst die Felder mit eigener Bedeutung, dann die Merkmale der gewählten
     * Projekte — in dieser Reihenfolge, weil `is:` und `level:` überall gelten
     * und ein `mandant:` nur dort, wo es jemand gesetzt hat.
     *
     * @return list<array{value: string, label: string, hint: string|null}>
     */
    private static function fields(GlobalFilter $filter, string $prefix): array
    {
        $needle = mb_strtolower($prefix);

        $known = array_values(array_filter(
            IssueFields::known(),
            fn (string $field): bool => str_starts_with(mb_strtolower($field), $needle),
        ));

        $suggestions = array_map(
            fn (string $field): array => [
                'value' => $field.':',
                'label' => $field.':',
                'hint' => __('search.fields.'.mb_strtolower($field)),
            ],
            $known,
        );

        foreach (self::tagKeys($filter, $needle, self::LIMIT) as $key) {
            $suggestions[] = [
                'value' => $key.':',
                'label' => $key.':',
                'hint' => TagFacets::label($key),
            ];
        }

        return array_slice($suggestions, 0, self::LIMIT);
    }

    /**
     * Vorschläge für den Wert eines Feldes.
     *
     * @return list<array{value: string, label: string, hint: string|null}>
     */
    private static function values(GlobalFilter $filter, string $field, string $prefix): array
    {
        $key = mb_strtolower($field);

        $fixed = match ($key) {
            'is' => ['unresolved', 'resolved', 'ignored', 'assigned', 'unassigned', 'for_review', 'regressed'],
            'level' => array_column(EventLevel::cases(), 'value'),
            'priority', 'issue.priority' => array_column(IssuePriority::cases(), 'value'),
            'timesseen', 'usersseen' => ['>100', '>1000', '1'],
            'firstseen', 'lastseen' => ['-24h', '-7d', '+30d'],
            default => null,
        };

        if ($fixed !== null) {
            return self::wrap($field, self::startingWith($fixed, $prefix));
        }

        if ($key === 'release' || $key === 'firstrelease') {
            return self::wrap($field, self::versions($filter, $prefix));
        }

        if ($key === 'assigned') {
            return self::wrap($field, self::assignees($filter, $prefix));
        }

        // Alles übrige ist ein Merkmal — und dessen Werte kennt nur die
        // Auswertung aus S3.
        return self::wrap($field, self::tagValues($filter, $key, $prefix));
    }

    /**
     * Aus Werten werden Begriffe: `browser:"Chrome 124"` und nicht `Chrome 124`.
     *
     * Der Vorschlag ersetzt den ganzen Begriff und nicht nur seinen Rest — sonst
     * müsste die Oberfläche wissen, wo der Wert anfängt, und das weiß sie
     * schlechter als die Stelle, die ihn gerade zerlegt hat.
     *
     * @param  list<string>  $values
     * @return list<array{value: string, label: string, hint: string|null}>
     */
    private static function wrap(string $field, array $values): array
    {
        return array_map(
            fn (string $value): array => [
                'value' => SearchQuery::term($field, $value),
                'label' => $value,
                'hint' => null,
            ],
            $values,
        );
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function startingWith(array $values, string $prefix): array
    {
        $needle = mb_strtolower($prefix);

        return array_values(array_filter(
            $values,
            fn (string $value): bool => str_starts_with(mb_strtolower($value), $needle),
        ));
    }

    /**
     * Die Merkmalsnamen der gewählten Projekte, die häufigsten zuerst.
     *
     * @return list<string>
     */
    private static function tagKeys(GlobalFilter $filter, string $prefix, int $limit): array
    {
        $projectIds = $filter->projectIds();

        if ($projectIds === []) {
            return [];
        }

        $query = ProjectTagKey::query()
            ->whereIn('project_id', $projectIds)
            ->when($prefix !== '', fn ($q) => $q->whereRaw("tag_key like ? escape '!'", [self::like($prefix)]))
            ->select('tag_key')
            ->groupBy('tag_key')
            ->orderByRaw('sum(times_seen) desc')
            ->limit($limit);

        return $query->pluck('tag_key')->map(fn (mixed $key): string => (string) $key)->values()->all();
    }

    /**
     * Die Werte eines Merkmals, die häufigsten zuerst.
     *
     * @return list<string>
     */
    private static function tagValues(GlobalFilter $filter, string $key, string $prefix): array
    {
        $projectIds = $filter->projectIds();

        if ($projectIds === []) {
            return [];
        }

        return ProjectTag::query()
            ->whereIn('project_id', $projectIds)
            ->where('tag_key', $key)
            ->when($prefix !== '', fn ($q) => $q->whereRaw("tag_value like ? escape '!'", [self::like($prefix)]))
            ->select('tag_value')
            ->groupBy('tag_value')
            ->orderByRaw('sum(times_seen) desc')
            ->limit(self::LIMIT)
            ->pluck('tag_value')
            ->map(fn (mixed $value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * Die Zuständigen zur Auswahl: `me`, `none`, die Teams und die Mitglieder
     * der Organisation (S7).
     *
     * Vorgeschlagen wird, was man auch tippen kann — bei Personen die
     * E-Mail-Adresse ({@see IssueAssignee::term()}), weil sie eindeutig ist und
     * ein gespeicherter Link damit dieselbe Person meint, auch wenn jemand mit
     * gleichem Namen dazukommt.
     *
     * @return list<string>
     */
    private static function assignees(GlobalFilter $filter, string $prefix): array
    {
        $organization = $filter->organization;

        if ($organization === null) {
            return [];
        }

        $teams = Team::query()
            ->where('organization_id', $organization->id)
            ->when($prefix !== '', fn ($q) => $q->whereRaw("name like ? escape '!'", [self::like($prefix)]))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name'])
            ->map(static fn (Team $team): string => IssueAssignee::forTeam($team)->term())
            ->all();

        $members = User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
            ->where('organization_user.organization_id', $organization->id)
            ->when($prefix !== '', fn ($q) => $q->where(
                fn ($any) => $any
                    ->whereRaw("users.name like ? escape '!'", [self::like($prefix)])
                    ->orWhereRaw("users.email like ? escape '!'", [self::like($prefix)]),
            ))
            ->orderBy('users.name')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (User $user): string => IssueAssignee::forUser($user)->term())
            ->all();

        return array_slice([
            // `me` und `none` zuerst und ohne Namensvergleich: sie sind die
            // beiden häufigsten Antworten und die einzigen, die auch dann
            // gelten, wenn die Organisation niemanden führt.
            ...self::startingWith([IssueAssignee::SELF, IssueAssignee::NOBODY], $prefix),
            ...$teams,
            ...$members,
        ], 0, self::LIMIT);
    }

    /**
     * Die Versionen der gewählten Projekte, die neuesten zuerst.
     *
     * @return list<string>
     */
    private static function versions(GlobalFilter $filter, string $prefix): array
    {
        $projectIds = $filter->projectIds();

        if ($projectIds === []) {
            return [];
        }

        return Release::query()
            ->whereIn('project_id', $projectIds)
            ->when($prefix !== '', fn ($q) => $q->whereRaw("version like ? escape '!'", [self::like($prefix)]))
            ->orderByDesc('last_event_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->pluck('version')
            ->unique()
            ->map(fn (mixed $version): string => (string) $version)
            ->values()
            ->all();
    }

    /**
     * Ein Anfangs-Muster für `LIKE`, mit unschädlich gemachten Platzhaltern.
     *
     * Ohne das Fluchtzeichen wäre ein `%` in der Eingabe ein Platzhalter — und
     * ein Suchender, der ein Prozentzeichen tippt, bekäme alles vorgeschlagen.
     * Das Fluchtzeichen ist das Ausrufezeichen und nicht der Rückstrich — aus
     * denselben Gründen wie überall sonst in dieser Anwendung.
     */
    private static function like(string $prefix): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
    }

    /**
     * Nimmt die Anführungszeichen von einem halb getippten Wert.
     */
    private static function unquote(string $value): string
    {
        return ltrim($value, '"');
    }
}
