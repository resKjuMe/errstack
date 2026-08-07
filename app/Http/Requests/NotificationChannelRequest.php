<?php

namespace App\Http\Requests;

use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Notifications\ChannelField;
use App\Notifications\ChannelRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Einrichten und Ändern eines Benachrichtigungswegs.
 *
 * Welche Felder unter `config` erlaubt und nötig sind, weiß nur der Treiber
 * des gewählten Kanals — die Prüfregeln kommen deshalb von dort. Der Kanal-Typ
 * steht nur beim Anlegen zur Wahl: aus einem Slack-Kanal wird kein
 * Teams-Kanal, dafür legt man einen neuen an.
 */
class NotificationChannelRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(ChannelRegistry $registry): array
    {
        $channel = $this->channel();
        $organization = $this->organization();
        $type = $this->type();

        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('notification_channels')
                    ->where('organization_id', $organization->id)
                    ->ignore($channel?->id),
            ],
            'is_active' => ['boolean'],
        ];

        if ($channel === null) {
            $rules['type'] = ['required', 'string', Rule::in($registry->keys())];
        }

        if (! $registry->has($type)) {
            return $rules;
        }

        foreach ($registry->driver($type)->rules() as $key => $rule) {
            $rules["config.{$key}"] = $rule;
        }

        // Beim Ändern bleiben leer gelassene Zugangsdaten-Felder, wie sie
        // sind — sie werden gar nicht erst übertragen (prepareForValidation).
        // Ohne `sometimes` würde die Prüfung sie trotzdem einfordern und jede
        // Umbenennung verlangte das Token erneut.
        if ($channel !== null) {
            foreach ($registry->driver($type)->fields() as $field) {
                if (! $field->secret || $this->has("config.{$field->key}")) {
                    continue;
                }

                $existing = $rules["config.{$field->key}"] ?? [];
                $rules["config.{$field->key}"] = [
                    'sometimes',
                    ...is_string($existing) ? explode('|', $existing) : $existing,
                ];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('validation.messages.channel_name_taken'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = ['name' => __('validation.attributes.name'), 'type' => __('validation.attributes.type')];

        foreach ($this->fields() as $field) {
            $attributes["config.{$field->key}"] = $field->label;
        }

        return $attributes;
    }

    /**
     * Die geprüften Kanal-Einstellungen, ergänzt um die Zugangsdaten, die
     * unverändert bleiben sollen.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->validated('config', []);
        $channel = $this->channel();
        $existing = $channel === null ? [] : $channel->config;

        foreach ($this->fields() as $field) {
            if ($field->secret && ! array_key_exists($field->key, $config) && array_key_exists($field->key, $existing)) {
                $config[$field->key] = $existing[$field->key];
            }
        }

        return $config;
    }

    public function organization(): Organization
    {
        $channel = $this->channel();
        $organization = $channel === null ? $this->route('organization') : $channel->organization;

        assert($organization instanceof Organization);

        return $organization;
    }

    public function type(): string
    {
        $channel = $this->channel();

        return $channel === null ? (string) $this->input('type') : $channel->type;
    }

    /**
     * Zeilenweise Eingaben (Empfängerlisten) kommen als Text an und werden zur
     * Liste; leer gelassene Zugangsdaten-Felder fliegen ganz heraus, damit sie
     * den gespeicherten Wert nicht mit einer leeren Zeichenkette überschreiben.
     */
    protected function prepareForValidation(): void
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->input('config', []);

        foreach ($this->fields() as $field) {
            $value = $config[$field->key] ?? null;

            if ($field->type === 'list' && is_string($value)) {
                $config[$field->key] = array_values(array_filter(array_map(
                    trim(...),
                    preg_split('/[\r\n,;]+/', $value) ?: [],
                )));
            }

            // Auch `null` zählt als „leer gelassen": das Formular schickt ein
            // leeres Feld als leere Zeichenkette, und die kommt hier dank
            // ConvertEmptyStringsToNull schon als null an.
            if ($field->secret && ($value === null || (is_string($value) && trim($value) === ''))) {
                unset($config[$field->key]);
            }
        }

        $this->merge(['config' => $config]);
    }

    /**
     * Felder des gewählten Kanals — leer, solange kein gültiger Kanal gewählt
     * ist (dann meldet das die Prüfung von `type`).
     *
     * @return list<ChannelField>
     */
    private function fields(): array
    {
        $registry = app(ChannelRegistry::class);
        $type = $this->type();

        return $registry->has($type) ? $registry->driver($type)->fields() : [];
    }

    private function channel(): ?NotificationChannel
    {
        $channel = $this->route('channel');

        return $channel instanceof NotificationChannel ? $channel : null;
    }
}
