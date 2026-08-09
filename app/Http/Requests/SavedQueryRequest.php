<?php

namespace App\Http\Requests;

use App\Enums\FilterPeriod;
use App\Models\Environment;
use App\Models\SavedQuery;
use App\Support\CurrentOrganization;
use App\Support\Dashboards\WidgetOverrides;
use App\Support\Dashboards\WidgetQuery;
use App\Support\Discover\Dataset;
use App\Support\Discover\Interval;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Eine gespeicherte Auswertung als Eingabe: Name, Beschreibung, Freigabe — dazu
 * die Frage und ihr Ausschnitt.
 *
 * **Die Frage kommt in denselben Feldern wie in der Adresszeile der freien
 * Auswertung** (`dataset`, `fields[]`, `metrics[]`, `q`, `sort`, `limit`,
 * `interval`), der Ausschnitt in denen der Filterleiste (`period`, `from`,
 * `to`, `environment`, `projects[]`). Beides ist Absicht: die Oberfläche
 * schickt schlicht das ab, was ohnehin in der Adresse steht, und es gibt keine
 * dritte Schreibweise, die zwischen Seite, Kachel und Gespeichertem
 * übersetzt — dieselbe Zusage wie bei {@see DashboardWidgetRequest}.
 *
 * **Geprüft wird die Form, nicht die Rechenbarkeit.** Ob `p95(duration)` in
 * dieser Quelle geht, weiß der Motor und sagt es an der Auswertung. Ein
 * Speichern, das mit „so nicht" abbricht, während die Seite daneben denselben
 * Ausdruck klaglos annimmt und erklärt, wäre zweierlei Maß — und zwar genau in
 * dem Moment, in dem jemand etwas ausprobiert.
 */
class SavedQueryRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:'.SavedQuery::NAME_LIMIT,
                // Zweimal derselbe Name im eigenen Bestand wäre in der Leiste
                // zweimal dasselbe Wort — und welche der beiden man gerade
                // öffnet, sähe man nicht. Die Datenbank hält denselben
                // Schlüssel; hier steht er, damit daraus eine Meldung am Feld
                // wird und kein abgebrochener Aufruf.
                Rule::unique('saved_queries', 'name')
                    ->where('organization_id', $this->organizationId())
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('savedQuery')),
            ],
            'description' => ['nullable', 'string', 'max:'.SavedQuery::DESCRIPTION_LIMIT],
            'shared' => ['nullable', 'boolean'],

            // Die Frage.
            'dataset' => ['nullable', Rule::enum(Dataset::class)],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', 'max:255'],
            'metrics' => ['nullable', 'array'],
            'metrics.*' => ['string', 'max:255'],
            'q' => ['nullable', 'string', 'max:'.DiscoverRequest::SEARCH_LIMIT],
            'sort' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1'],
            'interval' => ['nullable', Rule::in(Interval::options())],

            // Der Ausschnitt. Alles davon darf fehlen — dann geht die
            // Auswertung mit der Leiste auf, so wie sie gerade steht.
            'period' => ['nullable', Rule::enum(FilterPeriod::class)],
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:period,custom'],
            'environment' => ['nullable', 'string', 'max:'.Environment::NAME_LIMIT],
            'projects' => ['nullable', 'array'],
            'projects.*' => ['string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => __('validation.messages.range_reversed'),
            'from.required_if' => __('validation.messages.range_from_missing'),
            'to.required_if' => __('validation.messages.range_to_missing'),
        ];
    }

    /**
     * Der Name wird mit umschließenden Leerzeichen geprüft, wie er ankommt —
     * gespeichert wird er ohne. Damit „ Offen " und „Offen" als derselbe Name
     * gelten, wird vor der Prüfung beschnitten.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function description(): string
    {
        return trim((string) ($this->validated('description') ?? ''));
    }

    public function shared(): bool
    {
        return (bool) $this->validated('shared');
    }

    /**
     * Die Frage.
     *
     * Nicht `query()`: so heißt am Request bereits der Zugriff auf die
     * Adresszeile ({@see Request::query()}), und ihn zu überschreiben wäre ein
     * anderer Rückgabetyp unter demselben Namen — dieselbe Falle wie in
     * {@see DiscoverRequest}.
     */
    public function discoverQuery(): WidgetQuery
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

    /**
     * Der Ausschnitt: Zeitraum, Umgebung, Projekt.
     *
     * Das Projekt kommt als Liste an, weil die Filterleiste eine führt —
     * gespeichert wird das erste. Eine Auswertung rechnet über genau ein
     * Projekt (die Begründung steht in {@see DiscoverController}), und eine
     * Liste in der Datenbank wäre die Zusage, dass es auch mehrere sein
     * könnten.
     */
    public function savedFilters(): WidgetOverrides
    {
        $projects = $this->validated('projects');
        $projects = is_array($projects) ? array_values(array_filter($projects, is_string(...))) : [];

        $period = $this->validated('period');

        return WidgetOverrides::make(
            period: is_string($period) ? FilterPeriod::tryFrom($period) : null,
            from: self::text($this->validated('from')),
            to: self::text($this->validated('to')),
            environment: self::text($this->validated('environment')),
            projectSlug: $projects[0] ?? null,
        );
    }

    /**
     * Die Organisation, in der der Name eindeutig sein muss.
     *
     * Beim Ändern ist es die der Auswertung und nicht die aus der Adresse: wer
     * die Organisation gewechselt hat und einen alten Reiter absendet, soll
     * seine Auswertung nicht in eine andere schieben.
     */
    private function organizationId(): ?int
    {
        $saved = $this->route('savedQuery');

        if ($saved instanceof SavedQuery) {
            return $saved->organization_id;
        }

        return CurrentOrganization::for($this)?->id;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
