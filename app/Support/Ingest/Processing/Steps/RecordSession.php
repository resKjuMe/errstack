<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\ProjectKey;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Releases\Health\SessionBatch;
use App\Support\Releases\Health\SessionRecorder;
use App\Support\Releases\Health\SessionUpdate;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Zählt gemeldete Sitzungen auf die Gesundheit ihrer Version.
 *
 * Der Schritt fasst nur die beiden Sitzungs-Elemente an — die einzelne Sitzung
 * (`session`) und das Bündel (`sessions`) — und reicht alles andere unverändert
 * weiter. Er ist damit das Gegenstück zu {@see RecordRelease}: der erfasst die
 * Version aus Fehlern und Antwortzeiten, dieser die Sitzungen dazu.
 *
 * **Er steht direkt vor der Erfassung der Version**, und zwar aus demselben
 * Grund wie diese am Ende der Kette steht: erfasst werden darf nur, was auch
 * bleibt — hinter Filter und Scrubbing. Weiter vorn stünden Versionen in der
 * Liste, aus denen niemand je eine Meldung behalten hat. Umgekehrt braucht er
 * nichts von dem, was zwischen Scrubbing und Version passiert: eine Sitzung ist
 * kein Fehler, hat keinen Fingerabdruck und keinen Eintrag.
 *
 * **Er sortiert nicht aus**, sondern zählt eine unbrauchbare Sitzung als
 * verworfen und lässt die Kette weiterlaufen — dieselbe Haltung wie bei den
 * Antwortzeiten. Ein `drop()` beendete die Verarbeitung der ganzen Meldung,
 * und die Buchhaltung am Ende der Kette hat mit ihr noch zu tun.
 */
final class RecordSession implements ProcessingStep
{
    /**
     * Die Elemente, die dieser Schritt liest.
     *
     * @var list<IngestType>
     */
    private const HANDLED = [IngestType::Session, IngestType::Sessions];

    public function __construct(
        private readonly SessionRecorder $recorder,
    ) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;

        if (! in_array($payload->type, self::HANDLED, true)) {
            $next($context);

            return;
        }

        if (! $this->record($context)) {
            // Gezählt statt still weggelassen: eine Sitzung ohne Versionsangabe
            // ist der häufigste Grund, warum die Gesundheit einer Auslieferung
            // leer bleibt — ohne diese Zahl bliebe nur das Rätselraten.
            $this->discard($payload);
        }

        $next($context);
    }

    /**
     * Liest das Element und schreibt es fort.
     */
    private function record(ProcessingContext $context): bool
    {
        $payload = $context->payload;
        $project = $payload->project;

        if ($project === null) {
            return false;
        }

        if ($payload->type === IngestType::Sessions) {
            $batch = SessionBatch::fromPayload($context->data);

            return $batch !== null && $this->recorder->batch($project, $batch);
        }

        $update = SessionUpdate::fromPayload($context->data);

        return $update !== null && $this->recorder->single($project, $update);
    }

    /**
     * Zählt und protokolliert, was nicht erfasst wurde — dieselbe Form wie bei
     * den Antwortzeiten, damit die Nutzungsstatistik (O3) beides nebeneinander
     * ausweisen kann.
     *
     * `unreadable` und nicht `filtered`: hier hat niemand etwas eingestellt, die
     * Meldung ließ sich schlicht nicht deuten — meist, weil ihr die Version
     * fehlt oder die Sitzungsnummer.
     */
    private function discard(IngestPayload $payload): void
    {
        $key = $payload->key;

        if ($key instanceof ProjectKey) {
            IngestDiscard::server($key, DiscardReason::Unreadable, $payload->type->value);
        }

        Log::warning('Sitzung nicht erfasst.', [
            'projekt' => $payload->project_id,
            'meldung' => $payload->id,
            'art' => $payload->type->value,
        ]);
    }
}
