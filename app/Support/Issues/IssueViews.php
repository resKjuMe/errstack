<?php

namespace App\Support\Issues;

use App\Enums\IssueSort;
use App\Http\Requests\IssueListRequest;
use App\Models\User;
use App\Support\Filters\GlobalFilter;
use App\Support\Search\SearchExpression;

/**
 * Die Standard-Ansichten der Fehlerliste — die Fragen, die man ohnehin jeden Tag
 * stellt, als Knopf statt als Tipparbeit.
 *
 * **Sie sind keine gespeicherten Suchen.** Es gibt sie ohne Datenbankzeile, in
 * jeder Organisation, für jedes Konto; sie lassen sich nicht umbenennen und
 * nicht löschen. Sie als Datensätze bei der Einrichtung anzulegen wäre die
 * naheliegende Abkürzung gewesen und hätte drei Probleme geerbt: sie fehlten in
 * jeder Organisation, die vor dieser Aufgabe angelegt wurde, sie liefen
 * auseinander, sobald jemand eine davon ändert, und eine Verbesserung an der
 * Formulierung („was heißt eigentlich neu?") erreichte nur noch die nächste
 * neue Organisation.
 *
 * **Jede Ansicht ist nichts weiter als ein Suchausdruck und eine Sortierung** —
 * genau das, was auch eine gespeicherte Suche ausmacht. Damit gibt es nur einen
 * Mechanismus: die Oberfläche behandelt beide gleich, und wer eine
 * Standard-Ansicht anpassen will, öffnet sie, ändert den Ausdruck und speichert
 * das Ergebnis unter eigenem Namen.
 *
 * **Was die Sprache kennt, die Daten aber noch nicht, steht trotzdem hier.**
 * „Wieder aufgetreten" (S8) gehört zur Suchsprache, aber die Daten dazu
 * entstehen erst in einer späteren Aufgabe ({@see IssueFields}); „Zur Prüfung"
 * und „Mir zugewiesen" sind seit S7 vollständig beantwortbar. Eine Ansicht zu
 * verschweigen, bis es ihre Daten gibt, wäre die
 * schlechtere Wahl: die Ansichten sind der Ort, an dem man sie erwartet, und
 * die Liste sagt ohnehin ausdrücklich, welche Begriffe sie noch nicht auswerten
 * konnte. Deshalb trägt jede Ansicht hier mit, ob sie heute vollständig
 * beantwortbar ist — die Oberfläche kann es dann dazuschreiben, statt eine
 * ungefilterte Liste als Antwort auszugeben.
 */
final class IssueViews
{
    /**
     * Die Ansichten in der Reihenfolge, in der sie erscheinen.
     *
     * Sie beginnt bei der Arbeitsliste und endet bei den Randfällen: „offen"
     * ist die Voreinstellung der Liste, „stummgeschaltet" schaut man selten an.
     *
     * `new_24h` ist die Ansicht, die im Sprachgebrauch „neu heute" heißt, und
     * sie ist bewusst ein rollendes Fenster von 24 Stunden und kein Kalendertag:
     * die Suchsprache kennt relative Zeitangaben (`-24h`) und keinen Begriff für
     * „heute". Ein fest eingetragenes Datum wäre morgen falsch.
     *
     * @var list<array{key: string, query: string, sort: IssueSort}>
     */
    private const VIEWS = [
        ['key' => 'unresolved', 'query' => 'is:unresolved', 'sort' => IssueSort::LastSeen],
        ['key' => 'for_review', 'query' => 'is:for_review', 'sort' => IssueSort::FirstSeen],
        ['key' => 'regressed', 'query' => 'is:regressed', 'sort' => IssueSort::LastSeen],
        ['key' => 'assigned', 'query' => 'assigned:me', 'sort' => IssueSort::LastSeen],
        ['key' => 'new_24h', 'query' => 'firstSeen:-24h', 'sort' => IssueSort::FirstSeen],
        ['key' => 'ignored', 'query' => 'is:ignored', 'sort' => IssueSort::LastSeen],
    ];

    /**
     * Die Ansichten, fertig für die Oberfläche — mit Adresse, unter der sie
     * aufgeht.
     *
     * @return list<array{key: string, name: string, query: string, sort: string, href: string, available: bool}>
     */
    public static function forFilter(GlobalFilter $filter, ?User $viewer = null): array
    {
        return array_map(
            static fn (array $view): array => [
                'key' => $view['key'],
                'name' => self::label($view['key']),
                'query' => $view['query'],
                'sort' => $view['sort']->value,
                'href' => self::href($filter, $view['query'], $view['sort']),
                'available' => self::isAvailable($view['query'], $filter, $viewer),
            ],
            self::VIEWS,
        );
    }

    /**
     * Die Adresse, unter der ein Suchausdruck mit einer Sortierung aufgeht.
     *
     * Auch der Weg, auf dem eine **gespeicherte** Suche angewendet wird — beide
     * bestehen aus denselben zwei Angaben, und zwei Wege, dieselbe Adresse zu
     * bauen, wären der Anfang zweier Bedeutungen.
     *
     * Drei Dinge daran sind Absicht:
     *
     *   - **Die Filterleiste fährt unverändert mit.** Projekte, Umgebung und
     *     Zeitraum bleiben, wie der Betrachter sie eingestellt hat; eine Suche
     *     sagt, *welche* Fehler gemeint sind, nicht *wo und wann* gesucht wird.
     *   - **Der Zustandsfilter wird ausdrücklich auf „alle" gestellt.** Er ist
     *     ein zweiter Weg, dasselbe zu sagen — und ohne diese Zeile stünde vor
     *     „Stummgeschaltet" (`is:ignored`) noch die Vorgabe „offen" und die
     *     Ansicht bliebe zuverlässig leer. Was der Ausdruck über den Zustand
     *     sagt, ist maßgeblich; das Auswahlfeld tritt beiseite.
     *   - **Die Seitenzahl bleibt draußen.** „Seite 7" einer anderen Liste ist
     *     eine andere Seite 7.
     */
    public static function href(GlobalFilter $filter, string $query, IssueSort $sort): string
    {
        return route('issues.index', [
            ...$filter->formValues(),
            'q' => $query,
            'sort' => $sort->value,
            'status' => IssueListRequest::STATUS_ANY,
        ]);
    }

    /**
     * Der angezeigte Name einer Ansicht.
     */
    private static function label(string $key): string
    {
        return __('issues.views.'.$key);
    }

    /**
     * Lässt sich dieser Ausdruck heute vollständig beantworten?
     *
     * Übersetzt wird er dafür wirklich — nicht gegen eine Liste bekannter
     * Lücken verglichen. Die Liste stünde sonst an zwei Stellen, und die zweite
     * bliebe stehen, wenn S7 und S8 die erste erledigen.
     *
     * Ein Ausdruck, der gar nicht aufgeht, gilt hier ebenfalls als „nicht
     * verfügbar": beides führt zu derselben Auskunft, nämlich dass die Liste
     * nicht das zeigt, was draufsteht. Genau das trifft `assigned:me` ohne
     * angemeldeten Betrachter — dann ist „mir" niemand.
     */
    private static function isAvailable(string $query, GlobalFilter $filter, ?User $viewer): bool
    {
        $expression = SearchExpression::compile(
            $query,
            new IssueFields($filter->timezone, $filter->organization, $viewer),
        );

        return $expression->error === null && $expression->unavailable === [];
    }
}
