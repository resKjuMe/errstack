<?php

namespace App\Support\Performance;

use App\Models\Environment;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Models\TransactionSpan;
use Illuminate\Support\Facades\DB;

/**
 * Legt eine gemessene Transaktion samt Einzelschritten ab und schreibt die
 * Vorberechnung fort.
 *
 * Die drei Schreibvorgänge gehören in **eine** Datenbank-Transaktion: eine
 * Messung ohne ihre Schritte ist eine Zahl ohne Erklärung, und eine Messung, die
 * in der Vorberechnung fehlt, ist in der Übersicht unsichtbar. Bricht etwas ab,
 * soll nichts davon dastehen — die Meldung wird dann wiederholt.
 *
 * Ein zweiter Durchlauf derselben Meldung ändert die vorhandene Zeile und zählt
 * die Vorberechnung **nicht** erneut hoch. Dieser Fall ist nicht theoretisch: ein
 * gescheiterter Job wird wiederholt, und nach einer Änderung an der Verarbeitung
 * sollen sich die Rohdaten erneut durchlaufen lassen ({@see IngestPayload}).
 */
final class TransactionStore
{
    /**
     * @return Transaction Die abgelegte Messung.
     */
    public function store(TransactionEvent $event, Project $project, ?IngestPayload $payload = null): Transaction
    {
        // Die Umgebung wird außerhalb der Datenbank-Transaktion erfasst: sie ist
        // projektweiter Bestand und keine Messung. Läge sie mit drin, würde ein
        // Abbruch beim Ablegen der Schritte eine bereits gesehene Umgebung wieder
        // aus der Filterleiste entfernen.
        $environment = Environment::record($project, $event->environment, $event->startedAt);

        return DB::transaction(function () use ($event, $project, $payload, $environment): Transaction {
            $existing = Transaction::query()
                ->where('project_id', $project->id)
                ->where('event_id', $event->eventId)
                ->first();

            $transaction = $existing ?? new Transaction;

            $transaction->project_id = $project->id;
            $transaction->ingest_payload_id = $payload?->id;
            $transaction->event_id = $event->eventId;
            $transaction->trace_id = $event->traceId;
            $transaction->span_id = $event->spanId;
            $transaction->parent_span_id = $event->parentSpanId;
            $transaction->name = $event->name;
            $transaction->op = $event->op;
            $transaction->source = $event->source;
            $transaction->status = $event->status;
            $transaction->platform = $event->platform;
            $transaction->environment = $environment->name;
            $transaction->release = $event->release;
            $transaction->user_identifier = $event->userIdentifier;
            $transaction->started_at = $event->startedAt;
            $transaction->finished_at = $event->finishedAt;
            $transaction->duration_us = $event->durationUs;
            $transaction->span_count = count($event->spans);
            $transaction->measurements = $event->measurements;
            $transaction->save();

            $this->storeSpans($transaction, $event, $existing !== null);

            if ($existing === null) {
                // Nur beim ersten Mal. Ein erneuter Durchlauf derselben Meldung
                // würde die Anzahl sonst ein zweites Mal erhöhen — und eine
                // Übersicht, die eine wiederholte Verarbeitung als doppelten
                // Verkehr ausweist, führt in die Irre.
                TransactionAggregate::record($transaction);
            }

            return $transaction;
        });
    }

    /**
     * Legt die Einzelschritte ab.
     *
     * In **einer** Einfügung, nicht in einer je Schritt: bei hundert Schritten
     * sind das hundert Netzwerkumläufe, und genau daran hängt die Zusage, dass
     * auch eine Transaktion mit hundert Schritten ohne Zeitüberschreitung
     * durchläuft.
     */
    private function storeSpans(Transaction $transaction, TransactionEvent $event, bool $replacing): void
    {
        if ($replacing) {
            // Ersetzen statt ergänzen: die Schritte sind kein Zuwachs, sondern
            // dieselbe Meldung noch einmal. Zusammenführen hieße, jeden Schritt
            // einzeln abzugleichen, um am Ende dasselbe zu haben.
            $transaction->spans()->delete();
        }

        if ($event->spans === []) {
            return;
        }

        // Zeitpunkte hier selbst formatiert: eine Massen-Einfügung geht am Model
        // vorbei, und der Abfrage-Erzeuger schreibt `Y-m-d H:i:s` — die
        // Millisekunden, um derer willen die Spalten Bruchteile haben, fielen
        // damit weg (siehe {@see Transaction::$dateFormat}).
        $format = 'Y-m-d H:i:s.v';
        $now = now()->format($format);
        $rows = [];

        foreach ($event->spans as $position => $span) {
            $rows[] = [
                'transaction_id' => $transaction->id,
                'project_id' => $transaction->project_id,
                // Der Trace des Schritts ist der der Transaktion. Eine
                // abweichende Angabe im Element wäre eine Fehlmeldung des SDK und
                // würde den Schritt aus dem Ablauf herausfallen lassen, in dem er
                // gemessen wurde.
                'trace_id' => $transaction->trace_id,
                'span_id' => $span->spanId,
                // Ohne Elternteil hängt der Schritt an der Transaktion selbst.
                // Das ist die häufigste Meldung überhaupt — die meisten SDKs
                // setzen die Angabe nur bei verschachtelten Schritten.
                'parent_span_id' => $span->parentSpanId ?? $transaction->span_id,
                'op' => $span->op,
                'description' => $span->description,
                'status' => $span->status,
                'started_at' => $span->startedAt->format($format),
                'finished_at' => $span->finishedAt->format($format),
                'duration_us' => $span->durationUs,
                'data' => $span->data === null ? null : json_encode($span->data),
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TransactionSpan::query()->insert($rows);
    }
}
