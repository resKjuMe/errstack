<?php

namespace App\Enums;

/**
 * Das Verfahren, mit dem eine Erreichbarkeits-Prüfung anfragt.
 *
 * `GET` ist die Vorgabe und deckt fast alles ab. `HEAD` ist die sparsame
 * Fassung für große Seiten — es überträgt keinen Rumpf und schließt damit die
 * Inhaltsprüfung aus; darauf weist die Oberfläche hin, statt es zu verbieten.
 * Die schreibenden Verfahren gibt es für Ziele, die nur auf einen echten Aufruf
 * antworten — etwa eine Prüf-Schnittstelle, die eine Nutzlast erwartet.
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Options = 'OPTIONS';

    /**
     * Überträgt dieses Verfahren einen Rumpf, den man durchsuchen könnte?
     */
    public function hasResponseBody(): bool
    {
        return $this !== self::Head;
    }

    /**
     * Darf eine Nutzlast mitgeschickt werden?
     */
    public function acceptsRequestBody(): bool
    {
        return match ($this) {
            self::Post, self::Put, self::Patch => true,
            default => false,
        };
    }

    public function label(): string
    {
        return $this->value;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
