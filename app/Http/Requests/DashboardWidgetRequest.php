<?php

namespace App\Http\Requests;

use App\Enums\FilterPeriod;
use App\Enums\WidgetType;
use App\Models\DashboardWidget;
use App\Models\Environment;
use App\Support\Dashboards\DashboardLayout;
use App\Support\Dashboards\WidgetOverrides;
use App\Support\Dashboards\WidgetQuery;
use App\Support\Discover\Dataset;
use App\Support\Discover\Interval;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Eine Kachel als Eingabe: Überschrift, Darstellungsart, Abfrage, eigene Sicht
 * auf die Filterleiste und Lage im Raster.
 *
 * **Die Abfrage kommt in denselben Feldern wie in der Adresszeile der freien
 * Auswertung** (`dataset`, `fields[]`, `metrics[]`, `q`, `sort`, `limit`,
 * `interval`). Das ist der Grund, warum sich eine Auswertung ohne Übersetzung zu
 * einer Kachel machen lässt: es sind dieselben Namen mit derselben Bedeutung.
 *
 * **Geprüft wird die Form, nicht die Rechenbarkeit.** Ob `p95(duration)` in
 * dieser Quelle geht, weiß der Motor; er sagt es an der Kachel, wenn sie
 * gerechnet wird ({@see App\Support\Dashboards\WidgetData}). Eine Kachel, die
 * sich nicht speichern lässt, weil die Quelle die Kennzahl nicht kann, wäre eine
 * Sackgasse in dem Moment, in dem jemand gerade ausprobiert — dieselbe Haltung
 * wie bei der gespeicherten Suche.
 */
class DashboardWidgetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.DashboardWidget::TITLE_LIMIT],
            'type' => ['required', Rule::enum(WidgetType::class)],

            'dataset' => ['nullable', Rule::enum(Dataset::class)],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', 'max:255'],
            'metrics' => ['nullable', 'array'],
            'metrics.*' => ['string', 'max:255'],
            'q' => ['nullable', 'string', 'max:'.DiscoverRequest::SEARCH_LIMIT],
            'sort' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1'],
            'interval' => ['nullable', Rule::in(Interval::options())],

            // Die eigene Sicht der Kachel auf die Filterleiste. Alles davon darf
            // fehlen — dann gilt die Leiste.
            'overrides' => ['nullable', 'array'],
            'overrides.period' => ['nullable', Rule::enum(FilterPeriod::class)],
            'overrides.from' => ['nullable', 'date', 'required_if:overrides.period,custom'],
            'overrides.to' => ['nullable', 'date', 'after_or_equal:overrides.from', 'required_if:overrides.period,custom'],
            'overrides.environment' => ['nullable', 'string', 'max:'.Environment::NAME_LIMIT],
            'overrides.project' => ['nullable', 'string', 'max:255'],

            // Die Lage. Sie darf fehlen: eine neue Kachel landet dann unter
            // allem, was schon da ist.
            'x' => ['nullable', 'integer', 'min:0', 'max:'.(DashboardLayout::COLUMNS - 1)],
            'y' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:'.DashboardLayout::MIN_WIDTH, 'max:'.DashboardLayout::COLUMNS],
            'height' => ['nullable', 'integer', 'min:'.DashboardLayout::MIN_HEIGHT, 'max:'.DashboardLayout::MAX_HEIGHT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'overrides.to.after_or_equal' => __('validation.messages.range_reversed'),
            'overrides.from.required_if' => __('validation.messages.range_from_missing'),
            'overrides.to.required_if' => __('validation.messages.range_to_missing'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');

        if (is_string($title)) {
            $this->merge(['title' => trim($title)]);
        }
    }

    public function title(): string
    {
        return trim((string) $this->validated('title'));
    }

    public function type(): WidgetType
    {
        return WidgetType::from((string) $this->validated('type'));
    }

    public function widgetQuery(): WidgetQuery
    {
        $dataset = $this->validated('dataset');
        $interval = $this->validated('interval');

        return WidgetQuery::make(
            dataset: is_string($dataset) && $dataset !== '' ? Dataset::from($dataset) : Dataset::Errors,
            fields: is_array($this->validated('fields')) ? $this->validated('fields') : [],
            metrics: is_array($this->validated('metrics')) ? $this->validated('metrics') : [],
            search: (string) ($this->validated('q') ?? ''),
            sort: (string) ($this->validated('sort') ?? ''),
            limit: is_numeric($this->validated('limit')) ? (int) $this->validated('limit') : DiscoverRequest::DEFAULT_LIMIT,
            interval: is_string($interval) ? $interval : null,
        );
    }

    public function overrides(): WidgetOverrides
    {
        $overrides = $this->validated('overrides');

        return WidgetOverrides::fromArray(is_array($overrides) ? $overrides : []);
    }

    /**
     * Die gewünschte Lage — oder `null`, wo keine angegeben ist.
     *
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public function placement(): ?array
    {
        $width = $this->validated('width');
        $height = $this->validated('height');
        $x = $this->validated('x');
        $y = $this->validated('y');

        if ($x === null && $y === null && $width === null && $height === null) {
            return null;
        }

        return DashboardLayout::normalize(
            (int) ($x ?? 0),
            (int) ($y ?? 0),
            (int) ($width ?? DashboardLayout::DEFAULT_WIDTH),
            (int) ($height ?? DashboardLayout::DEFAULT_HEIGHT),
        );
    }
}
