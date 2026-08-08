<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Models\Event;
use App\Models\Issue;
use App\Models\Release;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Issues\IssueActions;
use App\Support\Issues\RegressionCondition;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Erkennt den Rückfall: ein erledigter Fehler, der wieder auftritt, geht von
 * selbst wieder auf.
 *
 * Ohne diesen Schritt bleibt ein erledigter Eintrag erledigt, auch wenn er
 * weiter gemeldet wird — die Zähler laufen, aber in der Arbeitsliste steht er
 * nicht mehr, und niemandem fällt auf, dass die Behebung nicht gehalten hat.
 * Das ist der ganze Zweck: der Fehler kommt zurück in die Liste, in der er
 * gesucht wird.
 *
 * **Er steht hinter der Version** ({@see RecordRelease}) und nicht neben dem
 * Zählen, und das ist die Voraussetzung und nicht die Ordnung: „erledigt in
 * 1.4.2" ist erst dann widerlegt, wenn eine **neuere** Fassung betroffen ist,
 * und welche das ist, weiß die Kette erst dort. Ein Schritt früher wüsste er
 * nur, dass überhaupt etwas eingetroffen ist — und würde jede Meldung aus der
 * noch laufenden alten Fassung als Rückfall werten.
 *
 * **Er steht vor der Alarmierung** ({@see EvaluateIssueAlerts}), damit die
 * Regel „ein erledigter Fehler tritt wieder auf" den Rückfall auch als solchen
 * sieht. Genau daraus entsteht die eine Feststellung, die sich hinterher nicht
 * mehr ablesen lässt und deshalb weitergereicht wird ({@see RESOLVED_AT}): nach
 * diesem Schritt ist der Eintrag **offen**, und aus einem offenen Eintrag ist
 * nicht mehr zu erkennen, dass er vor einer Sekunde erledigt war.
 *
 * **Der Regelfall kostet einen Vergleich.** Für jeden nicht erledigten Eintrag
 * endet der Schritt bei {@see RegressionCondition::isPossible()} — kein
 * Nachladen, keine Abfrage. Erst ein erledigter Eintrag holt seinen frischen
 * Stand und die Fassung dazu, in der er als behoben gilt. Das ist dieselbe
 * Rechnung wie bei der Stummschaltung einen Schritt davor
 * ({@see AggregateIssue}): eine Abfrage je Ereignis wäre bei einem Ausfall
 * genau die Last, die der Aufnahmeweg nicht hat.
 */
final class DetectRegression implements ProcessingStep
{
    /**
     * Ob **dieses** Ereignis den Eintrag wieder aufgemacht hat.
     */
    public const RESULT = 'issue_regressed';

    /**
     * Der Zeitpunkt der Erledigung, die der Rückfall beendet hat.
     *
     * Er wird weitergereicht, weil die Alarmierung ihn braucht und ihn nicht
     * mehr finden kann: die Marke, mit der sie denselben Rückfall nicht zweimal
     * meldet (App\Models\IssueAlertState), ist genau dieser Zeitpunkt — und am
     * Eintrag steht er nach dem Aufmachen nicht mehr.
     */
    public const RESOLVED_AT = 'issue_regressed_from';

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $issue = $context->get(AggregateIssue::RESULT);
        $record = $context->get(NormalizeEvent::RESULT.'_record');

        if (! $issue instanceof Issue || ! $record instanceof Event) {
            $next($context);

            return;
        }

        // Zuerst der Vergleich am Eintrag aus dem Zählen, ohne Nachladen: für
        // jeden offenen und jeden stummgeschalteten Eintrag — den Regelfall —
        // endet der Schritt hier, und die Aufnahme ist um keine Abfrage
        // gewachsen. Ein Eintrag, den jemand in der Zwischenzeit erledigt hat,
        // fällt dabei durch: dann ist dieses Ereignis älter als die Erledigung
        // und wäre ohnehin kein Rückfall.
        if (! RegressionCondition::fromIssue($issue)->isPossible()) {
            $next($context);

            return;
        }

        // Erst jetzt frisch geholt — der Eintrag aus dem Zählen ist im Speicher
        // veraltet, und hier hängt alles daran: hat ihn jemand in derselben
        // Sekunde wieder geöffnet, gibt es nichts aufzumachen.
        $current = $issue->fresh();

        if ($current === null) {
            $next($context);

            return;
        }

        $condition = RegressionCondition::fromIssue($current);

        if (! $condition->isPossible()) {
            $next($context);

            return;
        }

        $seenIn = $context->get(RecordRelease::RESULT);
        $seenIn = $seenIn instanceof Release ? $seenIn : null;

        $regressed = $condition->evaluate(
            CarbonImmutable::parse($record->occurred_at)->utc(),
            $seenIn,
            $current->resolvedInRelease,
        );

        if ($regressed && IssueActions::reopenRegression($current, $seenIn)) {
            $context->with(self::RESULT, true);
            $context->with(self::RESOLVED_AT, $condition->resolvedAt);
        }

        $next($context);
    }
}
