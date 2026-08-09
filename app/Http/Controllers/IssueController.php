<?php

namespace App\Http\Controllers;

use App\Enums\IssueSort;
use App\Enums\IssueStatus;
use App\Events\IssueCreated;
use App\Http\Requests\IssueListRequest;
use App\Models\Project;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Issues\IssueActionData;
use App\Support\Issues\IssueList;
use App\Support\Issues\IssueSeries;
use App\Support\Issues\IssueViews;
use App\Support\Issues\SavedSearchData;
use App\Support\Releases\DeployMarkers;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Fehlerliste — die Arbeitsansicht dieser Anwendung.
 *
 * Sie steht bewusst nicht unter einem einzelnen Projekt in der Adresszeile:
 * welche Projekte gemeint sind, sagt die globale Filterleiste (F7), und die kann
 * eines, mehrere oder alle meinen. Eine Route `/projekte/{projekt}/fehler`
 * daneben hätte eine zweite Wahrheit darüber aufgemacht, welches Projekt gerade
 * gilt.
 */
class IssueController extends Controller
{
    public function __invoke(IssueListRequest $request): InertiaResponse|RedirectResponse
    {
        $filter = $request->filter();

        $default = $this->defaultSearch($request, $filter);

        if ($default !== null) {
            return redirect()->to($default);
        }

        $period = IssueSeries::periodFor($filter);

        $search = $request->search();

        $issues = IssueList::paginate($filter, $request->sort(), $request->status(), $search);

        return Inertia::render('issues/Index', [
            'issues' => $issues,
            'list' => $request->listValues(),
            // Der Weg zur projektweiten Merkmal-Übersicht, mit derselben
            // Projektauswahl. Sortierung, Zustand und Seite bleiben draußen —
            // dort gibt es sie nicht.
            'tagsHref' => route('tags.index', $filter->formValues()),
            // Woher das Suchfeld seine Vorschläge holt. Als Adresse und nicht
            // als fertige Liste: welche Merkmale es gibt, hängt an den gewählten
            // Projekten und wäre auf Vorrat die halbe Merkmal-Übersicht in jeder
            // Seitenlast.
            'suggestHref' => route('issues.search.suggest', $filter->formValues()),
            // Wohin die Auswahl geht, wenn jemand mehrere Fehler von Hand
            // zusammenführt (S9). Welcher Eintrag dabei der Kopf wird, steht
            // nicht in der Adresse — das entscheidet der Server aus der Auswahl.
            'mergeHref' => route('issues.merge.store'),
            // Die Gesamtzahl auch geschrieben: „12.480" gegen „12480" — wie eine
            // Zahl aussieht, entscheidet die Sprache, und die kennt der Server.
            'totalLabel' => Formats::number($issues->total()),
            'sortOptions' => IssueSort::options(),
            'statusOptions' => self::statusOptions(),
            // Die Standard-Ansichten und die gespeicherten Suchen (S5). Sie
            // hängen an jeder Fehlerliste, weil sie der Einstieg in sie sind —
            // eine eigene Seite dafür wäre der Umweg, den man nimmt, um dorthin
            // zurückzukehren, wo man schon war.
            'savedSearches' => SavedSearchData::bar($filter, $request->user()),
            // Die Sammelaktionen (S6). Sie brauchen dieselben Filterfelder, mit
            // denen diese Seite gebaut wurde: „alle 12.480" meint genau die
            // Menge, die hier steht, und die Oberfläche schickt sie dafür aus
            // der Adresszeile mit zurück.
            'actions' => IssueActionData::forViewer(),
            // Was an der Eingabe nicht aufging. Beides stillschweigend zu
            // übergehen wäre die schlechtere Wahl: die Liste sähe aus, als hätte
            // sie die Frage beantwortet.
            //
            //   - `searchError`: die Eingabe wurde nicht verstanden. Die Liste
            //     steht ungefiltert da — eine leere Seite wäre die Sackgasse,
            //     aus der man nur durch Löschen der Adresszeile herausfindet.
            //   - `unavailableTerms`: Begriffe der Sprache, für die es die Daten
            //     noch nicht gibt (`assigned:`, `bookmarks:`, `is:regressed`).
            'searchError' => $search->error?->toArray(),
            'unavailableTerms' => $search->unavailable,
            'series' => [
                'period' => $period->value,
                'periodLabel' => $period->label(),
                // Wann in diesem Zeitraum ausgeliefert wurde (R3). Einmal für
                // die ganze Seite: alle Grafiken darauf stehen über demselben
                // Raster und zeigen deshalb dieselben Striche.
                'markers' => DeployMarkers::forFilter($filter),
            ],
            // Woran die Oberfläche die Live-Aktualisierung anmeldet: **ein** Kanal
            // für die ganze Organisation. Welche Projekte davon gerade zählen,
            // steht daneben — die Meldung trägt ihr Projekt mit, und die Ansicht
            // wirft aus, was nicht in der Auswahl liegt.
            'live' => [
                'channel' => $filter->organization === null
                    ? null
                    : IssueCreated::channelName($filter->organization->id),
                'projectIds' => $filter->projectIds(),
            ],
            // Der Zeitraum wirkt, die Umgebung nicht: der Eintrag ist über alle
            // Umgebungen hinweg gezählt, und ihn danach zu trennen ginge nur über
            // die Einzelereignisse. Statt die Auswahl still zu übergehen, sagt
            // die Seite es.
            'environmentIgnored' => $filter->environment !== null,
        ]);
    }

