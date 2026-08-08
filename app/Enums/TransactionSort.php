<?php

namespace App\Enums;

/**
 * Die Kennzahlen der Performance-Übersicht — zugleich ihre Spalten und die
 * erlaubten Werte von `?sort=`.
 *
 * Eine Aufzählung und keine Liste im Controller, weil dieselbe Menge an drei
 * Stellen gebraucht wird: die Prüfung der Adresszeile, die Spaltenköpfe der
 * Tabelle und das Vergleichen zweier Zeilen. Fielen die auseinander, gäbe es
 * einen Spaltenkopf, der sich anklicken lässt und nichts tut — oder einen
 * Sortierschlüssel, den die Oberfläche nie erzeugt und der trotzdem gepflegt
 * werden will.
 *
 * Die Reihenfolge der Fälle ist die Reihenfolge der Spalten
 * ({@see self::columns()}).
 */
enum TransactionSort: string
{
    case Name = 'name';
    case Throughput = 'throughput';
    case P50 = 'p50';
    case P75 = 'p75';
    case P95 = 'p95';
    case P99 = 'p99';
    case Average = 'avg';
    case FailureRate = 'failureRate';
    case Users = 'users';
    case UserMisery = 'userMisery';
    case Count = 'count';
    case Trend = 'trend';

    /**
     * Voreinstellung: p95, absteigend — „sortiert nach dem größten Problem".
     *
     * Nicht der Mittelwert, obwohl der geläufiger ist: ein Mittelwert versteckt
     * genau die Ausreißer, wegen derer jemand diese Seite öffnet. Und nicht p99,
     * weil das bei wenigen Messungen an einem einzigen Aufruf hängt.
     */
    public static function default(): self
    {
        return self::P95;
    }

    public function label(): string
    {
        return __('performance.columns.'.$this->value);
    }

    /**
     * Ist die Spalte eine Zahl? Entscheidet über die Ausrichtung in der Tabelle
     * und darüber, in welche Richtung ein frisch angeklickter Spaltenkopf
     * sortiert: bei Zahlen will man das Größte zuerst sehen, bei Namen das
     * Alphabet.
     */
    public function numeric(): bool
    {
        return $this !== self::Name;
    }

    /**
     * Spalten zweiter Ordnung — sie stehen in der Tabelle, verschwinden aber in
     * schmalen Ansichten.
     *
     * Die Auswahl folgt der Aufgabe: p50 und p95 sind die Kennzahlen, wegen
     * derer die Seite existiert, p75 und p99 die Verfeinerung daneben. Der
     * Mittelwert und die Zahl der Messungen sind Einordnung — die Messungen
     * sagen, wie belastbar die Verteilung ist, und das ist eine Rückfrage und
     * keine erste Auskunft.
     */
    public function secondary(): bool
    {
        return match ($this) {
            self::P75, self::P99, self::Average, self::Count => true,
            default => false,
        };
    }

    /**
     * Die Spalten der Tabelle in ihrer Reihenfolge, wie die Oberfläche sie
     * braucht. Sie kommen vom Server, damit die anklickbaren Spaltenköpfe genau
     * die Schlüssel tragen, die der Server auch annimmt.
     *
     * @return list<array{key: string, label: string, numeric: bool, secondary: bool}>
     */
    public static function columns(): array
    {
        return array_map(
            fn (self $sort): array => [
                'key' => $sort->value,
                'label' => $sort->label(),
                'numeric' => $sort->numeric(),
                'secondary' => $sort->secondary(),
            ],
            self::cases(),
        );
    }

    /**
     * Die erlaubten Werte für `Rule::in()`.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $sort): string => $sort->value, self::cases());
    }

    /**
     * Der Wert aus der Adresszeile, oder die Voreinstellung.
     *
     * Ein unbekannter Schlüssel kommt hier im Regelfall gar nicht an: die
     * Prüfung der Anfrage weist ihn vorher ab, damit ein Tippfehler in der
     * Adresszeile nicht stillschweigend etwas anderes sortiert, als dasteht. Die
     * Ausweichlösung hier gilt dem häufigeren Fall — es steht schlicht nichts da.
     */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? self::tryFrom($value) ?? self::default() : self::default();
    }
}
