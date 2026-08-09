<?php

namespace App\Support\Discover;

use App\Support\Filters\GlobalFilter;
use App\Support\Performance\TransactionSearch;
use App\Support\Search\SearchQuery;

/**
 * Der Weg von einer Zeile der Auswertung zu den Ereignissen, aus denen sie
 * entstanden ist.
 *
 * **Der Link entsteht nur, wenn er dieselbe Menge trifft.** Eine Auswertung
 * gruppiert über Felder, die es in der Zielansicht geben muss — sonst führte der
 * Klick auf „Chrome 124: 812" zu einer Liste, in der 4.000 Fehler stehen, und
 * die Zahl daneben wäre nicht mehr zu erklären. Wo sich die Gruppe nicht
 * vollständig übersetzen lässt, gibt es deshalb **keinen** Link statt eines
 * ungefähren; die Oberfläche sagt an der Zeile, warum.
 *
 * **Die Fehlermeldungen sind der Regelfall**, und dort ist die Übersetzung
 * wörtlich: die Fehlerliste spricht dieselbe Suchsprache (S4), die auch die
 * Auswertung filtert. Der Ausdruck der Zeile ist damit derselbe Text, den jemand
 * in die Suchzeile getippt hätte — und was die Fehlerliste an Feldern nicht
 * kennt, benennt sie dort selbst.
 *
 * Für die Antwortzeiten gibt es diese Wörtlichkeit nicht: die Leistungs-Übersicht
 * hat ihr eigenes, kleines Suchformat ({@see TransactionSearch})
 * mit genau einem Schlüsselwort. Übersetzbar sind deshalb `name` und `op`, und
 * auch nur diese beiden — jedes weitere Gruppierungsfeld nimmt der Zeile ihren
 * Link. Die Rückmeldungen haben gar keine Suche; dort führt der Weg zur Liste,
 * solange die Zeile nicht über ein Feld gruppiert, das sie nicht kennt.
 */
final class DiscoverDrilldown
{
    /**
     * Die Adresse zu den Ereignissen einer Zeile — oder `null`, wenn keine
     * Ansicht genau diese Menge zeigen kann.
     *
     * Das Projekt steht dabei ausdrücklich in der Adresse und nicht als „alle,
     * die gerade gewählt sind": die Auswertung hat über genau eines gerechnet,
     * und die Zielansicht soll genau dieses zeigen — auch wenn die Filterleiste
     * daneben keine Einschränkung führt.
     *
     * @param  list<string>  $groupBy
     * @param  array<string, string|null>  $groups
     */
    public static function href(
        Dataset $dataset,
        array $groupBy,
        array $groups,
        string $search,
        GlobalFilter $filter,
        string $projectSlug,
    ): ?string {
        // Ein fehlender Gruppenwert ist keine Bedingung, die sich schreiben
        // lässt: „Browser unbekannt" ist in der Suchsprache nicht dasselbe wie
        // `browser:""`. Solche Zeilen bleiben ohne Link.
        foreach ($groupBy as $field) {
            if (($groups[$field] ?? null) === null) {
                return null;
            }
        }

        $base = ['projects' => [$projectSlug]] + $filter->formValues();

        return match ($dataset) {
            Dataset::Errors => self::issues($groupBy, $groups, $search, $base),
            Dataset::Transactions, Dataset::TransactionWindows => self::performance($groupBy, $groups, $base),
            Dataset::UserReports => self::feedback($groupBy, $base),
        };
    }

    /**
     * @param  list<string>  $groupBy
     * @param  array<string, string|null>  $groups
     * @param  array<string, mixed>  $base
     */
    private static function issues(array $groupBy, array $groups, string $search, array $base): string
    {
        $terms = array_map(
            static fn (string $field): string => SearchQuery::term($field, (string) $groups[$field]),
            $groupBy,
        );

        if (trim($search) !== '') {
            array_unshift($terms, trim($search));
        }

        return route('issues.index', ['q' => implode(' ', $terms)] + $base);
    }

    /**
     * @param  list<string>  $groupBy
     * @param  array<string, string|null>  $groups
     * @param  array<string, mixed>  $base
     */
    private static function performance(array $groupBy, array $groups, array $base): ?string
    {
        $terms = [];

        foreach ($groupBy as $field) {
            $value = (string) $groups[$field];

            // `op:` ist das einzige Schlüsselwort der Leistungs-Suche; der Name
            // ist dort Freitext und trifft, wo er vorkommt — bei einem Namen, der
            // die Zeile bereits benennt, ist das die Liste mit dieser einen
            // Transaktion darin. Alles andere hat keine Entsprechung, und dann
            // gibt es lieber keinen Link.
            $term = match ($field) {
                'op' => 'op:'.$value,
                'name' => $value,
                default => null,
            };

            if ($term === null) {
                return null;
            }

            $terms[] = $term;
        }

        return route('performance.index', ['q' => implode(' ', $terms)] + $base);
    }

    /**
     * @param  list<string>  $groupBy
     * @param  array<string, mixed>  $base
     */
    private static function feedback(array $groupBy, array $base): ?string
    {
        // Die Rückmeldungsliste kennt keine Suche: eine gruppierte Zeile ließe
        // sich dort nicht wiederfinden. Ohne Gruppierung ist die Zeile die
        // gesamte Auswahl — und die zeigt die Liste genau.
        return $groupBy === [] ? route('feedback.index', $base) : null;
    }
}
