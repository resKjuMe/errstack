<?php

namespace App\Support\Ingest\Quotas;

use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Zählt den Verbrauch — je Minute und je Monat, im Zwischenspeicher.
 *
 * **Nicht in der Datenbank**, und das ist die eine Entscheidung, die diese
 * Klasse trägt: gezählt wird bei jeder eingehenden Meldung, und eine Meldung
 * kommt in genau der Lage herein, in der die überwachte Anwendung ohnehin
 * schon Mühe hat. Ein Schreibvorgang je Meldung wäre eine zweite Datenbank-Last
 * neben der Ablage selbst — und die Begrenzung soll Last **nehmen**.
 *
 * Was das kostet, ist derselbe Handel wie bei den Stichproben
 * ({@see App\Support\Ingest\Sampling\WindowCounter}): geht der
 * Zwischenspeicher verloren, beginnt die Zählung von vorn, und es kommt
 * **mehr** herein als vorgesehen. Der Fehler geht damit in die harmlose
 * Richtung — zu viele Daten statt einer Anwendung, die stumm bleibt, weil ein
 * Neustart ihren Zähler auf „aufgebraucht" gestellt hat.
 *
 * Was **nicht** verloren gehen darf, steht deshalb nicht hier: die Verwerfungen
 * ({@see App\Models\IngestDiscard}) und die verschickten Warnungen
 * ({@see App\Models\Quota}) stehen in der Datenbank.
 */
final class QuotaCounter
{
    /**
     * Wie lange ein Minutenzähler aufbewahrt wird.
     *
     * Zwei Minuten für ein Fenster von einer: der Schlüssel enthält die Minute,
     * ein abgelaufenes Fenster wird also nie wieder gelesen. Die zusätzliche
     * Minute ist der Spielraum für Uhren, die zwischen mehreren
     * Anwendungsservern auseinanderlaufen.
     */
    private const MINUTE_TTL = 120;

    /**
     * Wie lange ein Monatszähler aufbewahrt wird: gut fünf Wochen. Auch hier
     * steht der Zeitraum im Schlüssel; der Vorhalt muss nur den längsten Monat
     * überdauern.
     */
    private const MONTH_TTL = 40 * 86400;

    public function minuteUsage(QuotaScope $scope, int $scopeId, ?QuotaCategory $category): int
    {
        return $this->read($this->minuteKey($scope, $scopeId, $category));
    }

    public function monthUsage(QuotaScope $scope, int $scopeId, ?QuotaCategory $category): int
    {
        return $this->read($this->monthKey($scope, $scopeId, $category));
    }

    /**
     * Bucht einen Verbrauch auf den Minutenzähler.
     */
    public function addMinute(QuotaScope $scope, int $scopeId, ?QuotaCategory $category, int $quantity): int
    {
        return $this->add($this->minuteKey($scope, $scopeId, $category), $quantity, self::MINUTE_TTL);
    }

    /**
     * Bucht einen Verbrauch auf den Monatszähler und gibt den neuen Stand
     * zurück — den braucht die Warnung, um die Schwelle zu erkennen.
     */
    public function addMonth(QuotaScope $scope, int $scopeId, ?QuotaCategory $category, int $quantity): int
    {
        return $this->add($this->monthKey($scope, $scopeId, $category), $quantity, self::MONTH_TTL);
    }

    /**
     * Sekunden bis zum Ende der laufenden Minute — die Wartezeit, die einer
     * gerissenen Rate angemessen ist.
     *
     * Mindestens eine: eine Antwort „versuch es in 0 Sekunden" ist eine
     * Aufforderung, es sofort wieder zu tun.
     */
    public function secondsUntilNextMinute(): int
    {
        return max(1, 60 - (int) Carbon::now()->second);
    }

    /**
     * Sekunden bis zum Monatsersten — die Wartezeit eines aufgebrauchten
     * Kontingents.
     *
     * Ehrlich und unbequem: wer sein Monatskontingent am Zwölften verbraucht
     * hat, wartet nicht zwölf Sekunden. Eine kürzere Angabe wäre freundlicher
     * und würde dazu führen, dass ein SDK bis zum Monatsende alle paar Sekunden
     * anklopft.
     */
    public function secondsUntilNextMonth(): int
    {
        $now = Carbon::now();

        return max(1, (int) $now->diffInSeconds($now->copy()->addMonthNoOverflow()->startOfMonth(), true));
    }

    /**
     * Der laufende Abrechnungszeitraum (`2026-08`) — dieselbe Angabe, unter der
     * eine Warnung vermerkt wird.
     */
    public function period(): string
    {
        return Carbon::now()->format('Y-m');
    }

    private function read(string $key): int
    {
        $value = Cache::get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function add(string $key, int $quantity, int $ttl): int
    {
        if ($quantity < 1) {
            return $this->read($key);
        }

        // Erst anlegen, dann hochzählen — wie bei den Stichproben: manche
        // Zwischenspeicher legen bei einem `increment` auf einen fehlenden
        // Schlüssel selbst einen an, dann aber ohne Verfallszeit. Der
        // Monatszähler von heute stünde in einem Jahr noch da.
        Cache::add($key, 0, $ttl);

        $value = Cache::increment($key, $quantity);

        return is_int($value) && $value > 0 ? $value : $quantity;
    }

    private function minuteKey(QuotaScope $scope, int $scopeId, ?QuotaCategory $category): string
    {
        return $this->key('m', $scope, $scopeId, $category).':'.Carbon::now()->format('YmdHi');
    }

    private function monthKey(QuotaScope $scope, int $scopeId, ?QuotaCategory $category): string
    {
        return $this->key('M', $scope, $scopeId, $category).':'.$this->period();
    }

    private function key(string $window, QuotaScope $scope, int $scopeId, ?QuotaCategory $category): string
    {
        return 'quota:'.$window.':'.$scope->value.':'.$scopeId.':'.($category?->value ?? 'all');
    }
}
