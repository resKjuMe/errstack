<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Support\Feedback\UserReportIntake;
use App\Support\Feedback\UserReportPayload;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Macht aus einer angenommenen Rückmeldung die Zeile, die in der Liste steht.
 *
 * Der Schritt fasst nur die beiden Rückmeldungs-Typen an
 * ({@see IngestType::isUserFeedback()}) und reicht alles andere
 * weiter — dasselbe Muster wie {@see RecordTransaction}.
 *
 * **Eine unlesbare Rückmeldung wird ausgesondert, nicht durchgereicht.** Bei
 * einer Transaktion wäre das falsch, weil an derselben Meldung noch andere
 * Schritte etwas zu tun haben; hier ist die Rückmeldung der ganze Inhalt. Ohne
 * Text bleibt nichts übrig, was noch jemanden erreichen könnte, und ein
 * gezähltes Aussortieren ist die ehrlichere Auskunft als ein stiller Durchlauf,
 * der als „ausgewertet" endet.
 */
final class RecordUserReport implements ProcessingStep
{
    /** Name, unter dem die abgelegte Rückmeldung im Kontext steht. */
    public const RESULT = 'user_report';

    public function __construct(private readonly UserReportIntake $intake) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;

        if (! $payload->type->isUserFeedback()) {
            $next($context);

            return;
        }

        $data = $context->data;

        if ($data === null) {
            $context->drop(DiscardReason::Unreadable, $payload->type->value);

            return;
        }

        $report = UserReportPayload::fromArray($data);

        if ($report === null) {
            // Ein Rumpf ohne Text: ein leer abgeschicktes Formular, ein SDK,
            // das den Abschnitt anders nennt als alle bekannten Formen.
            $context->drop(DiscardReason::Unreadable, $payload->type->value);

            return;
        }

        $stored = $this->intake->accept($payload, $report);

        if ($stored !== null) {
            $context->with(self::RESULT, $stored);
        }

        $next($context);
    }
}
