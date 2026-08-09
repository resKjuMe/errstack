<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Models\Environment;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\Replay;
use App\Models\ReplayError;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Replays\ReplayMetadata;
use App\Support\Replays\ReplayRecording;
use App\Support\Replays\ReplayStore;
use App\Support\Replays\ReplayTimeline;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Legt eine Sitzungs-Aufzeichnung ab: Kopfdaten und Bildabschnitte.
 *
 * **Ein Schritt für zwei Element-Typen**, und das ist Absicht. `replay_event`
 * und `replay_recording` sind nicht zwei Dinge, sondern zwei Hälften einer
 * Sache: das Begleitpapier und der Film. Beide legen dieselbe Zeile an, beide
 * schreiben sie fort, und beide müssen mit dem Fall umgehen, dass die andere
 * Hälfte noch nicht da ist. Zwei Schritte daraus zu machen hieße, diese
 * Sonderbehandlung zweimal zu schreiben — und beim nächsten Feld eine der
 * beiden zu vergessen.
 *
 * **Die Reihenfolge ist offen, und das braucht keinen Vorsprung.** Anders als
 * beim Profil ({@see RecordProfile}), das ohne seine Transaktion nicht ablegbar
 * ist, kann hier jede Hälfte für sich anfangen: wer zuerst kommt, legt die Zeile
 * an, der andere findet sie vor. Deshalb wird der Job einer Aufzeichnung auch
 * nicht verzögert eingereiht — ein Vorsprung würde hier nichts retten, was ohne
 * ihn verloren ginge.
 *
 * Was der Schritt **nicht** tut: maskieren. Das ist im Browser geschehen oder
 * gar nicht — siehe `config/replays.php`. Er hält nur fest, was das SDK dazu
 * gemeldet hat, damit eine unmaskierte Aufzeichnung erkennbar ist statt
 * unbemerkt zu bleiben.
 */
final class RecordReplay implements ProcessingStep
{
    /**
     * Name, unter dem die abgelegte Aufzeichnung im Kontext steht.
     */
    public const RESULT = 'replay';

    public function __construct(
        private readonly ReplayStore $store,
    ) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;

        if ($payload->type !== IngestType::ReplayEvent && $payload->type !== IngestType::ReplayRecording) {
            $next($context);

            return;
        }

        $project = $payload->project;

        if ($project === null) {
            // Ohne Projekt gibt es keinen Ort für die Aufzeichnung. Das trifft
            // nur Meldungen, deren Projekt nach der Annahme gelöscht wurde.
            $context->drop(DiscardReason::Unreadable, $payload->type->value);

            return;
        }

        if (self::retentionDays($project) === 0) {
            // Null Tage Aufbewahrung heißt „gar nicht aufzeichnen". Hier
            // auszusteigen statt später wegzuräumen ist der Unterschied
            // zwischen einer Zusage und einer Aufräumarbeit: die Bilddaten
            // dieses Projekts berühren nie eine Platte.
            $context->drop(DiscardReason::Discarded, $payload->type->value);

            return;
        }

        $recorded = $payload->type === IngestType::ReplayEvent
            ? $this->header($context, $project)
            : $this->segment($context, $project);

        if ($recorded === null) {
            return;
        }

        $context->with(self::RESULT, $recorded);

