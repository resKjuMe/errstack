<?php

namespace App\Http\Requests;

use App\Enums\IssueAlertAction;
use App\Enums\IssueAlertCondition;
use App\Enums\IssueAlertFilter;
use App\Enums\IssueAlertMatch;
use App\Models\IssueAlertRule;
use App\Models\Organization;
use App\Support\IssueAlerts\RuleAction;
use App\Support\IssueAlerts\RuleCondition;
use App\Support\IssueAlerts\RuleFilter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Eine Alarm-Regel als Eingabe.
 *
 * Bedingungen, Filter und Aktionen kommen als Listen und werden hier **Eintrag
 * für Eintrag** geprüft, nicht bloß als „ist ein Array". Das ist der Grund,
 * warum die Auswertung im Aufnahmeweg nichts abfangen muss: was dort ankommt,
 * lässt sich deuten. Ein Rumpf, der das nicht tut, wird hier abgewiesen und
 * nicht später stillschweigend übergangen.
 */
class IssueAlertRuleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.IssueAlertRule::NAME_LIMIT],
            'condition_match' => ['required', Rule::enum(IssueAlertMatch::class)],
            'filter_match' => ['required', Rule::enum(IssueAlertMatch::class)],

            'conditions' => ['required', 'array', 'min:1', 'max:10'],
            'conditions.*.type' => ['required', Rule::enum(IssueAlertCondition::class)],
            'conditions.*.value' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'conditions.*.window' => ['nullable', 'integer', 'min:1'],

            'filters' => ['present', 'array', 'max:10'],
            'filters.*.type' => ['required', Rule::enum(IssueAlertFilter::class)],
            'filters.*.comparison' => ['required', 'string'],
            'filters.*.value' => ['nullable', 'string', 'max:200'],
            'filters.*.key' => ['nullable', 'string', 'max:200'],

            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::enum(IssueAlertAction::class)],
            'actions.*.channel_id' => ['nullable', 'integer'],

            'frequency_minutes' => [
                'required',
                'integer',
                'min:'.IssueAlertRule::MIN_FREQUENCY_MINUTES,
                'max:'.IssueAlertRule::MAX_FREQUENCY_MINUTES,
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                // Erst wenn die Form stimmt, lohnt der Blick auf den Inhalt:
                // sonst hinge an einem fehlenden Feld eine zweite, verwirrende
                // Meldung über einen Wert, den es gar nicht gibt.
                return;
            }

            $this->checkConditions($validator);
            $this->checkFilters($validator);
            $this->checkChannels($validator);
        });
    }

    /**
     * Zahl und Zeitfenster gehören zu manchen Bedingungen und zu anderen nicht.
     *
     * Eine fehlende Angabe fiele sonst erst im Betrieb auf — als Regel, die bei
     * „öfter als 0-mal in 0 Minuten" bei jedem Ereignis meldet.
     */
    private function checkConditions(Validator $validator): void
    {
        foreach ($this->listOf('conditions') as $index => $raw) {
            $type = IssueAlertCondition::tryFrom((string) ($raw['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            if ($type->hasValue() && (int) ($raw['value'] ?? 0) <= 0) {
                $validator->errors()->add("conditions.{$index}.value", __('issue_alerts.validation.value_required'));
            }

            $unit = $type->windowUnit();

            if ($unit === null) {
                continue;
            }

            $window = (int) ($raw['window'] ?? 0);

            if ($window < 1 || $window > $unit->max()) {
                $validator->errors()->add("conditions.{$index}.window", __('issue_alerts.validation.window_range', [
                    'max' => $unit->max(),
                ]));
            }
        }
    }

    /**
     * Der Vergleich muss zum Filter passen, und ein Merkmalsfilter braucht
     * seinen Merkmalsnamen.
     *
     * „Alter enthält Chrome" ließe sich abspeichern und würde beim Auswerten
     * einfach nie zutreffen — eine Regel, die dasteht und nichts tut, ist die
     * schlechteste aller Rückmeldungen.
     */
    private function checkFilters(Validator $validator): void
    {
        foreach ($this->listOf('filters') as $index => $raw) {
            $filter = RuleFilter::fromArray($raw);

            if ($filter === null) {
                $validator->errors()->add("filters.{$index}.comparison", __('issue_alerts.validation.comparison_invalid'));

                continue;
            }

            if ($filter->type->hasKey() && ($filter->key === null || $filter->key === '')) {
                $validator->errors()->add("filters.{$index}.key", __('issue_alerts.validation.key_required'));
            }

            if ($filter->value === '') {
                $validator->errors()->add("filters.{$index}.value", __('issue_alerts.validation.filter_value_required'));

                continue;
            }

            if ($filter->type->isNumeric() && ! is_numeric($filter->value)) {
                $validator->errors()->add("filters.{$index}.value", __('issue_alerts.validation.filter_value_numeric'));
            }
        }
    }

    /**
     * Ein benannter Kanal muss zu dieser Organisation gehören.
     *
     * Ohne diese Prüfung wäre die Kennung eines fremden Kanals ein Weg, sich
     * Meldungen aus einem Projekt schicken zu lassen, das einem nicht gehört.
     */
    private function checkChannels(Validator $validator): void
    {
        $organization = $this->route('organization');
        $available = $organization instanceof Organization
            ? $organization->notificationChannels()->pluck('id')->all()
            : [];

        foreach ($this->listOf('actions') as $index => $raw) {
            $channelId = $raw['channel_id'] ?? null;

            if ($channelId === null || $channelId === '') {
                continue;
            }

            if (! in_array((int) $channelId, array_map('intval', $available), true)) {
                $validator->errors()->add("actions.{$index}.channel_id", __('issue_alerts.validation.channel_unknown'));
            }
        }
    }

    /**
     * Die Werte, wie das Modell sie erwartet — die drei Listen bereits durch
     * die Wertobjekte gefiltert und wieder eingepackt.
     *
     * Der Umweg über die Wertobjekte ist Absicht: gespeichert wird damit genau
     * das, was die Auswertung später herausliest, und kein Feld mehr. Ein
     * mitgeschicktes `window` an einer Bedingung ohne Zeitfenster landet so gar
     * nicht erst in der Zeile.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $validated = $this->validated();

        return [
            'name' => $validated['name'],
            'condition_match' => $validated['condition_match'],
            'filter_match' => $validated['filter_match'],
            'conditions' => $this->pack('conditions', RuleCondition::fromArray(...)),
            'filters' => $this->pack('filters', RuleFilter::fromArray(...)),
            'actions' => $this->pack('actions', RuleAction::fromArray(...)),
            'frequency_minutes' => (int) $validated['frequency_minutes'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }

    /**
     * Eine geprüfte Regel, wie die Vorschau sie braucht — ohne sie zu speichern.
     */
    public function draft(): IssueAlertRule
    {
        return new IssueAlertRule($this->values());
    }

    /**
     * @param  callable(array<string, mixed>): (RuleAction|RuleCondition|RuleFilter|null)  $parse
     * @return list<array<string, mixed>>
     */
    private function pack(string $key, callable $parse): array
    {
        $packed = [];

        foreach ($this->listOf($key) as $raw) {
            $value = $parse($raw);

            if ($value !== null) {
                $packed[] = $value->toArray();
            }
        }

        return $packed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listOf(string $key): array
    {
        $value = $this->input($key);

        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, is_array(...));
    }
}
