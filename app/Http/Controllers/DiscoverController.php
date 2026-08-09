<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiscoverRequest;
use App\Models\Project;
use App\Support\Discover\DiscoverData;
use App\Support\Discover\DiscoverEngine;
use App\Support\Discover\DiscoverException;
use App\Support\Discover\DiscoverLimits;
use App\Support\Discover\DiscoverRow;
use App\Support\Filters\GlobalFilter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Die freie Auswertung: eine Frage selbst zusammenstellen und als Tabelle **und**
 * Diagramm beantwortet bekommen.
 *
 * Gerechnet wird nichts hier — das tut der Motor aus D1. Dieser Controller liest
 * die Adresszeile ({@see DiscoverRequest}), stellt die Abfrage zusammen und gibt
 * das Ergebnis aus. Tabelle und Diagramm entstehen aus **derselben** Abfrage, nur
 * einmal mit und einmal ohne Schrittweite: das ist der Grund, warum die Summe
 * einer Linie die Zahl der Tabellenzeile ist und nicht eine zweite Rechnung
 * daneben.
 *
 * **Eine Auswertung läuft über genau ein Projekt.** Die Grenzen des Motors —
 * Zeit, Zeilen, Stützstellen — gelten je Abfrage; über mehrere Projekte wäre es
 * je Projekt eine Abfrage, und die Zusage an die übrigen Leser der Datenbank
 * gälte für keine davon. Dazu kommt, dass sich ein Perzentil oder eine
 * Zufriedenheit hinterher nicht zusammenzählen lässt: aus „p95 = 400 ms" und
 * „p95 = 900 ms" folgt kein gemeinsames p95. Steht in der Filterleiste nicht
 * genau ein Projekt, zeigt die Seite deshalb keine geratene Auswahl, sondern die
 * Bitte, eines zu wählen.
 *
 * **Eine abgelehnte Abfrage ist eine Auskunft und kein Fehlerbildschirm.** Der
 * Motor sagt mit Grenze und verlangtem Wert, warum er nicht gerechnet hat
 * ({@see DiscoverException}); die Seite steht daneben unverändert da, sodass die
 * Abfrage an Ort und Stelle geändert werden kann.
 */
class DiscoverController extends Controller
{
    public function __construct(private readonly DiscoverEngine $engine = new DiscoverEngine) {}

    public function index(DiscoverRequest $request): InertiaResponse
    {
        $filter = $request->filter();
        $limits = DiscoverLimits::fromConfig();
        $project = self::project($filter);

        $page = [
            'catalog' => DiscoverData::catalog($filter->timezone, $limits),
            'project' => $project === null ? null : ['slug' => $project->slug, 'name' => $project->name],
            'projectOptions' => self::projectOptions($filter),
        ];

        if ($project === null) {
            // Ohne Projekt gibt es keine Abfrage — und damit auch keinen
            // Zustand, den die Seite zurückschreiben könnte. Die Felder der
            // Leiste stehen trotzdem, damit die Auswahl beim Wechsel des
            // Projekts erhalten bleibt.
            return Inertia::render('discover/Index', $page + [
                'query' => $request->queryValues($filter, $limits),
                'columns' => [],
                'table' => null,
                'series' => null,
                'error' => null,
                'seriesError' => null,
                'exportHref' => null,
            ]);
        }

        try {
            $query = $request->discoverQuery($project, $filter, $limits);
            $table = $this->engine->table($query);
        } catch (DiscoverException $exception) {
            return Inertia::render('discover/Index', $page + [
                'query' => $request->queryValues($filter, $limits),
                'columns' => [],
                'table' => null,
                'series' => null,
                'error' => $exception->toArray(),
                'seriesError' => null,
                'exportHref' => null,
            ]);
        }

        // Die Zeitreihe hat eine eigene Grenze — die Zahl der Stützstellen —, und
        // sie fällt anders aus als die der Tabelle: eine Schrittweite von einer
        // Minute über dreißig Tage sind über vierzigtausend Punkte. Das nimmt der
        // Tabelle aber nicht ihre Antwort. Deshalb wird nur das Diagramm
        // ausgesetzt und die Auswertung bleibt stehen, statt beides gemeinsam
        // gegen eine Grenze zu verlieren, die nur für eines von beidem gilt.
        $seriesError = null;
        $series = null;

        try {
            $series = $this->engine->series($query->every($request->interval($filter)));
        } catch (DiscoverException $exception) {
            $seriesError = $exception->toArray();
        }

        return Inertia::render('discover/Index', $page + [
            'query' => $request->queryValues($filter, $limits),
            'columns' => DiscoverData::columns($query),
            'table' => DiscoverData::table($table, $query, $filter, $project->slug),
            'series' => $series === null ? null : DiscoverData::series($series, __('discover.chart.all')),
            'error' => null,
            'seriesError' => $seriesError,
            'exportHref' => route('discover.export', $request->queryValues($filter, $limits) + $filter->formValues()),
        ]);
    }

