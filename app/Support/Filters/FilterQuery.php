<?php

namespace App\Support\Filters;

use App\Enums\FilterPeriod;
use App\Http\Requests\GlobalFilterRequest;
use App\Support\ShellData;
use Illuminate\Http\Request;

/**
 * Die globale Filterleiste als Adresszeile — Bauen und Erkennen.
 *
 * Der Filterzustand steht in der Adresse, und drei Stellen müssen sich darüber
 * einig sein: die Oberfläche schreibt ihn beim Filtern
 * (resources/js/shell/filters/useGlobalFilter.js), die Navigation trägt ihn beim
 * Seitenwechsel mit ({@see ShellData}), und der Server liest ihn
 * zurück ({@see GlobalFilterRequest}). Die Schreibweise
 * gehört deshalb an eine Stelle und nicht in jede dieser drei.
 *
 * Sie ist bewusst dieselbe wie die des Browsers — `projects[]` je Kürzel, leere
 * Felder gar nicht erst —, damit eine vom Server gebaute Adresse und eine von
 * der Oberfläche gebaute Zeichen für Zeichen übereinstimmen: die Oberfläche
 * räumt beim nächsten Filtern genau die Felder aus der Adresse, die sie selbst
 * schreiben würde.
 */
final class FilterQuery
{
    /**
     * Die Felder, an denen sich eine **ausdrücklich gefilterte** Adresse
     * erkennen lässt. Alles andere in der Adresszeile gehört der Seite
     * (Sortierung, Suchausdruck, Blättern) — dieselbe Aufteilung wie in
     * useGlobalFilter.js.
     *
     * `tz` fehlt hier, und das ist der Kern: die Zeitzone trägt die Oberfläche
     * von sich aus nach, sobald sie fehlt (useGlobalFilter.js). Zählte sie mit,
     * verwandelte dieser Nachtrag jeden nackten Aufruf in einen „ausdrücklich
     * gefilterten" — und der gemerkte Stand käme nie mehr zum Zug. Die Zeitzone
     * sagt, in welcher Uhr gerechnet wird, nicht welcher Ausschnitt gemeint ist.
     *
     * @var list<string>
     */
    private const SELECTION_KEYS = ['projects', 'environment', 'period', 'from', 'to'];

    /**
     * Trägt die Adresse eine ausdrückliche Filterauswahl?
     *
     * Sie hat dann Vorrang vor dem gemerkten Stand — und zwar **ganz**, nicht
     * Feld für Feld. Ein geteilter Link soll beim Empfänger denselben Ausschnitt
     * zeigen wie beim Absender; würde nur ergänzt, was der Link nicht nennt,
     * mischte sich dessen Zeitraum mit den gemerkten Projekten des Empfängers zu
     * einem Ausschnitt, den niemand von beiden gemeint hat.
     */
    public static function isExplicit(Request $request): bool
    {
        foreach (self::SELECTION_KEYS as $key) {
            if ($request->has($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Der aufgelöste Filter als Adresszeile, ohne führendes `?`.
     *
     * Leere Felder bleiben weg, damit dort nicht `?environment=&from=` steht;
     * die Datumsfelder nur beim eigenen Zeitraum, sonst löst der Server den
     * relativen selbst auf.
     */
    public static function build(GlobalFilter $filter): string
    {
        $values = $filter->formValues();
        $parts = [];

        foreach ($values['projects'] as $slug) {
            $parts[] = rawurlencode('projects[]').'='.rawurlencode($slug);
        }

        foreach (['environment', 'period'] as $key) {
            if ($values[$key] !== '') {
                $parts[] = $key.'='.rawurlencode($values[$key]);
            }
        }

        if ($values['period'] === FilterPeriod::Custom->value) {
            $parts[] = 'from='.rawurlencode($values['from']);
            $parts[] = 'to='.rawurlencode($values['to']);
        }

        if ($values['tz'] !== '') {
            $parts[] = 'tz='.rawurlencode($values['tz']);
        }

        return implode('&', $parts);
    }

    /**
     * Eine Adresse ohne die Projektauswahl — und ohne die Seitenzahl.
     *
     * Gebraucht beim Wechsel der Organisation: Projekte gehören zu einer
     * Organisation, also darf die Auswahl der alten nicht in der neuen
     * weiterstehen. Beim Auflösen fiele sie ohnehin heraus ({@see
     * GlobalFilter::resolve}) — hier verschwindet sie schon aus der Adresse,
     * damit dort nicht ein Projekt steht, das die Leiste darunter nicht zeigt.
     *
     * Die Seitenzahl geht mit: eine andere Organisation hat eine andere
     * Trefferliste, und „Seite 7" darin ist eine andere Seite 7.
     *
     * Alles Übrige — der Zeitraum voran — bleibt Zeichen für Zeichen stehen: der
     * Wechsel der Organisation ist kein Grund, den betrachteten Zeitraum zu
     * verlieren.
     */
    public static function withoutProjectSelection(string $url): string
    {
        [$path, $query] = array_pad(explode('?', $url, 2), 2, '');

        if ($query === '') {
            return $path;
        }

        $kept = array_filter(
            explode('&', $query),
            function (string $pair): bool {
                $key = urldecode(explode('=', $pair, 2)[0]);

                return ! str_starts_with($key, 'projects') && $key !== 'page';
            },
        );

        return $kept === [] ? $path : $path.'?'.implode('&', $kept);
    }
}
