<?php

namespace App\Support\Operations;

use App\Console\Commands\OperationsWatchCommand;
use App\Enums\BacklogAction;
use App\Support\Ingest\Processing\ProcessingMetrics;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * Der Wächter über den Rückstand: stellt fest, ob die Verarbeitung
 * hinterherhängt — und ob das lange genug so ist, um den Betreiber zu wecken.
 *
 * Der Rückstand ist der eine Zustand, den niemand meldet. Ein Fehler meldet
 * sich, ein Ausfall fällt auf; eine Warteschlange, die langsam volläuft, sieht
 * von außen aus wie Betrieb — bis Nutzer fragen, warum ihre Fehler fehlen.
 *
 * Gemessen wird an zwei Größen, weil einzeln keine trägt: die **Menge** schlägt
 * bei jedem Ansturm an, auch bei einem, der in Sekunden abgearbeitet ist; das
 * **Alter** der ältesten wartenden Meldung bleibt still, solange eine einzige
 * alte quer liegt. Über der Schwelle ist, wer eine von beiden reißt.
 *
 * Entscheidend ist die Frist davor: gewarnt wird erst, wenn die Schwelle
 * ununterbrochen `grace_minutes` lang überschritten ist. Eine Warnung, die bei
 * jeder Lastspitze kommt, wird nach der dritten weggeklickt — und die vierte
 * war die echte.
 *
 * Der Zustand (seit wann über der Schwelle, wann zuletzt gewarnt) liegt im
 * Zwischenspeicher und nicht in der Datenbank: er ist eine Beobachtung über
 * Minuten, kein Datum. Geht er verloren, beginnt die Frist von vorn — der
 * schlechteste Fall ist eine Warnung, die ein paar Minuten später kommt.
 */
final class BacklogWatch
{
    private const KEY_SINCE = 'operations:backlog:breaching-since';

    private const KEY_WARNED = 'operations:backlog:warned-at';

    /**
     * Wie lange der Zustand überlebt, ohne dass jemand nachsieht. Großzügig
     * über der Wiederholfrist, damit ein Wächter, der eine Runde aussetzt,
     * nicht die halbe Frist verliert.
     */
    private const STATE_TTL_HOURS = 24;

    public function __construct(
        private readonly ProcessingMetrics $metrics,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Der Rückstand, wie er gerade ist — ohne etwas zu merken.
     *
     * Das ist die Auskunft für die Betriebsansicht: sie zeigt an, sie
     * entscheidet nicht.
     *
     * @return array{
     *     pending: int,
     *     oldest_seconds: int|null,
     *     breaching: bool,
     *     reasons: list<string>,
     *     since: Carbon|null,
     *     max_pending: int,
     *     max_age_seconds: int,
     * }
     */
    public function status(): array
    {
        $pending = $this->metrics->backlog();
        $oldest = $this->metrics->oldestPendingSeconds();

        $maxPending = self::maxPending();
        $maxAge = self::maxAgeSeconds();

        $reasons = [];

        if ($pending > $maxPending) {
            $reasons[] = 'pending';
        }

        if ($oldest !== null && $oldest > $maxAge) {
            $reasons[] = 'age';
        }

        return [
            'pending' => $pending,
            'oldest_seconds' => $oldest,
            'breaching' => $reasons !== [],
            'reasons' => $reasons,
            'since' => $this->breachingSince(),
            'max_pending' => $maxPending,
            'max_age_seconds' => $maxAge,
        ];
    }

    /**
     * Dasselbe, aber mit Gedächtnis: schreibt fort, seit wann die Schwelle
     * überschritten ist, und sagt, was daraus folgt.
     *
     * Nur der Wächter ({@see OperationsWatchCommand})
     * ruft das auf. Die Ansicht darf den Zustand nicht verändern — sonst
     * entschiede das Aufrufen einer Seite darüber, wann gewarnt wird.
     *
     * @return array{
     *     action: BacklogAction,
     *     pending: int,
     *     oldest_seconds: int|null,
     *     breaching: bool,
     *     reasons: list<string>,
     *     since: Carbon|null,
     *     max_pending: int,
     *     max_age_seconds: int,
     * }
     */
    public function evaluate(): array
    {
        $status = $this->status();
        $now = Carbon::now();

        if (! $status['breaching']) {
            // Entwarnung nur, wenn vorher gewarnt wurde. Ein Rückstand, der
            // die Frist nie überstanden hat, hat auch nichts, wovon zu
            // entwarnen wäre.
            $action = $this->cache->get(self::KEY_WARNED) === null
                ? BacklogAction::None
                : BacklogAction::Recover;

            $this->cache->forget(self::KEY_SINCE);
            $this->cache->forget(self::KEY_WARNED);

            return [...$status, 'action' => $action, 'since' => null];
        }

        $since = $status['since'];

        if ($since === null) {
            $since = $now;
            $this->cache->put(self::KEY_SINCE, $since->toIso8601String(), $this->stateTtl());
        }

        $status['since'] = $since;

        // Noch in der Frist: die Lage ist schlecht, aber sie war es noch nicht
        // lange genug, um jemanden zu wecken.
        if ($since->diffInMinutes($now, absolute: true) < self::graceMinutes()) {
            return [...$status, 'action' => BacklogAction::None];
        }

        $warnedAt = $this->warnedAt();

        if ($warnedAt !== null && $warnedAt->diffInMinutes($now, absolute: true) < self::repeatMinutes()) {
            return [...$status, 'action' => BacklogAction::None];
        }

        $this->cache->put(self::KEY_WARNED, $now->toIso8601String(), $this->stateTtl());

        return [...$status, 'action' => BacklogAction::Warn];
    }

    private function breachingSince(): ?Carbon
    {
        return self::parse($this->cache->get(self::KEY_SINCE));
    }

    private function warnedAt(): ?Carbon
    {
        return self::parse($this->cache->get(self::KEY_WARNED));
    }

    private static function parse(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    private function stateTtl(): int
    {
        return self::STATE_TTL_HOURS * 3600;
    }

    private static function maxPending(): int
    {
        return max(0, (int) config('operations.backlog.max_pending', 1000));
    }

    private static function maxAgeSeconds(): int
    {
        return max(1, (int) config('operations.backlog.max_age_seconds', 300));
    }

    private static function graceMinutes(): int
    {
        return max(0, (int) config('operations.backlog.grace_minutes', 5));
    }

    private static function repeatMinutes(): int
    {
        return max(1, (int) config('operations.backlog.repeat_minutes', 60));
    }
}
