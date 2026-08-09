<?php

namespace App\Support\Dashboards;

use App\Enums\WidgetType;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Organization;
use App\Models\User;
use App\Support\Discover\Dataset;

/**
 * Vorlagen: fertige Dashboards für die drei Fragen, mit denen fast jeder anfängt.
 *
 * **Eine Vorlage ist ein Startpunkt, keine Bindung.** Angelegt werden ganz
 * normale Kacheln; wer danach etwas ändert, ändert sein Dashboard und nicht die
 * Vorlage — und eine geänderte Vorlage rührt die bereits angelegten nicht an.
 * Der Herkunftsvermerk (`template`) sagt nur, woher es kam.
 *
 * **Die Kacheln stehen hier als Abfragen und nicht als Bilder.** Eine Vorlage
 * ist damit dasselbe wie ein selbst gebautes Dashboard, nur vorbereitet: sie
 * kann nichts, was man nicht auch von Hand hinbekäme. Was hier steht, muss
 * deshalb auch der Motor können — eine Vorlage mit einem Feld, das es nicht
 * gibt, wäre eine Kachel, die von Anfang an ihren Fehler zeigt.
 *
 * **Die Weltkarten-Kachel liest die Antwortzeiten und nicht die Fehler.** Beide
 * Quellen kennen ein Land; bei den Messungen ist es eine Spalte und bei den
 * Meldungen ein Feld im JSON, das nur gefüllt ist, wenn das SDK es mitschickt.
 * Für eine Vorlage, die auf Anhieb etwas zeigen soll, ist die Spalte die
 * ehrlichere Wahl.
 */
final class DashboardTemplates
{
    /**
     * Die Vorlagen mit ihren Kacheln.
     *
     * @return array<string, list<array{title: string, type: WidgetType, query: WidgetQuery, x: int, y: int, width: int, height: int}>>
     */
    public static function all(): array
    {
        return [
            'errors' => self::errors(),
            'performance' => self::performance(),
            'release_health' => self::releaseHealth(),
        ];
    }

