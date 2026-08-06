<?php

namespace App\Notifications;

/**
 * Ein Eingabefeld der Kanal-Einrichtung. Der Treiber beschreibt damit, was er
 * braucht; die Oberfläche baut daraus das Formular, ohne die Kanäle zu kennen.
 *
 * `secret` markiert Zugangsdaten (Webhook-URLs, Token): sie gehen nie an den
 * Browser zurück. Beim Bearbeiten bleibt das Feld leer und ein leerer Wert
 * bedeutet „unverändert lassen" — sonst müsste man bei jeder Umbenennung das
 * Token neu eintippen.
 */
final readonly class ChannelField
{
    /**
     * @param  'text'|'url'|'password'|'list'  $type  Art des Eingabefelds in der Oberfläche.
     *                                                `list` ist ein mehrzeiliges Feld, dessen Zeilen
     *                                                als Liste ankommen (z. B. Empfängeradressen).
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
        public bool $required = true,
        public bool $secret = false,
        public ?string $hint = null,
        public ?string $placeholder = null,
    ) {}

    /**
     * Zugangsdaten-Feld — der Regelfall bei Webhook-URLs und Token.
     */
    public static function secret(string $key, string $label, ?string $hint = null, ?string $placeholder = null): self
    {
        return new self(
            key: $key,
            label: $label,
            type: 'password',
            secret: true,
            hint: $hint,
            placeholder: $placeholder,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'required' => $this->required,
            'secret' => $this->secret,
            'hint' => $this->hint,
            'placeholder' => $this->placeholder,
        ];
    }
}
