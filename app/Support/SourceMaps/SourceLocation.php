<?php

namespace App\Support\SourceMaps;

/**
 * Eine Stelle im geschriebenen Quelltext, wie sie aus der Karte kommt.
 *
 * Datei, Zeile, Spalte — und der Bezeichner, sofern die Karte einen kennt. Der
 * `sourceIndex` gehört nicht zur Auskunft, sondern ist der Schlüssel, mit dem
 * der eingebettete Quelltext derselben Karte geholt wird
 * ({@see SourceMap::context()}); ihn erneut zu suchen hieße, die Datei über
 * ihren Namen wiederzufinden, den es in einer Karte doppelt geben darf.
 */
final class SourceLocation
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly int $column,
        public readonly ?string $function,
        public readonly int $sourceIndex,
    ) {}

    /**
     * Stammt die Stelle aus fremdem Code?
     *
     * Die Frage entscheidet über `in_app` am zurückübersetzten Rahmen — und
     * damit darüber, welche Rahmen die Anzeige offen zeigt. Am minimierten
     * Rahmen ist sie nicht zu beantworten: dort ist alles eine Datei, und genau
     * deshalb ist ein minimierter Stacktrace so mühsam zu lesen.
     *
     * Entschieden wird an der Herkunft im Quellbaum, nicht am Namen der Datei:
     * `node_modules` und die Ausgabeordner der Bauwerkzeuge sind fremd, alles
     * andere ist eigener Code.
     */
    public function isInApp(): bool
    {
        $path = strtolower($this->file);

        foreach (['node_modules/', 'bower_components/', '/webpack/bootstrap', 'webpack/runtime/'] as $marker) {
            if (str_contains($path, $marker)) {
                return false;
            }
        }

        return true;
    }
}
