<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Models\Event;
use App\Models\Issue;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\IssueAlerts\IssueAlertContext;
use App\Support\IssueAlerts\IssueAlertEvaluator;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Gleicht die Alarm-Regeln des Projekts mit dem gerade verarbeiteten Ereignis
 * ab und meldet, was greift.
 *
 * **Der letzte Schritt der Kette**, und das ist die Zusage der Aufgabe: die
 * Auswertung passiert im Hintergrund unmittelbar nach der Verarbeitung. Früher
 * ginge es nicht — der Eintrag entsteht erst beim Zählen ({@see AggregateIssue}),
 * und eine Regel auf „öfter als zehnmal" bräuchte sonst die Zahl von vorhin.
 *
 * **Er reicht immer weiter und sortiert nie aus.** Eine Regel, die nicht greift,
 * ist der Normalfall und kein Grund, eine Meldung wegzuwerfen; und was der
 * Versand daraus macht, entscheidet ohnehin die Warteschlange (A1) und nicht
 * dieser Schritt.
 *
 * Ohne Eintrag geschieht nichts: eine Sitzung, ein Anhang oder eine
 * Transaktion hat keinen Fehler, auf den sich eine Regel beziehen könnte.
 */
final class EvaluateIssueAlerts implements ProcessingStep
{
    public function __construct(private readonly IssueAlertEvaluator $evaluator) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $issue = $context->get(AggregateIssue::RESULT);
        $record = $context->get(NormalizeEvent::RESULT.'_record');

        if (! $issue instanceof Issue || ! $record instanceof Event) {
            $next($context);

            return;
        }

        // Frisch geholt: `record()` schreibt die Zähler in der Datenbank fort,
        // die Instanz aus dem vorigen Schritt ist danach veraltet. Genau diese
        // Zähler stehen aber in den Filtern („schon öfter als hundertmal
        // gesehen"), und eine Regel darf sich nicht auf den Stand von vor
        // diesem Ereignis beziehen.
        $current = $issue->fresh() ?? $issue;

        // Der Rückfall kommt aus dem Schritt davor und nicht aus dem Eintrag:
        // der ist inzwischen offen, und dass er eben noch erledigt war, ist
        // ihm nicht mehr anzusehen ({@see DetectRegression::RESOLVED_AT}).
        $regressedFrom = $context->get(DetectRegression::RESOLVED_AT);

        $this->evaluator->evaluate(new IssueAlertContext(
            issue: $current,
            event: $record,
            isNew: $context->get(AggregateIssue::WAS_NEW) === true,
            escalated: $context->get(AggregateIssue::ESCALATED) === true,
            occurredAt: CarbonImmutable::parse($record->occurred_at)->utc(),
            regressedFrom: $regressedFrom instanceof CarbonImmutable ? $regressedFrom : null,
        ));

        $next($context);
    }
}
