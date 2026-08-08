<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Models\IngestPayload;
use App\Models\Transaction;
use App\Support\Ingest\EnvelopeIntake;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Profiling\ProfileEvent;
use App\Support\Profiling\ProfileStore;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Legt ein gemeldetes Sample-Profil an der Transaktion ab, die es vermessen hat.
 *
 * Der Schritt fasst nur Meldungen des Typs `profile` an und reicht alles andere
 * unverändert weiter — dieselbe Zusage wie bei {@see RecordTransaction}: ein
 * Profil ist kein Fehler und darf in keiner Fehlerliste auftauchen.
 *
 * **Er sortiert aus, wenn die Transaktion fehlt.** Das ist der Unterschied zu
 * den Antwortzeiten, und er ist gewollt: eine Transaktion ohne lesbare Zeiten
 * ist unbrauchbar, ein Profil ohne Transaktion ist etwas anderes — es ist
 * vollständig und in Ordnung, es hat nur nichts, woran es hängen könnte. Beides
 * abzulegen hieße, eine Ansicht zu bauen, die sagt „hier wurden 300 ms
 * gerechnet" und auf die Rückfrage „wofür?" schweigt.
 *
 * Zum Zeitpunkt: Transaktion und Profil kommen im selben Envelope, aber als
 * zwei Elemente mit je einem eigenen Job. Damit das Profil seine Transaktion
 * vorfindet, wird sein Job verzögert eingereiht ({@see EnvelopeIntake}) — der
 * Vorsprung ist die einzige Reihenfolge-Zusage, die es zwischen zwei
 * unabhängigen Jobs geben kann. Reicht er nicht (die Transaktion hing in einer
 * Wiederholung), wird das Profil verworfen und gezählt, statt auf gut Glück
 * liegen zu bleiben.
 */
final class RecordProfile implements ProcessingStep
{
    /**
     * Name, unter dem das abgelegte Profil im Kontext steht.
     */
    public const RESULT = 'profile';

    public function __construct(
        private readonly ProfileStore $store,
    ) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;

        if ($payload->type !== IngestType::Profile) {
            $next($context);

            return;
        }

        $project = $payload->project;
        $data = $context->data;

        if ($project === null || $data === null) {
            // Ohne Projekt gibt es keinen Ort für die Messung, ohne Rumpf nichts
            // zu lesen. Beides trifft nur Meldungen, deren Projekt nach der
            // Annahme gelöscht wurde.
            $context->drop(DiscardReason::Unreadable, IngestType::Profile->value);

            return;
        }

        $event = ProfileEvent::fromPayload($data, $payload->event_id);

        if ($event === null) {
            $this->log($payload, 'Profil ohne Bezug zu einer Transaktion oder ohne verwertbare Stichproben.');
            $context->drop(DiscardReason::Unreadable, IngestType::Profile->value);

            return;
        }

        $transaction = Transaction::query()
            ->where('project_id', $project->id)
            ->where('event_id', $event->transactionEventId)
            ->first();

        if ($transaction === null) {
            $this->log($payload, 'Zum Profil gibt es keine Transaktion.', [
                'transaktion' => $event->transactionEventId,
            ]);
            $context->drop(DiscardReason::Orphaned, IngestType::Profile->value);

            return;
        }

        $this->countLosses($payload, $event);

        $context->with(self::RESULT, $this->store->store($event, $transaction, $payload));

        $next($context);
    }

    /**
     * Schreibt mit, was beim Lesen des Profils liegen blieb.
     *
     * Ohne diese Zahlen wäre ein Flamegraph aus einem Fünftel der Stichproben
     * von einem vollständigen nicht zu unterscheiden — und niemand käme auf die
     * Idee, dass die Anwendung ihre Arbeit auf zwanzig Ausführungsstränge
     * verteilt, von denen wir einen zeigen.
     *
     * Die Stichproben fremder Ausführungsstränge sind dabei ausdrücklich **kein**
     * Verlust: sie wurden gemeldet und gelesen, nur eben nicht für dieses Bild
     * verwendet.
     */
    private function countLosses(IngestPayload $payload, ProfileEvent $event): void
    {
        $notes = array_filter([
            'stichproben_fremder_straenge' => $event->foreignSamples,
            'stichproben_unlesbar' => $event->unreadableSamples,
            'stichproben_ueber_grenze' => $event->excessSamples,
            'stapel_gekuerzt' => $event->truncatedStacks,
            'baum_gekuerzt' => $event->tree->droppedNodes,
        ]);

        if ($notes === []) {
            return;
        }

        Log::info('Profil nicht vollständig ausgewertet.', $notes + [
            'projekt' => $payload->project_id,
            'meldung' => $payload->id,
            'profil' => $event->profileId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(IngestPayload $payload, string $message, array $context = []): void
    {
        Log::warning('Profil nicht abgelegt: '.$message, $context + [
            'projekt' => $payload->project_id,
            'meldung' => $payload->id,
        ]);
    }
}
