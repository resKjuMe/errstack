<?php

namespace App\Support\Ingest\Quotas;

use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Jobs\WarnAboutQuota;
use App\Models\Quota;

/**
 * Meldet, wenn ein Monatskontingent zur Neige geht.
 *
 * Zwei Schwellen, und beide werden gebraucht: bei 80 % ist noch Zeit, das
 * Kontingent anzuheben oder eine gesprächige Anwendung zu drosseln — bei 100 %
 * ist die Meldung keine Warnung mehr, sondern die Erklärung dafür, dass gerade
 * nichts mehr ankommt. Wer nur die zweite verschickt, erfährt vom Problem in
 * dem Moment, in dem er es nicht mehr abwenden kann.
 *
 * **Genau einmal je Schwelle und Monat.** Geprüft wird bei jeder aufgenommenen
 * Meldung; ohne diese Zusage bekäme die Verwaltung ab 80 % eine Nachricht je
 * Ereignis. Der Vermerk steht in der Datenbank und nicht im Zwischenspeicher —
 * die Zahlen dürfen einen Neustart nicht überleben müssen, die Nachricht darf
 * sich davon nicht wiederholen lassen.
 */
final class QuotaWarnings
{
    /**
     * Die Schwellen, absteigend geprüft: wer mit einem Schwung Elemente von
     * 79 % auf 100 % springt, soll die Nachricht bekommen, die zutrifft, und
     * nicht die, die er übersprungen hat.
     *
     * @var list<int>
     */
    public const THRESHOLDS = [100, 80];

    public function __construct(private readonly QuotaCounter $counter) {}

    public function evaluate(QuotaScope $scope, int $scopeId, QuotaCategory $category, int $usage): void
    {
        $limit = QuotaLimits::for($scope, $scopeId, $category);

        if ($limit === null) {
            return;
        }

        $perMonth = $limit['month'];

        if ($perMonth === null || $perMonth < 1) {
            return;
        }

        $percent = (int) floor($usage / $perMonth * 100);

        foreach (self::THRESHOLDS as $threshold) {
            if ($percent < $threshold) {
                continue;
            }

            // Beansprucht der Aufruf die Schwelle nicht, war jemand anders
            // schneller — oder es wurde in diesem Monat bereits davor gewarnt.
            // Beides heißt: hier ist nichts mehr zu tun.
            if (Quota::claimWarning($limit['id'], $this->counter->period(), $threshold)) {
                WarnAboutQuota::dispatch($limit['id'], $threshold, $usage, $perMonth);
            }

            return;
        }
    }
}