    /**
     * Was die Oberfläche zur Auswahl stellt.
     *
     * @return list<array{value: string, name: string, description: string, widgets: int}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $key => $widgets) {
            $options[] = [
                'value' => $key,
                'name' => __('dashboards.templates.'.$key.'.name'),
                'description' => __('dashboards.templates.'.$key.'.description'),
                'widgets' => count($widgets),
            ];
        }

        return $options;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * Legt ein Dashboard aus einer Vorlage an — samt Kacheln, in einem Zug.
     */
    public static function create(string $key, Organization $organization, User $user, ?string $name = null): Dashboard
    {
        $dashboard = Dashboard::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'name' => $name ?? __('dashboards.templates.'.$key.'.name'),
            'description' => __('dashboards.templates.'.$key.'.description'),
            'shared' => false,
            'template' => $key,
        ]);

        foreach (self::all()[$key] as $widget) {
            DashboardWidget::query()->create([
                'dashboard_id' => $dashboard->id,
                'title' => $widget['title'],
                'type' => $widget['type'],
                'query' => $widget['query']->toArray(),
                'overrides' => null,
                'x' => $widget['x'],
                'y' => $widget['y'],
                'width' => $widget['width'],
                'height' => $widget['height'],
            ]);
        }

        return $dashboard;
    }

    /**
     * Fehlerübersicht: wie viele, welche, wen trifft es, woher.
     *
     * @return list<array{title: string, type: WidgetType, query: WidgetQuery, x: int, y: int, width: int, height: int}>
     */
    private static function errors(): array
    {
        return [
            self::widget(
                __('dashboards.templates.errors.widgets.volume'),
                WidgetType::Bar,
                WidgetQuery::make(Dataset::Errors, metrics: ['count()']),
                0, 0, 8, 4,
            ),
            self::widget(
                __('dashboards.templates.errors.widgets.total'),
                WidgetType::BigNumber,
                WidgetQuery::make(Dataset::Errors, metrics: ['count()']),
                8, 0, 4, 2,
            ),
            self::widget(
                __('dashboards.templates.errors.widgets.users'),
                WidgetType::BigNumber,
                WidgetQuery::make(Dataset::Errors, metrics: ['count_unique(user.id)']),
                8, 2, 4, 2,
            ),
            self::widget(
                __('dashboards.templates.errors.widgets.top'),
                WidgetType::Table,
                WidgetQuery::make(Dataset::Errors, fields: ['title'], metrics: ['count()'], limit: 10),
                0, 4, 6, 4,
            ),
            self::widget(
                __('dashboards.templates.errors.widgets.browsers'),
                WidgetType::Table,
                WidgetQuery::make(Dataset::Errors, fields: ['browser.name'], metrics: ['count()'], limit: 8),
                6, 4, 6, 4,
            ),
        ];
    }

    /**
     * Performance: wie schnell, wie viel, wo klemmt es, wo sitzen die Wartenden.
     *
     * @return list<array{title: string, type: WidgetType, query: WidgetQuery, x: int, y: int, width: int, height: int}>
     */
    private static function performance(): array
    {
        return [
            self::widget(
                __('dashboards.templates.performance.widgets.p95'),
                WidgetType::Line,
                WidgetQuery::make(Dataset::TransactionWindows, metrics: ['p95(duration)']),
                0, 0, 8, 4,
            ),
            self::widget(
                __('dashboards.templates.performance.widgets.apdex'),
                WidgetType::BigNumber,
                // Die Zufriedenheit rechnen die vorberechneten Fenster nicht —
                // ihnen fehlt die Aufteilung in zufrieden/geduldig/unzufrieden.
                // Deshalb hier die Messungen, als einzige Kachel der Vorlage.
                WidgetQuery::make(Dataset::Transactions, metrics: ['apdex()']),
                8, 0, 4, 2,
            ),
            self::widget(
                __('dashboards.templates.performance.widgets.failure_rate'),
                WidgetType::BigNumber,
                WidgetQuery::make(Dataset::TransactionWindows, metrics: ['failure_rate()']),
                8, 2, 4, 2,
            ),
            self::widget(
                __('dashboards.templates.performance.widgets.slowest'),
                WidgetType::Table,
                WidgetQuery::make(
                    Dataset::TransactionWindows,
                    fields: ['name'],
                    metrics: ['p95(duration)', 'count()'],
                    limit: 10,
                ),
                0, 4, 7, 4,
            ),
            self::widget(
                __('dashboards.templates.performance.widgets.countries'),
                WidgetType::WorldMap,
                WidgetQuery::make(
                    Dataset::Transactions,
                    fields: ['country'],
                    metrics: ['p95(duration)'],
                    limit: 30,
                ),
                7, 4, 5, 4,
            ),
        ];
    }

    /**
     * Release-Gesundheit: was die neue Fassung mitbringt.
     *
     * @return list<array{title: string, type: WidgetType, query: WidgetQuery, x: int, y: int, width: int, height: int}>
     */
    private static function releaseHealth(): array
    {
        return [
            self::widget(
                __('dashboards.templates.release_health.widgets.by_release'),
                WidgetType::Area,
                WidgetQuery::make(
                    Dataset::Errors,
                    fields: ['release'],
                    metrics: ['count()'],
                    limit: 5,
                ),
                0, 0, 8, 4,
            ),
            self::widget(
                __('dashboards.templates.release_health.widgets.releases'),
                WidgetType::BigNumber,
                WidgetQuery::make(Dataset::Errors, metrics: ['count_unique(release)']),
                8, 0, 4, 2,
            ),
            self::widget(
                __('dashboards.templates.release_health.widgets.fatal'),
                WidgetType::BigNumber,
                WidgetQuery::make(Dataset::Errors, search: 'level:fatal', metrics: ['count()']),
                8, 2, 4, 2,
            ),
            self::widget(
                __('dashboards.templates.release_health.widgets.table'),
                WidgetType::Table,
                WidgetQuery::make(
                    Dataset::Errors,
                    fields: ['release'],
                    metrics: ['count()', 'count_unique(user.id)'],
                    limit: 10,
                ),
                0, 4, 12, 4,
            ),
        ];
    }

    /**
     * @return array{title: string, type: WidgetType, query: WidgetQuery, x: int, y: int, width: int, height: int}
     */
    private static function widget(
        string $title,
        WidgetType $type,
        WidgetQuery $query,
        int $x,
        int $y,
        int $width,
        int $height,
    ): array {
        return ['title' => $title, 'type' => $type, 'query' => $query, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }
}
