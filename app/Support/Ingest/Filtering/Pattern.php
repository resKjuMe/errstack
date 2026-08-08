<?php

namespace App\Support\Ingest\Filtering;

/**
 * Der Vergleich eines Eintrags mit einem Wert aus der Meldung.
 *
 * Platzhalter statt regulärer Ausdrücke, und das ist eine bewusste
 * Einschränkung: die Sperrlisten schreiben die Leute, die die Fehlerliste
 * ansehen, nicht die, die die Anwendung ausliefern. Ein regulärer Ausdruck an
 * dieser Stelle wäre ein Werkzeug, mit dem sich versehentlich alles
 * wegfiltern lässt — und der Fehler fiele erst auf, wenn Meldungen fehlen.
 *
 * `*` steht für beliebig viele Zeichen, verglichen wird auf den **ganzen**
 * Wert. Wer einen Teiltreffer will, schreibt `*ResizeObserver*`. Herum
 * geschrieben wäre das bequemer und an genau einer Stelle gefährlich: eine
 * Release-Sperre `1.2` würde dann auch `21.2.5` treffen.
 */
final class Pattern
{
    /**
     * Trifft der Eintrag auf einen der Werte zu?
     *
     * @param  list<string>  $subjects
     */
    public static function matchesAny(string $expression, array $subjects): bool
    {
        $regex = self::compile($expression);

        if ($regex === null) {
            return false;
        }

        foreach ($subjects as $subject) {
            if (@preg_match($regex, $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Der Eintrag als regulärer Ausdruck, oder `null`, wenn er unbrauchbar ist.
     *
     * Ein leerer Eintrag ergibt `null` und nicht etwa ein Muster, das auf den
     * leeren Text passt: sonst würde eine versehentlich leer gespeicherte Zeile
     * jede Meldung ohne Fehlertext aussortieren.
     */
    private static function compile(string $expression): ?string
    {
        $expression = trim($expression);

        if ($expression === '') {
            return null;
        }

        // Drei Kennungen, und jede verhindert einen eigenen stillen Fehlschlag:
        //
        //   i — Groß- und Kleinschreibung ist bei einem Fehlertext nicht die
        //       Sache dessen, der die Sperre schreibt.
        //   s — `.` trifft auch den Zeilenumbruch. Ohne das ginge `*Foo*` an
        //       jedem mehrzeiligen Ausnahmetext vorbei, und mehrzeilig sind sie
        //       bei PHP, Python und Java der Regelfall.
        //   D — `$` heißt Ende und nicht „Ende oder davor ein Umbruch". Sonst
        //       träfe eine Sperre ohne Platzhalter auch den Text mit
        //       abschließendem Umbruch, obwohl gerade der ganze Wert gemeint ist.
        //
        // Kein `u`: die Rohdaten einer abstürzenden Anwendung sind nicht
        // verlässlich UTF-8, und mit dieser Kennung gäbe `preg_match` auf einer
        // kaputten Bytefolge stillschweigend „kein Treffer" zurück — der Filter
        // würde also genau dort nicht greifen, wo am wenigsten hinzusehen ist.
        return '/^'.str_replace('\*', '.*', preg_quote($expression, '/')).'$/isD';
    }
}
