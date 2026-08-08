<?php

namespace App\Enums;

use App\Support\Operations\HealthCheck;

/**
 * Das Ergebnis einer einzelnen Zustandsprüfung — und, aus denselben Fällen
 * zusammengesetzt, das der ganzen Installation ({@see HealthCheck}).
 *
 * Nur zwei Fälle, und das ist eine Entscheidung: `/health` beantwortet die
 * Frage eines Ladeverteilers, und der kann mit „teilweise" nichts anfangen —
 * er schickt Anfragen hin oder nicht. Alles, was dazwischenliegt (ein
 * wachsender Rückstand, eine langsame Verarbeitung), ist eine Kennzahl und
 * keine Zustandsprüfung: eine beschäftigte Installation ist nicht kaputt, und
 * sie aus dem Verkehr zu ziehen nimmt ihr die letzten Arbeiter weg.
 */
enum HealthState: string
{
    /** Der Bestandteil antwortet wie erwartet. */
    case Ok = 'ok';

    /** Der Bestandteil antwortet nicht, falsch oder zu spät. */
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.health_state.'.$this->value);
    }

    public function isOk(): bool
    {
        return $this === self::Ok;
    }
}
