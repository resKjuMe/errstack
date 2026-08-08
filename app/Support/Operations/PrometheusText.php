<?php

namespace App\Support\Operations;

/**
 * Baut das Textformat, das Prometheus (und alles, was es nachahmt) einliest.
 *
 * Bewusst von Hand und ohne Bibliothek: das Format ist eine Zeile je Messwert,
 * davor je Kennzahl eine Zeile `# HELP` und eine `# TYPE`. Eine Abhängigkeit
 * dafür einzuziehen, hieße ein Client-Paket mit Sammelregistern und
 * Speicheranbindung mitzuschleppen, um am Ende `printf` zu benutzen.
 *
 * Was hier nicht steht, ist Absicht: keine Zeitstempel (Prometheus setzt seinen
 * eigenen beim Abholen, und ein selbst gesetzter führt bei Uhrensprüngen zu
 * abgelehnten Reihen) und keine Histogramme (die Anwendung hält keine
 * Sammelregister über Prozessgrenzen hinweg — was sie liefern kann, sind
 * Momentaufnahmen, und die sind Gauges).
 *
 * @see https://prometheus.io/docs/instrumenting/exposition_formats/
 */
final class PrometheusText
{
    /** @var list<string> */
    private array $lines = [];

    /** @var array<string, true> */
    private array $declared = [];

    public function __construct(private readonly string $prefix) {}

    /**
     * Eine Momentaufnahme: ein Wert, der steigen und fallen kann.
     *
     * `null` schreibt keine Zeile. Das ist der Unterschied zwischen „der Wert
     * ist null" und „diese Installation kann den Wert nicht ermitteln" — eine
     * Warteschlange, deren Anbindung nicht zählen kann, darf nicht als leer
     * erscheinen.
     *
     * @param  array<string, string>  $labels
     */
    public function gauge(string $name, string $help, int|float|null $value, array $labels = []): self
    {
        if ($value === null) {
            return $this;
        }

        $metric = $this->prefix.'_'.$name;

        if (! isset($this->declared[$metric])) {
            $this->lines[] = '# HELP '.$metric.' '.self::escapeHelp($help);
            $this->lines[] = '# TYPE '.$metric.' gauge';
            $this->declared[$metric] = true;
        }

        $this->lines[] = $metric.self::labels($labels).' '.self::value($value);

        return $this;
    }

    public function render(): string
    {
        // Abschließender Zeilenumbruch: das Format verlangt ihn, und manche
        // Einleser verwerfen sonst die letzte Zeile.
        return implode("\n", $this->lines)."\n";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $parts = [];

        foreach ($labels as $key => $value) {
            $parts[] = $key.'="'.self::escapeLabel($value).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    private static function value(int|float $value): string
    {
        // Ganze Zahlen ohne Nachkommastellen, alles andere mit fester
        // Genauigkeit: `1.0E+25` in wissenschaftlicher Schreibweise liest
        // Prometheus zwar, aber die Zeile ist für Menschen unbrauchbar.
        return is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }

    private static function escapeHelp(string $help): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $help);
    }

    private static function escapeLabel(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
