<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\IngestType;
use App\Models\Environment;
use App\Models\IngestPayload;
use App\Models\Replay;
use App\Models\ReplayError;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Performance\PayloadReader;
use Closure;

/**
 * Hält fest, dass ein Fehler während einer laufenden Aufzeichnung passiert ist.
 *
 * Die Gegenrichtung zu {@see RecordReplay}: dort meldet die Aufzeichnung ihre
 * Fehler, hier meldet der Fehler seine Aufzeichnung. Beide Wege werden gegangen,
 * weil beide Lücken haben — die Kopfdaten einer noch laufenden Sitzung kennen
 * den Fehler von eben noch nicht, und nicht jedes SDK trägt jeden Fehler in
 * seine Aufzeichnung ein. Zusammen decken sie ab, was einzeln durchfiele.
 *
 * **Der Schritt sortiert nie aus.** Eine fehlende oder unlesbare Angabe zur
 * Aufzeichnung ist kein Mangel des Fehlers: die allermeisten Meldungen kommen
 * ohne, weil in der überwachten Anwendung gerade nichts aufgezeichnet wird. Er
 * reicht deshalb immer weiter.
 *
 * Er steht in der Kette hinter der Ablage der Aufzeichnungen und **vor** der
 * Normalisierung: gebraucht wird nur die Nummer aus dem gemeldeten Feld-Baum,
 * und die steht dort in der Form, die das SDK geschickt hat.
 */
final class LinkEventReplay implements ProcessingStep
{
    /**
     * Name, unter dem die verknüpfte Aufzeichnung im Kontext steht.
     */
    public const RESULT = 'linked_replay';

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;

        if ($payload->type !== IngestType::Event) {
            $next($context);

            return;
        }

        $replayId = self::replayId($context->data ?? []);
        $project = $payload->project;
        $eventId = IngestPayload::normalizeEventId($payload->event_id);

        if ($replayId === null || $project === null || $eventId === null) {
            $next($context);

            return;
        }

        // Angelegt, wenn es die Sitzung noch nicht gibt.
        //
        // Das ist der unschöne, aber richtige Teil: Fehler und Aufzeichnung
        // kommen als eigene Jobs, und der Fehler ist regelmäßig zuerst da. Ohne
        // eine Zeile, an der die Verknüpfung hängen kann, wäre sie verloren —
        // und zwar genau in dem Fall, für den die ganze Funktion gebaut wurde:
        // „zeig mir, was vor **diesem** Fehler passiert ist".
        //
        // Eine solche Zeile hat noch keinen einzigen Abschnitt. Sie taucht
        // deshalb in keiner Liste auf: gezeigt werden nur Aufzeichnungen mit
        // Bilddaten ({@see Replay::scopePlayable()}). Kommt die Aufnahme nach,
        // füllt sie sich; kommt sie nie (das SDK zeichnet nicht auf, die
        // Stichprobe hat nicht zugeschlagen), räumt die Aufbewahrungsfrist sie
        // weg wie jede andere.
        $occurredAt = PayloadReader::time($context->data['timestamp'] ?? null)
            ?? $payload->created_at->toImmutable();

        $replay = Replay::findOrStart($project, $replayId, [
            'environment' => Environment::normalizeName(PayloadReader::text($context->data['environment'] ?? null, 64))
                ?? Environment::forName($project)->name,
            'started_at' => $occurredAt,
            'last_segment_at' => $occurredAt,
        ]);

        ReplayError::link($replay, $eventId, $occurredAt);

        $context->with(self::RESULT, $replay);

        $next($context);
    }

    /**
     * Die Nummer der Aufzeichnung aus einer Fehlermeldung.
     *
     * Zwei Stellen, weil die SDKs sie an zweien führen: im Replay-Kontext (der
     * ausführliche Weg) und als Marke `replayId` (der kurze, den die Suche
     * ohnehin braucht). Wer nur eine liest, verliert je nach SDK-Fassung alles.
     *
     * @param  array<mixed>  $data
     */
    private static function replayId(array $data): ?string
    {
        $contexts = PayloadReader::map($data['contexts'] ?? null) ?? [];
        $replay = PayloadReader::map($contexts['replay'] ?? null) ?? [];
        $tags = PayloadReader::map($data['tags'] ?? null) ?? [];

        return PayloadReader::hex($replay['replay_id'] ?? null, 32)
            ?? PayloadReader::hex($tags['replayId'] ?? null, 32);
    }
}
