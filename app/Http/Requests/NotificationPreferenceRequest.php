<?php

namespace App\Http\Requests;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PreferenceScope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Eine Seite der Übersicht speichern: die Entscheidungen eines Nutzers für
 * genau einen Geltungsbereich.
 *
 * Der Bereich kommt als Kennung aus dem Formular (`global`,
 * `organization:12`, `project:34`) und wird hier gegen die tatsächlichen
 * Mitgliedschaften geprüft — sonst stellte jemand Vorlieben für ein Projekt
 * ein, das ihn nichts angeht.
 */
class NotificationPreferenceRequest extends FormRequest
{
    private ?PreferenceScope $scope = null;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $events = array_column(NotificationEventType::cases(), 'value');
        $transports = array_column(NotificationTransport::cases(), 'value');

        return [
            'scope' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->resolveScope() === null) {
                        $fail('Diesen Bereich gibt es nicht (mehr).');
                    }
                },
            ],
            // Die äußeren Schlüssel sind Anlässe, die inneren Wege. Was hier
            // nicht als eigene Regel steht, fällt bei `validated()` heraus:
            // sonst wüchse die Tabelle mit jedem Tippfehler um eine Zeile, die
            // nie jemand ausliest.
            'preferences' => ['present', 'array'],
        ] + $this->matrixRules($events, $transports);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['scope' => 'Bereich'];
    }

    /**
     * Die geprüften Entscheidungen als flache Liste.
     *
     * @return list<array{event: NotificationEventType, transport: NotificationTransport, choice: string}>
     */
    public function decisions(): array
    {
        /** @var array<string, mixed> $preferences */
        $preferences = $this->validated('preferences', []);
        $decisions = [];

        // `validated()` gibt den ganzen Teilbaum zurück, auch Schlüssel ohne
        // eigene Regel. Unbekanntes fliegt deshalb hier heraus statt in der
        // Prüfung — mit einer Fehlermeldung wäre niemandem geholfen, das
        // Formular schickt nur, was es kennt.
        foreach ($preferences as $eventKey => $transports) {
            $event = NotificationEventType::tryFrom((string) $eventKey);

            if ($event === null || ! is_array($transports)) {
                continue;
            }

            foreach ($transports as $transportKey => $choice) {
                $transport = NotificationTransport::tryFrom((string) $transportKey);

                if ($transport === null || ! in_array($choice, ['on', 'off', 'inherit'], true)) {
                    continue;
                }

                $decisions[] = [
                    'event' => $event,
                    'transport' => $transport,
                    'choice' => $choice,
                ];
            }
        }

        return $decisions;
    }

    public function scope(): PreferenceScope
    {
        $scope = $this->resolveScope();

        assert($scope !== null);

        return $scope;
    }

    /**
     * Ein eigener Regelsatz je Zelle statt eines Platzhalters über zwei
     * Ebenen: nur so meldet die Prüfung einen unbekannten Anlass auch als
     * solchen, statt ihn stillschweigend zu übergehen.
     *
     * @param  list<string>  $events
     * @param  list<string>  $transports
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function matrixRules(array $events, array $transports): array
    {
        $rules = [];

        foreach ($events as $event) {
            $rules["preferences.{$event}"] = ['sometimes', 'array'];

            foreach ($transports as $transport) {
                $rules["preferences.{$event}.{$transport}"] = ['sometimes', Rule::in(['on', 'off', 'inherit'])];
            }
        }

        return $rules;
    }

    /**
     * Kennung zu Bereich — und zwar nur, wenn der Nutzer dort auch etwas zu
     * suchen hat.
     */
    private function resolveScope(): ?PreferenceScope
    {
        if ($this->scope !== null) {
            return $this->scope;
        }

        $user = $this->user();
        $key = (string) $this->input('scope');

        if (! $user instanceof User) {
            return null;
        }

        if ($key === 'global') {
            return $this->scope = PreferenceScope::global();
        }

        [$kind, $id] = array_pad(explode(':', $key, 2), 2, null);

        if ($id === null || ! ctype_digit($id)) {
            return null;
        }

        return $this->scope = match ($kind) {
            'organization' => $this->organizationScope($user, (int) $id),
            'project' => $this->projectScope($user, (int) $id),
            default => null,
        };
    }

    private function organizationScope(User $user, int $id): ?PreferenceScope
    {
        $organization = Organization::query()->find($id);

        return $organization?->hasMember($user) === true
            ? PreferenceScope::forOrganization($organization)
            : null;
    }

    private function projectScope(User $user, int $id): ?PreferenceScope
    {
        $project = Project::query()->with('organization.memberships')->find($id);

        return $project?->organization->hasMember($user) === true
            ? PreferenceScope::forProject($project)
            : null;
    }
}
