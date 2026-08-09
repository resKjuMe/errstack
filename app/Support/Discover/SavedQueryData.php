<?php

namespace App\Support\Discover;

use App\Enums\WidgetType;
use App\Models\Dashboard;
use App\Models\Organization;
use App\Models\SavedQuery;
use App\Models\User;
use App\Policies\SavedQueryPolicy;
use App\Support\Filters\GlobalFilter;

/**
 * Die Leiste der gespeicherten Auswertungen über der freien Auswertung: was da
 * ist, wie man es öffnet und unter welchen Adressen man es verwaltet.
 *
 * **Eine gespeicherte Auswertung ist hier ein Link und kein Knopf.** Sie besteht
 * aus einer Frage und einem Ausschnitt, und beides steht in der Adresszeile —
 * die Adresse baut {@see self::href()}. Damit ist jede gespeicherte Auswertung
 * weitergebbar, der Verlauf zurück funktioniert, und die Oberfläche muss nicht
 * wissen, wie ein Zeitraum in die Leiste kommt.
 *
 * **Der gespeicherte Ausschnitt gewinnt gegen den aktuellen — beim Öffnen, und
 * nur dann.** Das ist der Vertrag dieser Aufgabe: der Zeitraum wird mitgespeichert
 * und ist danach überschreibbar. Er landet deshalb in der Adresse und nicht in
 * einer Einstellung: wer die Leiste danach anfasst, ändert ihn, ohne dass die
 * gespeicherte Auswertung davon etwas mitbekommt. Was die Auswertung **nicht**
 * gespeichert hat, bleibt stehen, wie es ist — eine ohne Umgebung reißt niemanden
 * aus seiner Umgebung heraus.
 */
final class SavedQueryData
{
    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     dashboards: list<array{id: int, name: string}>,
     *     widgetTypes: list<array{value: string, label: string, series: bool}>,
     *     storeHref: string,
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

        /** @var list<SavedQuery> $saved */
        $saved = SavedQuery::query()
            ->visibleTo($viewer, $organization)
            // Der Name des Erstellers steht an jeder fremden Auswertung; ohne
            // ihn wäre sie eine Ansicht ohne Herkunft.
            ->with('user:id,name')
            ->orderBy('name')
            ->get()
            ->all();

        $own = 0;

        foreach ($saved as $query) {
            if ($query->user_id === $viewer->id) {
                $own++;
            }
        }

        return [
            'items' => array_map(
                static fn (SavedQuery $query): array => self::item($query, $filter, $viewer),
                $saved,
            ),
            'dashboards' => self::dashboards($viewer, $organization),
            'widgetTypes' => WidgetType::options(),
            'storeHref' => route('discover.saved.store'),
            'limit' => SavedQuery::MAX_PER_USER,
            'remaining' => max(0, SavedQuery::MAX_PER_USER - $own),
        ];
    }

    /**
     * Die Adresse, unter der diese Auswertung aufgeht.
     *
     * Sie enthält beides: die Frage und den Ausschnitt. Wo die Auswertung
     * nichts gespeichert hat, steht der Wert der Leiste — deshalb wird auf
     * deren Formularwerte aufgesetzt und nicht auf ein leeres Feld.
     */
    public static function href(SavedQuery $saved, GlobalFilter $filter): string
    {
        $query = $saved->discoverQuery();
        $filters = $saved->savedFilters();

        $values = $filter->formValues();

        if ($filters->period !== null) {
            $values['period'] = $filters->period->value;
            $values['from'] = $filters->from ?? '';
            $values['to'] = $filters->to ?? '';
        }

        if ($filters->environment !== null) {
            $values['environment'] = $filters->environment;
        }

        if ($filters->projectSlug !== null) {
            $values['projects'] = [$filters->projectSlug];
        }

        return route('discover.index', [
            ...$values,
            'dataset' => $query->dataset->value,
            'fields' => $query->fields,
            'metrics' => $query->metrics,
            'q' => $query->search,
            'sort' => $query->sort,
            'limit' => $query->limit,
            // Ohne gespeicherte Schrittweite bleibt das Feld leer und die Seite
            // schlägt die zum Zeitraum passende vor — eine mitgeschriebene
            // wäre die von damals, zu einem Zeitraum, den es hier nicht mehr
            // gibt.
            'interval' => $query->interval ?? '',
        ]);
    }

    /**
     * Eine Zeile der Leiste.
     *
     * `own` entscheidet in der Oberfläche darüber, ob die Verwaltungsknöpfe
     * erscheinen. Es ist bewusst eine Angabe des Servers und keine Rechnung im
     * Browser: die Regel steht in {@see SavedQueryPolicy}, und eine zweite
     * Fassung davon in JavaScript wäre die, die als Erste veraltet.
     *
     * @return array<string, mixed>
     */
    private static function item(SavedQuery $saved, GlobalFilter $filter, User $viewer): array
    {
        $query = $saved->discoverQuery();
        $filters = $saved->savedFilters();

        return [
            'id' => $saved->id,
            'name' => $saved->name,
            'description' => $saved->description,
            'shared' => $saved->shared,
            'own' => $saved->user_id === $viewer->id,
            'ownerName' => $saved->user?->name,
            'href' => self::href($saved, $filter),

            // Der Zustand, wie ihn das Verwaltungsformular in seinen Feldern
            // führt — dieselben Namen wie in der Adresszeile, damit „speichern"
            // und „ändern" dasselbe abschicken.
            'values' => [
                'dataset' => $query->dataset->value,
                'fields' => $query->fields,
                'metrics' => $query->metrics,
                'q' => $query->search,
                'sort' => $query->sort,
                'limit' => $query->limit,
                'interval' => $query->interval ?? '',
                'period' => $filters->period?->value ?? '',
                'from' => $filters->from ?? '',
                'to' => $filters->to ?? '',
                'environment' => $filters->environment ?? '',
                'projects' => $filters->projectSlug === null ? [] : [$filters->projectSlug],
            ],

            'updateHref' => route('discover.saved.update', $saved),
            'destroyHref' => route('discover.saved.destroy', $saved),
            'duplicateHref' => route('discover.saved.duplicate', $saved),
            'widgetHref' => route('discover.saved.widget', $saved),
        ];
    }

    /**
     * Die Dashboards, auf die sich übernehmen lässt — die eigenen.
     *
     * Nur die eigenen und nicht alle sichtbaren: eine Kachel anzulegen ist eine
     * Änderung am Dashboard, und die darf nur sein Ersteller
     * ({@see App\Policies\DashboardPolicy}). Ein freigegebenes Dashboard eines
     * Kollegen in der Auswahl wäre ein Eintrag, der beim Anklicken abgewiesen
     * wird.
     *
     * @return list<array{id: int, name: string}>
     */
    private static function dashboards(User $viewer, Organization $organization): array
    {
        return Dashboard::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $viewer->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Dashboard $dashboard): array => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Ohne Organisation gibt es weder Auswertungen noch Dashboards — die Leiste
     * bleibt leer, statt mit Adressen zu antworten, die ins Leere zeigen.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     dashboards: list<array{id: int, name: string}>,
     *     widgetTypes: list<array{value: string, label: string, series: bool}>,
     *     storeHref: string,
     *     limit: int,
     *     remaining: int,
     * }
     */
    private static function empty(): array
    {
        return [
            'items' => [],
            'dashboards' => [],
            'widgetTypes' => WidgetType::options(),
            'storeHref' => route('discover.saved.store'),
            'limit' => SavedQuery::MAX_PER_USER,
            'remaining' => 0,
        ];
    }
}
