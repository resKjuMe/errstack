<?php

namespace App\Support\Ingest\Quotas;

use App\Enums\DiscardReason;
use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;

/**
 * Das Ergebnis einer Kontingent-Prüfung: durchlassen oder nicht — und wenn
 * nicht, warum und wie lange.
 *
 * Die Wartezeit gehört mit ins Ergebnis und wird nicht an der Aufrufstelle
 * gerechnet: nur hier ist bekannt, **welche** Grenze gegriffen hat, und die
 * beiden Antworten sind grundverschieden. „Versuch es in 12 Sekunden wieder"
 * ist bei einer gerissenen Rate richtig und bei einem aufgebrauchten
 * Monatskontingent eine Einladung, es 200.000 Mal zu tun.
 */
final readonly class QuotaVerdict
{
    private function __construct(
        public bool $allowed,
        public ?DiscardReason $reason = null,
        public ?QuotaScope $scope = null,
        public ?QuotaCategory $category = null,
        public int $retryAfter = 0,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function rateLimited(QuotaScope $scope, ?QuotaCategory $category, int $retryAfter): self
    {
        return new self(false, DiscardReason::RateLimited, $scope, $category, $retryAfter);
    }

    public static function quotaExceeded(QuotaScope $scope, QuotaCategory $category, int $retryAfter): self
    {
        return new self(false, DiscardReason::QuotaExceeded, $scope, $category, $retryAfter);
    }

    public function denied(): bool
    {
        return ! $this->allowed;
    }

    /**
     * Die Kategorie, unter der die Verwerfung gezählt wird.
     *
     * Die Datenart, sofern eine im Spiel war — bei der Grenze des Schlüssels
     * gibt es keine, weil sie für alles gilt, was über ihn hereinkommt.
     */
    public function discardCategory(): ?string
    {
        return $this->category?->value;
    }
}
