<?php

namespace App\Support\Tags;

use App\Support\Filters\GlobalFilter;
use App\Support\Search\SearchQuery;
use Closure;

/**
 * Hängt an eine Merkmal-Verteilung die Verweise, die sie anklickbar machen.
 *
 * Getrennt von {@see TagFacets}, weil die beiden verschiedene Dinge wissen: die
 * Verteilung weiß, wie oft etwas vorkam, und soll dafür keine Routen kennen —
 * sie wird auch von Stellen gelesen, die gar keine Seite bauen. Die Verweise
 * dagegen hängen an der Adresszeile: sie tragen die gewählten Projekte mit,
 * damit ein Klick nicht nebenbei die Filterleiste zurücksetzt.
 *
 * **Ein Klick auf einen Wert führt in die Fehlerliste**, eingeschränkt auf genau
 * diesen Wert. Das ist die eigentliche Zusage der Aufgabe: die Verteilung ist
 * keine Anzeige, sondern der Einstieg in die Frage „und welche Fehler sind
 * das?".
 *
 * **Der Klick schreibt einen Suchausdruck** (`?q=browser:"Chrome 124"`) und
 * keinen eigenen Parameter. Damit steht die Einschränkung dort, wo man sie
 * ändern kann — im Suchfeld —, und lässt sich um weitere Bedingungen ergänzen,
 * statt in einer Marke zu enden, die man nur an- oder abschalten kann.
 */
final class TagLinks
{
    /**
     * Verweise an eine Übersicht hängen.
     *
     * @param  list<array<string, mixed>>  $facets
     * @param  Closure(string): string  $keyHref  Adresse der Detailseite eines Merkmals
     * @return list<array<string, mixed>>
     */
    public static function decorate(array $facets, GlobalFilter $filter, Closure $keyHref): array
    {
        return array_map(
            fn (array $facet): array => self::decorateOne($facet, $filter) + [
                'href' => $keyHref((string) $facet['key']),
            ],
            $facets,
        );
    }

    /**
     * Verweise an die Werte **eines** Merkmals hängen.
     *
     * @param  array<string, mixed>  $facet
     * @return array<string, mixed>
     */
    public static function decorateOne(array $facet, GlobalFilter $filter): array
    {
        $key = (string) $facet['key'];

        /** @var list<array<string, mixed>> $values */
        $values = $facet['values'];

        $facet['values'] = array_map(
            fn (array $value): array => $value + [
                'href' => self::issuesHref($filter, $key, (string) $value['value']),
            ],
            $values,
        );

        return $facet;
    }

    /**
     * Die Fehlerliste, eingeschränkt auf einen Merkmalswert.
     *
     * Die Werte der Filterleiste fahren mit: wer die Verteilung eines Projekts
     * ansieht und auf „Chrome" klickt, will die Fehler **dieses** Projekts sehen
     * und nicht die aller.
     */
    public static function issuesHref(GlobalFilter $filter, string $key, string $value): string
    {
        return route('issues.index', $filter->formValues() + [
            'q' => SearchQuery::term($key, $value),
        ]);
    }
}
