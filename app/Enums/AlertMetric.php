<?php

namespace App\Enums;

/**
 * Die Kennzahl, auf die ein Schwellwert-Alarm schaut.
 *
 * Jede steht für eine Frage, die man an ein laufendes System stellt: „kommen
 * mehr Fehler als sonst?", „scheitern mehr Aufrufe?", „dauert es länger?".
 * Gerechnet werden sie **ausschließlich aus den vorberechneten Zeitreihen**
 * (I6, PF1) und aus den Ereignissen selbst — nie aus einer Auswertung, die mit
 * der Datenmenge wächst.
 *
 * **Zwei Sorten, und der Unterschied ist wichtig.** Eine *Anzahl* ist auch dann
 * eine Aussage, wenn nichts vorliegt: keine Messung heißt null. Ein *Anteil*
 * oder ein *Perzentil* ist es nicht — aus null Messungen folgt keine
 * Fehlerquote und keine Antwortzeit. Genau daran hängt das Verhalten bei Lücken
 * in den Daten ({@see isCount()}): eine Anzahl löst auf, ein Anteil hält den
 * Zustand.
 *
 * **Was hier (noch) fehlt und warum.** Die Aufgabenbeschreibung nennt auch Web
 * Vitals und die Crash-Free-Rate. Beide haben ihre eigene Aufgabe (PF5 bzw. R7)
 * und damit noch keine Datenquelle — eine Kennzahl anzubieten, hinter der nichts
 * steht, wäre ein Alarm, der nie auslöst und dabei aussieht, als überwache er
 * etwas. Sie kommen dazu, sobald ihre Zeitreihe existiert; an dieser Aufzählung
 * ist dafür eine Zeile zu ergänzen.
 */
enum AlertMetric: string
{
    /** Wie viele Fehlermeldungen im Zeitfenster eingegangen sind. */
    case ErrorCount = 'error_count';

    /** Wie viele Aufrufe im Zeitfenster gemessen wurden (hochgerechnet). */
    case TransactionThroughput = 'transaction_throughput';

    /** Anteil der gescheiterten Aufrufe, in Prozent. */
    case TransactionFailureRate = 'transaction_failure_rate';

    /** Mittlere Antwortzeit in Millisekunden. */
    case TransactionDurationAvg = 'transaction_duration_avg';

    /** Antwortzeit, die die Hälfte der Aufrufe unterschreitet (ms). */
    case TransactionDurationP50 = 'transaction_duration_p50';

    /** Antwortzeit, die 95 % der Aufrufe unterschreiten (ms). */
    case TransactionDurationP95 = 'transaction_duration_p95';

    /** Antwortzeit, die 99 % der Aufrufe unterschreiten (ms). */
    case TransactionDurationP99 = 'transaction_duration_p99';

    public function label(): string
    {
        return __('enums.alert_metric.'.$this->value);
    }

    /**
     * Die Einheit, in der die Zahl dasteht — sie gehört an jede Schwelle, weil
     * „größer als 500" ohne sie nicht zu lesen ist.
     */
    public function unit(): string
    {
        return match ($this) {
            self::TransactionFailureRate => '%',
            self::TransactionDurationAvg,
            self::TransactionDurationP50,
            self::TransactionDurationP95,
            self::TransactionDurationP99 => 'ms',
            default => '',
        };
    }

    /**
     * Ist die Kennzahl eine Anzahl?
     *
     * Eine Anzahl ist bei fehlenden Daten **null** und damit eine gültige
     * Aussage; alles andere ist dann unbekannt. Das ist der ganze Unterschied,
     * aber er entscheidet darüber, ob ein Alarm nach einem stillen Zeitfenster
     * Entwarnung gibt oder stehen bleibt.
     */
    public function isCount(): bool
    {
        return match ($this) {
            self::ErrorCount, self::TransactionThroughput => true,
            default => false,
        };
    }

    /**
     * Liest die Kennzahl aus den Antwortzeit-Messungen statt aus den Fehlern?
     *
     * Nur solche Alarme lassen sich auf einen einzelnen Vorgang einschränken —
     * einen Transaktionsnamen gibt es bei einer Fehlermeldung nicht.
     */
    public function isTransactionMetric(): bool
    {
        return $this !== self::ErrorCount;
    }

    /**
     * Das Perzentil hinter der Kennzahl, sofern sie eines ist.
     */
    public function percentile(): ?float
    {
        return match ($this) {
            self::TransactionDurationP50 => 0.5,
            self::TransactionDurationP95 => 0.95,
            self::TransactionDurationP99 => 0.99,
            default => null,
        };
    }

    /**
     * Wie viele Nachkommastellen die Zahl in der Anzeige bekommt.
     */
    public function decimals(): int
    {
        return match ($this) {
            self::TransactionFailureRate => 1,
            default => 0,
        };
    }

    /**
     * @return list<array{value: string, label: string, unit: string, transaction: bool, count: bool}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'unit' => $case->unit(),
                'transaction' => $case->isTransactionMetric(),
                'count' => $case->isCount(),
            ],
            self::cases(),
        );
    }
}
