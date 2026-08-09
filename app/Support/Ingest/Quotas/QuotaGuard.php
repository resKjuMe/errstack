<?php

namespace App\Support\Ingest\Quotas;

use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Models\ProjectKey;

/**
 * Entscheidet, ob noch etwas hereindarf — und bucht, was hereingekommen ist.
 *
 * Geprüft wird auf drei Ebenen nebeneinander, nicht nacheinander verfeinert:
 * der Schlüssel bringt seine eigene Rate mit (die Notbremse für eine einzelne
 * durchdrehende Anwendung), Projekt und Organisation begrenzen je Datenart in
 * der Minute und im Monat. Die **engste** Grenze entscheidet; welche es war,
 * steht im Ergebnis, weil die Antwort darauf eine andere ist — ein zu kleines
 * Projekt-Kontingent hebt man an, eine durchdrehende Anwendung repariert man.
 *
 * **Prüfen und buchen sind getrennt.** Die Datenaufnahme weiß erst nach dem
 * Zerlegen, wie viele Elemente welcher Datenart ein Envelope trug; die
 * klassische Meldung ist von vornherein genau eine. Beides in einem Aufruf
 * ginge deshalb nur, indem man den Envelope vor der Prüfung zerlegt — also
 * genau die Arbeit tut, die die Prüfung sparen soll.
 *
 * Daraus folgt eine bewusste Ungenauigkeit: zwischen Prüfung und Buchung
 * laufen weitere Anfragen durch, und bei gleichzeitigem Andrang kommt eine
 * Handvoll Meldungen über die Grenze hinaus herein. Das ist der richtige
 * Fehler. Die Gegenrichtung — erst buchen, dann prüfen — würde bei jedem
 * abgewiesenen Element trotzdem Kontingent verbrauchen und ein Projekt, das
 * seine Grenze reißt, dauerhaft stumm schalten.
 */
final class QuotaGuard
{
    public function __construct(
        private readonly QuotaCounter $counter,
        private readonly QuotaWarnings $warnings,
    ) {}

    /**
     * Darf noch etwas dieser Datenart über diesen Schlüssel herein?
     *
     * Ohne Datenart (`null`) wird nur die Rate des Schlüssels geprüft — das ist
     * der Fall des Envelope, dessen Inhalt vor dem Zerlegen niemand kennt.
     */
    public function check(ProjectKey $key, ?QuotaCategory $category, int $quantity = 1): QuotaVerdict
    {
        $keyVerdict = $this->checkKey($key, $quantity);

        if ($keyVerdict->denied()) {
            return $keyVerdict;
        }

        if ($category === null) {
            return QuotaVerdict::allow();
        }

        $projectVerdict = $this->checkScope(QuotaScope::Project, $key->project_id, $category, $quantity);

        if ($projectVerdict->denied()) {
            return $projectVerdict;
        }

        $organizationId = QuotaLimits::organizationId($key->project_id);

        if ($organizationId === null) {
            return QuotaVerdict::allow();
        }

        return $this->checkScope(QuotaScope::Organization, $organizationId, $category, $quantity);
    }

    /**
     * Bucht, was durchgelassen wurde — auf allen drei Ebenen.
     *
     * Was eine Grenze abgewiesen hat, wird **nicht** gebucht: sonst bliebe ein
     * Projekt, das einmal darüber war, bis zum Monatsersten stumm, auch wenn
     * die Grenze längst angehoben ist. Was die Aufnahme danach aus einem
     * anderen Grund verwirft — unlesbarer Rumpf, unbekannter Typ —, ist
     * dagegen gebucht: die Meldung war hier, und die Grenze soll die Menge
     * begrenzen, die ankommt, nicht die, die brauchbar war.
     */
    public function consume(ProjectKey $key, ?QuotaCategory $category, int $quantity = 1): void
    {
        if ($quantity < 1) {
            return;
        }

        $this->counter->addMinute(QuotaScope::Key, $key->id, null, $quantity);

        if ($category === null) {
            return;
        }

        $this->consumeScope(QuotaScope::Project, $key->project_id, $category, $quantity);

        $organizationId = QuotaLimits::organizationId($key->project_id);

        if ($organizationId !== null) {
            $this->consumeScope(QuotaScope::Organization, $organizationId, $category, $quantity);
        }
    }

    /**
     * Prüfen und buchen für die Fälle, in denen die Menge von vornherein
     * feststeht — eine klassische Meldung, ein Sicherheitsbericht, ein
     * Lebenszeichen.
     */
    public function admit(ProjectKey $key, ?QuotaCategory $category, int $quantity = 1): QuotaVerdict
    {
        $verdict = $this->check($key, $category, $quantity);

        if ($verdict->allowed) {
            $this->consume($key, $category, $quantity);
        }

        return $verdict;
    }

    /**
     * Die Rate des Schlüssels: sie gilt für alles, was über ihn hereinkommt,
     * und kennt keine Datenarten. Ohne Wert am Schlüssel gilt sie nicht.
     */
    private function checkKey(ProjectKey $key, int $quantity): QuotaVerdict
    {
        $limit = $key->rate_limit_per_minute;

        if ($limit === null || $limit < 1) {
            return QuotaVerdict::allow();
        }

        $usage = $this->counter->minuteUsage(QuotaScope::Key, $key->id, null);

        if ($usage + $quantity <= $limit) {
            return QuotaVerdict::allow();
        }

        return QuotaVerdict::rateLimited(QuotaScope::Key, null, $this->counter->secondsUntilNextMinute());
    }

    private function checkScope(QuotaScope $scope, int $scopeId, QuotaCategory $category, int $quantity): QuotaVerdict
    {
        $limit = QuotaLimits::for($scope, $scopeId, $category);

        if ($limit === null) {
            return QuotaVerdict::allow();
        }

        $perMinute = $limit['minute'];

        if ($perMinute !== null && $perMinute >= 1
            && $this->counter->minuteUsage($scope, $scopeId, $category) + $quantity > $perMinute) {
            return QuotaVerdict::rateLimited($scope, $category, $this->counter->secondsUntilNextMinute());
        }

        $perMonth = $limit['month'];

        if ($perMonth !== null && $perMonth >= 1
            && $this->counter->monthUsage($scope, $scopeId, $category) + $quantity > $perMonth) {
            return QuotaVerdict::quotaExceeded($scope, $category, $this->counter->secondsUntilNextMonth());
        }

        return QuotaVerdict::allow();
    }

    private function consumeScope(QuotaScope $scope, int $scopeId, QuotaCategory $category, int $quantity): void
    {
        $this->counter->addMinute($scope, $scopeId, $category, $quantity);

        $usage = $this->counter->addMonth($scope, $scopeId, $category, $quantity);

        $this->warnings->evaluate($scope, $scopeId, $category, $usage);
    }
}
