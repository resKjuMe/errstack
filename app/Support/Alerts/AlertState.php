<?php

namespace App\Support\Alerts;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;

/**
 * Die Zustandsmaschine eines Alarms: aus altem Zustand und neuem Wert wird der
 * neue Zustand.
 *
 * Bewusst eine reine Rechnung ohne Datenbank, ohne Uhr und ohne Benachrichtigung.
 * Sie ist der Teil, an dem sich Fehler verstecken — eine Hysterese, die in eine
 * Richtung klemmt, fällt im Betrieb erst auf, wenn ein Alarm nie wieder
 * aufgeht. Getrennt geschrieben lässt sie sich Fall für Fall prüfen.
 *
 * **Die Auflösungsschwelle ist der einzige Grund, warum das mehr als ein
 * Vergleich ist.** Ohne sie folgt der Zustand direkt aus den Schwellen. Mit ihr
 * hängt er zusätzlich davon ab, wo man herkommt: ein Wert, der um die Schwelle
 * pendelt, soll nicht abwechselnd Alarm und Entwarnung schicken, sondern erst
 * dann aufgelöst werden, wenn er die Grenze wirklich hinter sich lässt.
 */
final class AlertState
{
    /**
     * Der Zustand, in dem der Alarm nach dieser Ablesung steht.
     *
     * @param  AlertStatus  $current  der Zustand vor der Ablesung
     * @param  float  $value  der gemessene Wert (bzw. die Veränderung in Prozent)
     * @param  float|null  $warning  Warnschwelle, sofern gesetzt
     * @param  float|null  $critical  kritische Schwelle, sofern gesetzt
     * @param  float|null  $resolve  Auflösungsschwelle, sofern gesetzt
     */
    public static function next(
        AlertStatus $current,
        float $value,
        AlertDirection $direction,
        ?float $warning,
        ?float $critical,
        ?float $resolve,
    ): AlertStatus {
        $raw = self::fromThresholds($value, $direction, $warning, $critical);

        // Ein Alarm, der ohnehin ruhig ist, oder eine Ablesung, die weiterhin
        // eine Schwelle verletzt: dann entscheiden allein die Schwellen.
        if ($current === AlertStatus::Ok || $raw !== AlertStatus::Ok || $resolve === null) {
            return $raw;
        }

        if ($direction->clears($value, $resolve)) {
            return AlertStatus::Ok;
        }

        // Noch nicht aufgelöst. Gehalten wird auf der **niedrigsten**
        // eingestellten Stufe — ein Alarm, der nur eine kritische Schwelle hat,
        // darf hier nicht in eine Warnung rutschen, die niemand eingerichtet
        // hat.
        return $warning === null ? AlertStatus::Critical : AlertStatus::Warning;
    }

    /**
     * Der Zustand, der sich allein aus den Schwellen ergibt.
     *
     * Die kritische zuerst: sie ist die schärfere Aussage, und bei einem Wert,
     * der beide verletzt, gilt sie.
     */
    private static function fromThresholds(
        float $value,
        AlertDirection $direction,
        ?float $warning,
        ?float $critical,
    ): AlertStatus {
        if ($critical !== null && $direction->breaches($value, $critical)) {
            return AlertStatus::Critical;
        }

        if ($warning !== null && $direction->breaches($value, $warning)) {
            return AlertStatus::Warning;
        }

        return AlertStatus::Ok;
    }
}