    /**
     * Dieselbe Auswertung als CSV — genau die Spalten und genau die Zeilen, die
     * auf der Seite stehen.
     *
     * „Genau" heißt hier auch: dieselbe Zeilenzahl. Wer 50 Zeilen sieht und 1000
     * exportiert, bekommt eine andere Antwort auf dieselbe Frage; wer mehr will,
     * stellt die Zeilenzahl an der Abfrage um — sie steht in der Adresse und
     * geht damit in den Export mit ein.
     */
    public function export(DiscoverRequest $request): StreamedResponse|RedirectResponse
    {
        $filter = $request->filter();
        $limits = DiscoverLimits::fromConfig();
        $project = self::project($filter);

        if ($project === null) {
            return redirect()->route('discover.index', $request->queryValues($filter, $limits) + $filter->formValues());
        }

        try {
            $query = $request->discoverQuery($project, $filter, $limits);
            $result = $this->engine->table($query);
        } catch (DiscoverException) {
            // Abgelehnt wird auf der Seite erklärt und nicht in einer Datei, die
            // dann leer im Download-Ordner liegt.
            return redirect()->route('discover.index', $request->queryValues($filter, $limits) + $filter->formValues());
        }

        $columns = DiscoverData::columns($query);
        $filename = __('discover.export.filename', [
            'dataset' => $query->dataset->value,
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->streamDownload(function () use ($result, $columns): void {
            $handle = fopen('php://output', 'w');

            // Tabellenprogramme erkennen UTF-8 sonst nicht und zeigen aus „ä"
            // zwei Zeichen. Semikolon als Trenner aus demselben Grund — wie im
            // Änderungsprotokoll.
            fwrite($handle, "\xEF\xBB\xBF");

            // Alle Parameter ausgeschrieben: der frühere Fluchtmechanismus von
            // fputcsv ist abgekündigt, ein leerer Wert schaltet ihn ab.
            fputcsv($handle, array_map(DiscoverData::heading(...), $columns), ';', '"', '');

            foreach ($result->rows as $row) {
                fputcsv($handle, self::line($row, $columns), ';', '"', '');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Eine Zeile der Datei, in der Reihenfolge der Spalten.
     *
     * @param  list<array{key: string, label: string, kind: string, unit: string, format: string}>  $columns
     * @return list<string>
     */
    private static function line(DiscoverRow $row, array $columns): array
    {
        $line = [];

        foreach ($columns as $column) {
            if ($column['kind'] === 'group') {
                // Ein fehlender Gruppenwert bleibt eine leere Zelle: „—" wäre in
                // einer Tabellenkalkulation ein Text, mit dem niemand rechnen
                // kann, und „0" wäre gelogen.
                $line[] = (string) ($row->groups[$column['key']] ?? '');

                continue;
            }

            $line[] = DiscoverData::cell($row->value($column['key']));
        }

        return $line;
    }

    /**
     * Das eine Projekt, über das gerechnet wird — oder `null`, wenn die
     * Filterleiste keines eindeutig benennt.
     */
    private static function project(GlobalFilter $filter): ?Project
    {
        return $filter->projects->count() === 1 ? $filter->projects->first() : null;
    }

    /**
     * Die Projekte zur Auswahl, jedes mit der Adresse, die genau es einstellt —
     * der übrige Zustand der Abfrage bleibt dabei stehen.
     *
     * @return list<array{slug: string, name: string, href: string}>
     */
    private static function projectOptions(GlobalFilter $filter): array
    {
        return $filter->availableProjects
            ->map(fn (Project $project): array => [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('discover.index', array_merge(
                    request()->query(),
                    $filter->formValues(),
                    ['projects' => [$project->slug]],
                )),
            ])
            ->values()
            ->all();
    }
}
