<?php

namespace App\Support\Issues;

use App\Models\Project;
use App\Models\SavedSearch;
use App\Models\SavedSearchDefault;
use App\Models\User;
use App\Policies\SavedSearchPolicy;
use App\Support\Filters\GlobalFilter;

/**
 * Die Ansichtsleiste der Fehlerliste: die Standard-Ansichten, die gespeicherten
 * Suchen und die Adressen, unter denen man sie verwaltet.
 *
 * Sie hängt an **jeder** Fehlerliste, und das ist der Grund, warum sie hier
 * gebaut wird und nicht im Controller: die Liste ist die meistgeladene Seite
 * dieser Anwendung, und was sie jedes Mal mitschleppt, muss man an einer Stelle
 * überblicken können. Zwei Abfragen sind es — die sichtbaren Suchen und, wenn
 * genau ein Projekt in der Auswahl steht, dessen Einstieg.
 *
 * **Die Standard-Ansichten stehen neben den gespeicherten und nicht darüber.**
 * Beide sind dasselbe — Ausdruck plus Sortierung ({@see IssueViews}) —, und die
 * Oberfläche unterscheidet sie nur darin, dass die einen einen Verwaltungsknopf
 * haben und die anderen nicht.
 */
final class SavedSearchData
{
    /**
     * @return array{
     *     views: list<array{key: string, name: string, query: string, sort: string, href: string, available: bool}>,
     *     items: list<array<string, mixed>>,
     *     storeHref: string,
     *     project: array{slug: string, name: string}|null,
     *     defaultId: int|null,
     *     limit: int,
     *     remaining: int,
     * }
     */
    public static function bar(GlobalFilter $filter, User $viewer): array
    {
        $organization = $filter->organization;

        if ($organization === null) {
            return self::empty();
        }

        /** @var list<SavedSearch> $searches */
        $searches = SavedSearch::query()
            ->visibleTo($viewer, $organization)
            // Der Name des Erstellers steht an jeder fremden Suche; ohne ihn
            // wäre sie eine Ansicht ohne Herkunft, und „warum sieht die anders
            // aus als gestern?" hätte keinen Adressaten.
            ->with('user:id,name')
            ->orderBy('name')
            ->get()
            ->all();

        // Genau ein Projekt in der Auswahl — nur dann ist „Standard für dieses
        // Projekt" eine sinnvolle Aussage. Bei drei Projekten wäre die Frage,
        // für welches davon; bei keinem gäbe es nichts festzulegen.
        $project = $filter->projects->count() === 1 ? $filter->projects->first() : null;

        $default = $project instanceof Project
            ? self::defaultFor($viewer, $project)
            : null;

        $own = 0;

        foreach ($searches as $search) {
            if ($search->user_id === $viewer->id) {
                $own++;
            }
        }

        return [
            'views' => IssueViews::forFilter($filter),
            'items' => array_map(
                static fn (SavedSearch $search): array => self::item($search, $filter, $viewer, $default),
                $searches,
            ),
            'storeHref' => route('issues.searches.store'),
            'project' => $project instanceof Project
                ? ['slug' => $project->slug, 'name' => $project->name]
                : null,
            'defaultId' => $default,
            'limit' => SavedSearch::MAX_PER_USER,
            'remaining' => max(0, SavedSearch::MAX_PER_USER - $own),
        ];
    }

    /**
     * Die Suche, mit der dieses Projekt für diesen Betrachter aufgeht — oder
     * `null`.
     *
     * Die Prüfung, ob er sie überhaupt noch sehen darf, steckt in der Abfrage:
     * eine Suche, deren Freigabe zurückgenommen wurde, ist für alle anderen ab
     * sofort keine — und dann geht das Projekt wieder mit der gewöhnlichen
     * Liste auf, statt eine Ansicht zu zeigen, die es nicht mehr gibt.
     */
    public static function defaultSearch(User $viewer, Project $project): ?SavedSearch
    {
        $organization = $project->organization;

        if ($organization === null) {
            return null;
        }

        return SavedSearch::query()
            ->visibleTo($viewer, $organization)
            ->whereHas(
                'defaults',
                fn ($defaults) => $defaults
                    ->where('user_id', $viewer->id)
                    ->where('project_id', $project->id),
            )
            ->first();
    }

    /**
     * Eine Zeile der Auswahlliste.
     *
     * `own` entscheidet in der Oberfläche darüber, ob die Verwaltungsknöpfe
     * erscheinen. Es ist bewusst eine Angabe des Servers und keine Rechnung im
     * Browser: die Regel steht in {@see SavedSearchPolicy}, und
     * eine zweite Fassung davon in JavaScript wäre die, die als Erste veraltet.
     *
     * @return array<string, mixed>
     */
    private static function item(SavedSearch $search, GlobalFilter $filter, User $viewer, ?int $default): array
    {
        return [
            'id' => $search->id,
            'name' => $search->name,
            'query' => $search->query,
            'sort' => $search->sort->value,
            'sortLabel' => $search->sort->label(),
            'shared' => $search->shared,
            'own' => $search->user_id === $viewer->id,
            'ownerName' => $search->user?->name,
            'isDefault' => $default !== null && $default === $search->id,
            'href' => IssueViews::href($filter, $search->query, $search->sort),
            'updateHref' => route('issues.searches.update', $search),
            'destroyHref' => route('issues.searches.destroy', $search),
            'defaultHref' => route('issues.searches.default.store', $search),
        ];
    }

    private static function defaultFor(User $viewer, Project $project): ?int
    {
        $default = SavedSearchDefault::query()
            ->where('user_id', $viewer->id)
            ->where('project_id', $project->id)
            ->first();

        return $default?->saved_search_id;
    }

    /**
     * Ohne Organisation gibt es weder Projekte noch Suchen — die Leiste bleibt
     * leer, statt mit Adressen zu antworten, die ins Leere zeigen.
     *
     * @return array{
     *     views: list<array{key: string, name: string, query: string, sort: string, href: string, available: bool}>,
     *     items: list<array<string, mixed>>,
     *     storeHref: string,
     *     project: null,
     *     defaultId: null,
     *     limit: int,
     *     remaining: int,
     * }
     */
    private static function empty(): array
    {
        return [
            'views' => [],
            'items' => [],
            'storeHref' => route('issues.searches.store'),
            'project' => null,
            'defaultId' => null,
            'limit' => SavedSearch::MAX_PER_USER,
            'remaining' => 0,
        ];
    }
}
