<?php

namespace App\Support\Discover;

use App\Models\Transaction;
use App\Support\Performance\DurationHistogram;

/**
 * Die Rechenart einer Kennzahl — was mit den Zeilen einer Gruppe passiert.
 *
 * **Zwei Sorten, und der Unterschied entscheidet über die Lücken.** Eine *Anzahl*
 * ist auch dann eine Aussage, wenn nichts vorliegt: keine Zeile heißt null. Ein
 * *Mittelwert*, ein *Perzentil* oder ein *Anteil* ist es nicht — aus null
 * Messungen folgt keine Antwortzeit. Deshalb wird eine fehlende Stunde in einer
 * Zeitreihe bei einer Anzahl mit `0` und sonst mit `null` gefüllt
 * ({@see self::isCount()}); dieselbe Unterscheidung, an der bei den Alarmen (A3)
 * hängt, ob ein stilles Zeitfenster Entwarnung gibt.
 *
 * **Perzentile kommen aus der Verteilung, nicht aus einer Sortierung.** Weder
 * MySQL noch SQLite können ein Perzentil ohne Fensterfunktion über eine ganze
 * Gruppe rechnen; beide können Häufigkeiten summieren. Gerechnet wird deshalb
 * über die logarithmischen Klassen aus {@see DurationHistogram}
 * — dieselbe Rechnung, die die Antwortzeit-Übersicht (PF2) und die Alarme (A3)
 * benutzen, mit derselben bekannten Klassenbreite als Ungenauigkeit.
 */
enum Aggregate: string
{
    /** Wie viele Zeilen. */
    case Count = 'count';

    /** Wie viele verschiedene Werte eines Feldes. */
    case CountUnique = 'count_unique';

    /** Die Summe eines Feldes. */
    case Sum = 'sum';

    case Avg = 'avg';

    case Min = 'min';

    case Max = 'max';

    case P50 = 'p50';

    case P75 = 'p75';

    case P95 = 'p95';

    case P99 = 'p99';

    /**
     * Die Zufriedenheit mit den Antwortzeiten, zwischen 0 und 1.
     *
     * Die Rechnung von Sentry: zufrieden zählt ganz, geduldig (bis zum
     * Vierfachen der Schwelle) zur Hälfte, alles darüber nicht. Die Schwelle
     * steht in der Konfiguration und nicht hier — sie hängt von der überwachten
     * Anwendung ab ({@see Transaction::miserable()}).
     */
    case Apdex = 'apdex';

    /** Anteil der gescheiterten Aufrufe, in Prozent. */
    case FailureRate = 'failure_rate';

    /**
     * Braucht die Rechenart ein Feld, über das sie läuft?
     *
     * `count()` zählt Zeilen, `apdex()` und `failure_rate()` beziehen sich auf
     * die eine Größe, die ihre Quelle dafür vorsieht (Dauer bzw. Ausgang) — ein
     * Feld anzugeben wäre dort eine Wahl, die es nicht gibt.
     */
    public function needsField(): bool
    {
        return match ($this) {
            self::Count, self::Apdex, self::FailureRate => false,
            default => true,
        };
    }

    /**
     * Ist das Ergebnis eine Anzahl — und damit auch bei null Zeilen eine
     * Aussage?
     */
    public function isCount(): bool
    {
        return match ($this) {
            self::Count, self::CountUnique, self::Sum => true,
            default => false,
        };
    }

    /**
     * Das Perzentil hinter der Rechenart, sofern sie eines ist.
     */
    public function percentile(): ?float
    {
        return match ($this) {
            self::P50 => 0.5,
            self::P75 => 0.75,
            self::P95 => 0.95,
            self::P99 => 0.99,
            default => null,
        };
    }

    /**
     * Die Einheit, in der die Zahl dasteht — sie gehört an jede Anzeige, weil
     * „größer als 500" ohne sie nicht zu lesen ist.
     */
    public function unit(): string
    {
        return $this === self::FailureRate ? '%' : '';
    }

    public function label(): string
    {
        return __('enums.discover_aggregate.'.$this->value);
    }
}