    /**
     * Die Adresse der Suche, mit der dieses Projekt für diesen Betrachter
     * aufgeht — oder `null`, wenn die Liste so bleibt, wie sie angefragt wurde.
     *
     * **Weitergeleitet und nicht stillschweigend angewendet.** Die Liste legt
     * ihren ganzen Zustand in der Adresszeile ab; eine Voreinstellung, die nur
     * serverseitig wirkt, wäre die eine Ausnahme davon — und damit eine Seite,
     * deren Adresse etwas anderes zeigt als der Bildschirm. Nach der
     * Weiterleitung steht die Suche im Feld, die Sortierung im Auswahlfeld und
     * beides in der Adresse, die man weitergeben kann.
     *
     * Drei Bedingungen, und jede hat einen Grund:
     *
     *   - **Es steht kein `q` in der Adresszeile.** Nicht „`q` ist leer": ein
     *     ausdrücklich geleertes Suchfeld ist eine Aussage („zeig mir alles"),
     *     und sie zu übergehen hieße, dass man den eigenen Einstieg nicht mehr
     *     verlassen kann. Das ist zugleich das, was eine Schleife verhindert —
     *     nach der Weiterleitung steht `q` in der Adresse.
     *   - **Genau ein Projekt steht in der Auswahl.** Der Einstieg gehört einem
     *     Projekt; bei dreien wäre nicht zu entscheiden, welcher gilt.
     *   - **Es ist ein gewöhnlicher Seitenaufruf.** Teilaufrufe (das
     *     Live-Nachladen holt nur `issues`) und alles, was kein `GET` ist,
     *     bleiben außen vor: eine Weiterleitung mitten in einem Teilaufruf
     *     tauschte die Seite unter den Händen des Betrachters aus.
     */
    private function defaultSearch(IssueListRequest $request, GlobalFilter $filter): ?string
    {
        if ($request->has('q') || ! $request->isMethod('GET') || $request->header('X-Inertia-Partial-Data') !== null) {
            return null;
        }

        if ($filter->projects->count() !== 1) {
            return null;
        }

        $project = $filter->projects->first();

        if (! $project instanceof Project) {
            return null;
        }

        $search = SavedSearchData::defaultSearch($request->user(), $project);

        return $search === null ? null : IssueViews::href($filter, $search->query, $search->sort);
    }

    /**
     * Die Zustände zur Auswahl, „alle" voran.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function statusOptions(): array
    {
        return [
            ['value' => IssueListRequest::STATUS_ANY, 'label' => __('issues.filter.any_status')],
            ...IssueStatus::options(),
        ];
    }
}
