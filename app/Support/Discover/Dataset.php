<?php

namespace App\Support\Discover;

use App\Support\Discover\Datasets\ErrorFields;
use App\Support\Discover\Datasets\TransactionFields;
use App\Support\Discover\Datasets\TransactionWindowFields;
use App\Support\Discover\Datasets\UserReportFields;

/**
 * Die Datenquelle einer freien Auswertung — worüber gerechnet wird.
 *
 * Jede Quelle bringt ihre eigenen Felder mit ({@see DatasetFields}), aber
 * **dieselbe** Suchsprache (S4): `environment:production` bedeutet in jeder
 * Quelle dasselbe, und ein gespeicherter Ausdruck bleibt lesbar, wenn jemand die
 * Quelle wechselt.
 *
 * **Warum es die Transaktionen zweimal gibt.** {@see self::Transactions} liest
 * die einzelnen Messungen, {@see self::TransactionWindows} die daraus
 * vorberechneten Minuten-Fenster (PF1). Der Unterschied ist nicht Bequemlichkeit,
 * sondern die Grenze zwischen einer Abfrage, die mit der Datenmenge wächst, und
 * einer, die es nicht tut: „p95 je Seite über 30 Tage" ist aus den Fenstern eine
 * Handvoll Zeilen und aus den Messungen ein Durchlauf. Dafür trägt ein Fenster
 * keine Nutzer- und keine Browser-Dimension — wer danach gruppieren will, muss
 * die Messungen lesen. Die Wahl bleibt deshalb beim Aufrufer und wird nicht
 * geraten: eine Quelle, die sich hinter dem Rücken umschaltet, liefert je nach
 * Gruppierung verschiedene Zahlen für dieselbe Frage.
 *
 * **Sitzungen fehlen bewusst.** Die Aufgabenbeschreibung nennt sie als Quelle;
 * die Sitzungsdaten entstehen aber erst mit der Auslieferungs-Gesundheit (R7).
 * Eine Quelle anzubieten, hinter der keine Tabelle steht, wäre eine Auswertung,
 * die immer leer ist und dabei aussieht, als sei nichts passiert. Sie kommt dazu,
 * sobald es die Tabelle gibt: eine Zeile hier und eine Feldliste daneben.
 */
enum Dataset: string
{
    /** Die einzelnen Fehlermeldungen. */
    case Errors = 'errors';

    /** Die einzelnen Antwortzeit-Messungen. */
    case Transactions = 'transactions';

    /** Die vorberechneten Minuten-Fenster der Antwortzeiten. */
    case TransactionWindows = 'transaction_windows';

    /** Die Rückmeldungen von Nutzern. */
    case UserReports = 'user_reports';

    public function label(): string
    {
        return __('enums.discover_dataset.'.$this->value);
    }

    /**
     * Die Felder dieser Quelle.
     *
     * @param  string  $timezone  Zeitzone des Betrachters — ein Datum ohne
     *                            Uhrzeit meint seinen Tag und nicht den in UTC.
     */
    public function fields(string $timezone = 'UTC'): DatasetFields
    {
        return match ($this) {
            self::Errors => new ErrorFields($timezone),
            self::Transactions => new TransactionFields($timezone),
            self::TransactionWindows => new TransactionWindowFields($timezone),
            self::UserReports => new UserReportFields($timezone),
        };
    }

    /**
     * Was die Oberfläche (D2) zur Auswahl stellt.
     *
     * @return list<array{value: string, label: string, group_by: list<string>, aggregate: list<string>}>
     */
    public static function options(string $timezone = 'UTC'): array
    {
        return array_map(
            static function (self $case) use ($timezone): array {
                $fields = $case->fields($timezone);

                return [
                    'value' => $case->value,
                    'label' => $case->label(),
                    'group_by' => $fields->groupable(),
                    'aggregate' => $fields->aggregatable(),
                ];
            },
            self::cases(),
        );
    }
}
