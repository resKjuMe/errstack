<?php

namespace App\Support\Issues;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Release;
use Carbon\CarbonImmutable;

/**
 * Die Bedingung, unter der ein erledigter Fehler als zurückgekommen gilt — und
 * ihre Auswertung.
 *
 * **Ohne Datenbank**, wie die Schwester nebenan ({@see IgnoreCondition}) und aus
 * demselben Grund: die Frage wird bei **jedem** eingehenden Ereignis eines
 * erledigten Eintrags gestellt, und eine Abfrage an dieser Stelle wäre bei einer
 * Fehlerflut die Last, die niemand vermutet. Sie bekommt zwei Auslieferungen und
 * eine Uhr und gibt ein Urteil; nachgeschlagen wird vorher, vom Aufrufer.
 *
 * **Zwei Prüfungen, und beide müssen zutreffen:**
 *
 *   1. **Die Meldung ist jünger als die Erledigung.** Ein SDK, das nach einer
 *      Netztrennung seine Warteschlange leert, liefert Stunden später
 *      Ereignisse von vorhin — die haben nichts damit zu tun, ob der Fehler
 *      behoben ist. Ohne diese Prüfung würde jedes Erledigen von einer
 *      nachgereichten alten Meldung wieder aufgehoben, und niemand käme je
 *      dazu, seine Liste leer zu bekommen.
 *   2. **Eine neuere Auslieferung ist betroffen** — aber nur, wenn beim
 *      Erledigen überhaupt eine genannt wurde. „Erledigt in 1.4.2" und
 *      „erledigt mit der nächsten Auslieferung" sind beide eine Aussage über
 *      eine Fassung: dass der Fehler aus 1.4.2 noch gemeldet wird, ist kein
 *      Widerspruch, sondern die alte Fassung, die noch läuft. Erst eine
 *      **jüngere** Fassung widerlegt die Behauptung ({@see Release::isNewerThan()}).
 *
 * **Ohne Versionsangabe an der Meldung gibt es keinen Rückfall**, solange die
 * Erledigung an eine Fassung gebunden war. Das ist die vorsichtige und die
 * einzig ehrliche Wahl: gefordert ist eine neuere Fassung, und „keine Angabe"
 * ist keine. Ein Eintrag, der versehentlich wieder aufgeht, kostet mehr als
 * einer, der einen Tag zu spät aufgeht — er weckt jemanden.
 *
 * Wurde dagegen ohne Fassung erledigt („erledigt", sonst nichts), genügt die
 * erste Prüfung: die Behauptung war „das ist ab jetzt weg", und jede spätere
 * Meldung widerlegt sie.
 */
final class RegressionCondition
{
    public function __construct(
        /** Woran der Eintrag gerade ist — nur ein erledigter kann zurückkommen. */
        public readonly IssueStatus $status,
        /** Wann er erledigt wurde. */
        public readonly ?CarbonImmutable $resolvedAt,
        /** Die Fassung, in der er als behoben gilt — `null`, wenn ohne. */
        public readonly ?int $resolvedInReleaseId,
    ) {}

    public static function fromIssue(Issue $issue): self
    {
        return new self(
            status: $issue->status,
            resolvedAt: $issue->resolved_at,
            resolvedInReleaseId: $issue->resolved_in_release_id,
        );
    }

    /**
     * Kann dieser Eintrag überhaupt zurückkommen?
     *
     * Die Abkürzung für den Regelfall: ein offener oder stummgeschalteter
     * Eintrag ist nicht erledigt, und damit erübrigt sich alles Weitere —
     * insbesondere das Nachladen der beiden Auslieferungen.
     */
    public function isPossible(): bool
    {
        return $this->status === IssueStatus::Resolved && $this->resolvedAt !== null;
    }

    /**
     * Ist dieses Ereignis ein Rückfall?
     *
     * @param  CarbonImmutable  $occurredAt  Wann die Meldung entstanden ist — die
     *                                       Uhr der überwachten Anwendung, wie
     *                                       überall im Aufnahmeweg.
     * @param  Release|null  $seenIn  Die Fassung, aus der sie kam.
     * @param  Release|null  $resolvedIn  Die Fassung, in der erledigt wurde —
     *                                    muss zu {@see $resolvedInReleaseId}
     *                                    gehören.
     */
    public function evaluate(CarbonImmutable $occurredAt, ?Release $seenIn, ?Release $resolvedIn): bool
    {
        if (! $this->isPossible()) {
            return false;
        }

        if ($occurredAt->lessThanOrEqualTo($this->resolvedAt)) {
            return false;
        }

        if ($this->resolvedInReleaseId === null) {
            return true;
        }

        // Die Fassung wurde inzwischen gelöscht: die Bedingung, gegen die zu
        // prüfen wäre, gibt es nicht mehr. Dann gilt wieder die einfache
        // Aussage „ab jetzt weg", und die ist widerlegt.
        if ($resolvedIn === null) {
            return true;
        }

        return $seenIn !== null && $seenIn->isNewerThan($resolvedIn);
    }
}
