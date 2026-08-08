<?php

namespace App\Support\Ownership;

use App\Enums\OwnershipMatcher;
use App\Models\OwnershipRule;
use App\Support\Ingest\Filtering\Pattern;
use Illuminate\Database\Eloquent\Collection;

/**
 * Die Auswertung der Zuständigkeits-Regeln: welche Regel trifft auf diese
 * Meldung zu, und wer ist damit zuständig?
 *
 * **Die zuletzt passende Regel gewinnt.** Das ist die eine Festlegung, aus der
 * sich der Rest ergibt, und sie ist von CODEOWNERS übernommen — aus dem
 * einfachen Grund, dass sich diese Listen aus einer CODEOWNERS-Datei
 * importieren lassen und dieselbe Datei nicht zweierlei bedeuten darf. Sie
 * lässt sich auch ohne diesen Bezug begründen: eine Liste wird von allgemein
 * nach speziell geschrieben („alles gehört der Plattform, aber `src/billing/*`
 * gehört der Kasse"), und die speziellere Zeile steht dann unten. Bei der
 * ersten passenden Regel müsste man sie oben schreiben — und jede neue
 * Ausnahme oben einfügen, statt sie anzuhängen.
 *
 * **Abgeschaltete Regeln zählen nicht mit**, auch nicht als „getroffen, aber
 * still". Das ist der Weg, eine Zeile probeweise auszusetzen, ohne sie zu
 * verlieren — und solange sie aus ist, gewinnt die Regel darüber.
 *
 * **Ohne Datenbank prüfbar:** {@see self::matching()} bekommt die Regeln
 * übergeben und schlägt sie nicht selbst nach. Nur {@see self::rulesFor()}
 * fragt die Datenbank, und das ist eine Zeile.
 */
final class Ownership
{
    /**
     * Die Regeln eines Projekts in ihrer Reihenfolge.
     *
     * Dieselbe Form wie bei der Gruppierung ({@see App\Support\Ingest\Grouping\Grouper::rulesFor()}):
     * eine Abfrage, begrenzt auf das, was ein Projekt haben darf. Kein
     * Zwischenspeicher — die Auswertung trifft nur das **erste** Auftreten eines
     * Fehlers, und dafür lohnt sich kein zweiter Ort, an dem eine geänderte
     * Regel noch nicht angekommen ist.
     *
     * @return Collection<int, OwnershipRule>
     */
    public static function rulesFor(int $projectId): Collection
    {
        return OwnershipRule::query()
            ->where('project_id', $projectId)
            ->active()
            ->inOrder()
            ->limit(OwnershipRule::MAX_PER_PROJECT)
            ->get();
    }

    /**
     * Alle Regeln, die auf diese Meldung zutreffen — in der Reihenfolge der
     * Liste.
     *
     * Alle und nicht nur die gewinnende, weil die Vorschau die Frage „warum
     * dieser und nicht jener?" beantworten muss: dort steht die gewinnende Regel
     * oben und darunter, was sie überstimmt hat. Wer nur den Gewinner braucht,
     * nimmt {@see self::winner()}.
     *
     * @param  iterable<OwnershipRule>  $rules
     * @return list<OwnershipRule>
     */
    public static function matching(OwnershipSubjects $subjects, iterable $rules): array
    {
        if ($subjects->isEmpty()) {
            return [];
        }

        $matches = [];

        foreach ($rules as $rule) {
            // Die abgeschaltete Regel fällt hier heraus und nicht erst in der
            // Abfrage. Das ist bewusst doppelt: {@see self::rulesFor()} lädt
            // ohnehin nur die eingeschalteten, aber diese Methode nimmt
            // **irgendeine** Liste entgegen — aus einem Test, aus einer Vorschau
            // über ungespeicherte Regeln. Die Zusage „abgeschaltet heißt
            // wirkungslos" gehört deshalb an die Auswertung und nicht an eine
            // von mehreren Stellen, die sie beliefern.
            if ($rule->is_active && self::matches($rule, $subjects)) {
                $matches[] = $rule;
            }
        }

        return $matches;
    }

    /**
     * Die Regel, die gilt — die **letzte** zutreffende.
     *
     * @param  iterable<OwnershipRule>  $rules
     */
    public static function winner(OwnershipSubjects $subjects, iterable $rules): ?OwnershipRule
    {
        $matches = self::matching($subjects, $rules);

        return $matches === [] ? null : $matches[array_key_last($matches)];
    }

    /**
     * Trifft diese eine Regel zu?
     */
    public static function matches(OwnershipRule $rule, OwnershipSubjects $subjects): bool
    {
        $values = $subjects->for($rule->matcher, $rule->tag_key);

        if ($values === []) {
            return false;
        }

        return $rule->matcher === OwnershipMatcher::Path
            ? self::matchesPath($rule->pattern, $values)
            : Pattern::matchesAny($rule->pattern, $values);
    }

    /**
     * Der Vergleich eines Pfad-Musters — der einzige mit einer Besonderheit.
     *
     * Sie ist unvermeidlich: im Stacktrace steht der Pfad, unter dem die Datei
     * **auf dem Server** liegt (`/var/www/releases/17/src/billing/Invoice.php`),
     * in der Regel steht der Pfad, unter dem sie **im Repository** liegt
     * (`src/billing/*`) — und niemand kennt beim Schreiben der Regel das
     * Auslieferungsverzeichnis. Ein Muster ohne führenden Platzhalter wird
     * deshalb zusätzlich so verglichen, als stünde ein Sternchen mit
     * Schrägstrich davor. Das ist genau die Erwartung, die eine
     * CODEOWNERS-Datei mitbringt, in der alle Pfade repository-relativ sind.
     *
     * Wer die Freiheit nicht will, schreibt das Muster mit führendem `/` oder
     * `*` — dann gilt es unverändert.
     *
     * Rückwärtsschrägstriche werden vorher zu Schrägstrichen: eine Meldung aus
     * einer Windows-Anwendung trüge sonst Pfade, auf die kein einziges der
     * üblichen Muster passt.
     *
     * @param  list<string>  $values
     */
    private static function matchesPath(string $pattern, array $values): bool
    {
        $values = array_map(
            static fn (string $value): string => str_replace('\\', '/', $value),
            $values,
        );

        $pattern = str_replace('\\', '/', trim($pattern));

        if (Pattern::matchesAny($pattern, $values)) {
            return true;
        }

        return ! str_starts_with($pattern, '*')
            && ! str_starts_with($pattern, '/')
            && Pattern::matchesAny('*/'.$pattern, $values);
    }
}