        $next($context);
    }

    /**
     * Die Kopfdaten: wem die Sitzung gehört, wo sie unterwegs war, welche Fehler
     * dabei passierten.
     */
    private function header(ProcessingContext $context, Project $project): ?Replay
    {
        $payload = $context->payload;
        $data = $context->data;

        if ($data === null) {
            $context->drop(DiscardReason::Unreadable, $payload->type->value);

            return null;
        }

        $metadata = ReplayMetadata::fromPayload($data, $payload->event_id);

        if ($metadata === null) {
            $this->log($payload, 'Kopfdaten ohne Nummer der Aufzeichnung.');
            $context->drop(DiscardReason::Unreadable, $payload->type->value);

            return null;
        }

        $replay = Replay::findOrStart(
            $project,
            $metadata->replayId,
            $metadata->attributes($payload->created_at?->toImmutable() ?? CarbonImmutable::now()),
        );

        $metadata->applyTo($replay);
        $this->touch($replay, $metadata->timestamp);
        $replay->save();

        // Die Fehler der Sitzung. Sie kommen mit **jedem** Satz Kopfdaten erneut
        // und wachsen dabei an; doppelt gemeldete Verknüpfungen fängt
        // {@see ReplayError::link()} ab.
        foreach ($metadata->errorIds as $eventId) {
            ReplayError::link($replay, $eventId);
        }

        return $replay;
    }

    /**
     * Ein Abschnitt Film.
     */
    private function segment(ProcessingContext $context, Project $project): ?Replay
    {
        $payload = $context->payload;

        $replayId = IngestPayload::normalizeEventId($payload->event_id);

        if ($replayId === null) {
            // Ein Abschnitt erbt die Nummer aus dem Envelope-Kopf; ohne sie
            // gehört er zu keiner Sitzung, und eine anzulegen hieße, eine zu
            // erfinden.
            $this->log($payload, 'Abschnitt ohne Nummer der Aufzeichnung.');
            $context->drop(DiscardReason::Unreadable, $payload->type->value);

            return null;
        }

        $recording = ReplayRecording::fromBytes(
            $payload->bytes(),
            self::headerSegmentId($payload),
            max(1, (int) config('replays.max_events_per_segment')),
        );

        if ($recording === null) {
            $this->log($payload, 'Abschnitt ohne lesbare Bilddaten.');
            $context->drop(DiscardReason::Unreadable, $payload->type->value);

            return null;
        }

        $replay = Replay::findOrStart($project, $replayId, [
            // Was ein Abschnitt allein weiß. Kommt er vor den Kopfdaten an —
            // der Regelfall bei mehreren Arbeitern —, stehen Umgebung und
            // Version hier zunächst auf der Vorgabe des Projekts und werden
            // nachgetragen, sobald die Kopfdaten eintreffen.
            'environment' => Environment::forName($project)->name,
            'started_at' => $recording->startedAt,
            'last_segment_at' => $recording->endedAt,
        ]);

        if (! $this->withinLimits($replay, $recording, $context)) {
            return null;
        }

        // Die verlässlichste Auskunft über die Maskierung steckt in den
        // Einstellungen, die das Replay-SDK in seinen ersten Abschnitt legt.
        // Sie schlägt die Kopfdaten, in denen die Angabe gar nicht vorgesehen
        // ist — deshalb steht sie hier und nicht dort.
        $masked = ReplayTimeline::maskingFrom($recording->events);

        if ($masked === false && (bool) config('replays.require_masking')) {
            $this->log($payload, 'Abschnitt ohne Maskierung, und der Betreiber verlangt sie.');
            $context->drop(DiscardReason::Scrubbed, $payload->type->value);

            return null;
        }

        if ($masked !== null) {
            $replay->masked = $replay->masked && $masked;
        }

        $this->touch($replay, $recording->endedAt);

        $this->store->put($replay, $recording, $payload);

        if ($recording->droppedEvents > 0) {
            Log::info('Abschnitt einer Aufzeichnung gekürzt.', [
                'projekt' => $payload->project_id,
                'meldung' => $payload->id,
                'aufzeichnung' => $replayId,
                'abschnitt' => $recording->segmentId,
                'uebersprungen' => $recording->droppedEvents,
            ]);
        }

        return $replay;
    }

    /**
     * Passt der Abschnitt noch in die Grenzen dieser Aufzeichnung?
     *
     * Verworfen wird der **Abschnitt**, nicht die Sitzung. Das ist die
     * wichtigere Hälfte der Entscheidung: der interessante Teil einer
     * Aufzeichnung ist ihr Anfang, und eine Sitzung wegzuwerfen, weil sie zu
     * lange lief, hieße genau den Teil zu verlieren, dessentwegen jemand
     * hinsieht.
     */
    private function withinLimits(Replay $replay, ReplayRecording $recording, ProcessingContext $context): bool
    {
        $maxSegments = max(1, (int) config('replays.max_segments'));
        $maxBytes = max(1, (int) config('replays.max_total_bytes'));

        // Ein bereits vorhandener Abschnitt zählt nicht neu: ein
        // Wiederholungslauf derselben Rohdaten ersetzt ihn, und ihn gegen die
        // Grenze zu rechnen hieße, eine Sitzung durch ihre eigene Wiederholung
        // zu sprengen.
        $replaces = $replay->segments()->where('segment_id', $recording->segmentId)->exists();

        if (! $replaces && $replay->segment_count >= $maxSegments) {
            $context->drop(DiscardReason::TooManyItems, IngestType::ReplayRecording->value);

            return false;
        }

        if (! $replaces && $replay->size_bytes >= $maxBytes) {
            $context->drop(DiscardReason::TooLarge, IngestType::ReplayRecording->value);

            return false;
        }

        return true;
    }

    /**
     * Schiebt das Ende der Sitzung nach hinten und nimmt einen gesetzten
     * Schlusspunkt zurück.
     *
     * Der zweite Teil ist der wichtige: eine Sitzung gilt nach einer Pause als
     * beendet, und ein Nutzer, der nach zwanzig Minuten zurückkommt, macht sie
     * wieder auf. Bliebe `finished_at` stehen, zeigte die Liste eine beendete
     * Sitzung, die weiterläuft.
     */
    private function touch(Replay $replay, ?CarbonImmutable $at): void
    {
        if ($at !== null && $at->greaterThan($replay->last_segment_at)) {
            $replay->last_segment_at = $at;
        }

        $replay->finished_at = null;
    }

    /**
     * Die Abschnittsnummer aus dem Kopf des Envelope-Elements.
     *
     * Der Rückfall für Abschnitte ohne eigene Kopfzeile: ältere SDKs schreiben
     * die Nummer nur dorthin.
     */
    private static function headerSegmentId(IngestPayload $payload): ?int
    {
        $segmentId = $payload->item_headers['segment_id'] ?? null;

        return is_int($segmentId) || (is_string($segmentId) && ctype_digit($segmentId))
            ? (int) $segmentId
            : null;
    }

    /**
     * Die Aufbewahrungsfrist dieses Projekts in Tagen.
     *
     * `null` am Projekt heißt „die Vorgabe des Betreibers" — dieselbe Auslegung
     * wie überall, wo ein Projekt eine Betreiber-Einstellung überschreiben darf.
     */
    public static function retentionDays(Project $project): int
    {
        return max(0, $project->replay_retention_days ?? (int) config('replays.retention_days'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(IngestPayload $payload, string $message, array $context = []): void
    {
        Log::warning('Aufzeichnung nicht abgelegt: '.$message, $context + [
            'projekt' => $payload->project_id,
            'meldung' => $payload->id,
        ]);
    }
}
