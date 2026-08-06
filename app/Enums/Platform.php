<?php

namespace App\Enums;

/**
 * Plattform eines Projekts. Sie bestimmt das Symbol in der Projektliste und
 * steuert ab Phase P1 den Einrichtungs-Hinweis (welches SDK, welcher
 * Beispiel-Code). Unbekannte Technik landet unter „Sonstige".
 */
enum Platform: string
{
    case Php = 'php';
    case JavaScript = 'javascript';
    case Python = 'python';
    case Node = 'node';
    case Java = 'java';
    case Go = 'go';
    case Ruby = 'ruby';
    case DotNet = 'dotnet';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Php => 'PHP',
            self::JavaScript => 'JavaScript',
            self::Python => 'Python',
            self::Node => 'Node.js',
            self::Java => 'Java',
            self::Go => 'Go',
            self::Ruby => 'Ruby',
            self::DotNet => '.NET',
            self::Other => 'Sonstige',
        };
    }

    /**
     * Kürzel für das Symbol in der Projektliste — höchstens drei Zeichen,
     * damit es in die kleine Kachel passt.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Php => 'PHP',
            self::JavaScript => 'JS',
            self::Python => 'PY',
            self::Node => 'NOD',
            self::Java => 'JV',
            self::Go => 'GO',
            self::Ruby => 'RB',
            self::DotNet => '.NET',
            self::Other => '···',
        };
    }

    /**
     * Auswahlfeld der Oberfläche.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $platform) => ['value' => $platform->value, 'label' => $platform->label()],
            self::cases(),
        );
    }
}
