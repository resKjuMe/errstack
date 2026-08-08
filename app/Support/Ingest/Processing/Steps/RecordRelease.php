<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Models\Event;
use App\Models\Issue;
use App\Models\Release;
use App\Models\Transaction;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Erfasst die ausgelieferte Version einer Meldung und verbindet den
 * Fehler-Eintrag damit.
 *
 * Die Versionsangabe steht am Ereignis, seit die Meldungen vereinheitlicht
 * werden (I4) — hier wird aus ihr ein Gegenstand: eine Zeile in `releases`,
 * mit erstem und letztem Auftreten, und am Fehler-Eintrag die beiden Verweise
 * „zuerst gesehen in" und „zuletzt aufgetreten in".
 *
 * **Er steht am Ende der Kette**, hinter dem Zusammenfassen zum Eintrag (I6),
 * und das aus zwei Gründen. Der erste ist die Reihenfolge: die Verknüpfung
 * braucht den Eintrag, und den gibt es erst dort. Der zweite ist derselbe wie
 * beim Zählen — erfasst werden darf nur, was auch bleibt. Stünde der Schritt
 * vor dem Eingangsfilter (I8), stünden Versionen in der Liste, aus denen
 * niemand je eine Meldung behalten hat.
 *
 * **Er fasst beide Arten von Meldungen an.** Eine Version liefert nicht nur
 * Fehler aus, sondern auch Antwortzeiten: eine Auslieferung, aus der bislang
 * nur Transaktionen eintrafen, ist eine erfolgreiche Auslieferung und gehört
 * genauso in die Liste. Zum Fehler-Eintrag verbunden wird trotzdem nur, wenn es
 * einen gibt — eine Transaktion ist kein Fehler.
 */
final class RecordRelease implements ProcessingStep
{
    /**
     * Name, unter dem die erfasste Version im Kontext steht.
     */
    public const RESULT = 'release';

    public function handle(ProcessingContext $context, Closure $next): void
    {
        [$version, $occurred] = self::source($context);

        if ($version === null || $occurred === null) {
            // Keine Versionsangabe — der Regelfall bei einem SDK, das ohne
            // `release` konfiguriert ist. Durchreichen und **nichts** anlegen:
            // eine Ersatzversion („unbekannt") ließe sich später von einer
            // echten nicht mehr unterscheiden.
            $next($context);

            return;
        }

        $release = Release::record($context->payload->project_id, $version, $occurred);

        if ($release === null) {
            $next($context);

            return;
        }

        $issue = $context->get(AggregateIssue::RESULT);

        if ($issue instanceof Issue) {
            $issue->linkRelease($release, $occurred);
        }

        $context->with(self::RESULT, $release);

        $next($context);
    }

    /**
     * Versionsangabe und Zeitpunkt der Meldung.
     *
     * Beides stammt vom abgelegten Datensatz und nicht aus dem rohen Rumpf: der
     * ist zu diesem Zeitpunkt schon bereinigt (I7) und vereinheitlicht (I4), und
     * was dort steht, ist das, was auch am Ereignis steht. Aus dem Rumpf gelesen
     * wäre es dieselbe Angabe auf einem zweiten Weg — mit der Aussicht, dass
     * beide Wege eines Tages auseinanderlaufen.
     *
     * Der Zeitpunkt ist die Uhr der überwachten Anwendung (`occurred_at`,
     * `started_at`) und nicht unsere Empfangszeit: „seit wann läuft diese
     * Version?" meint die Uhr dort.
     *
     * @return array{0: string|null, 1: CarbonImmutable|null}
     */
    private static function source(ProcessingContext $context): array
    {
        $record = $context->get(NormalizeEvent::RESULT.'_record');

        if ($record instanceof Event) {
            return [$record->release, CarbonImmutable::parse($record->occurred_at)];
        }

        $transaction = $context->get(RecordTransaction::RESULT);

        if ($transaction instanceof Transaction) {
            return [$transaction->release, CarbonImmutable::parse($transaction->started_at)];
        }

        return [null, null];
    }
}
