<?php

namespace App\Http\Middleware;

use App\Enums\DiscardReason;
use App\Enums\QuotaCategory;
use App\Exceptions\IngestRejection;
use App\Models\IngestDiscard;
use App\Support\Ingest\IngestContext;
use App\Support\Ingest\Quotas\QuotaGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüft die Kontingente einer Aufnahme-Anfrage, bevor irgendetwas ausgewertet
 * oder abgelegt wird.
 *
 * Steht hinter {@see ResolveIngestKey}, weil erst dort feststeht, wessen
 * Kontingent gilt — die grobe Bremse gegen das Durchprobieren von Schlüsseln
 * ist eine Stufe davor ({@see ThrottleIngest}).
 *
 * Die Datenart kommt als Parameter aus der Route (`ingest.quota:errors`) und
 * nicht aus dem Rumpf: an allen Adressen bis auf eine steht sie fest, und sie
 * dort abzulesen hieße, die Meldung zu zerlegen, um zu entscheiden, ob man sie
 * überhaupt zerlegen darf. Die eine Ausnahme ist der Envelope — er trägt
 * Elemente verschiedener Art, und für ihn läuft diese Stufe **ohne** Datenart:
 * geprüft wird nur die Rate des Schlüssels, die Datenarten prüft die Aufnahme
 * je Element ({@see App\Support\Ingest\EnvelopeIntake}). Das ist auch der
 * Grund, warum ein aufgebrauchtes Transaktions-Kontingent die Fehlermeldungen
 * daneben nicht mitnimmt.
 */
class EnforceIngestQuota
{
    public function __construct(private readonly QuotaGuard $guard) {}

    public function handle(Request $request, Closure $next, ?string $category = null): Response
    {
        $key = IngestContext::key($request);
        $quotaCategory = $category === null ? null : QuotaCategory::from($category);

        // Ohne Datenart wird nur geprüft und nichts gebucht: gebucht wird beim
        // Envelope Element für Element, und was hier zusätzlich anfiele, wäre
        // dieselbe Anfrage ein zweites Mal.
        $verdict = $quotaCategory === null
            ? $this->guard->check($key, null)
            : $this->guard->admit($key, $quotaCategory);

        if ($verdict->allowed) {
            return $next($request);
        }

        // Gezählt wird auch das Abgewiesene — sonst ist die Antwort auf „warum
        // fehlen Meldungen?" eine 429 in einem Protokoll, das niemand liest.
        $reason = $verdict->reason ?? DiscardReason::RateLimited;

        IngestDiscard::server(
            key: $key,
            reason: $reason,
            category: $verdict->discardCategory(),
        );

        throw IngestRejection::rateLimited($this->reasonKey($reason), $verdict->retryAfter);
    }

    /**
     * Der Sprachschlüssel des Grundes — die Auskunft an das SDK.
     *
     * Zwei Sätze und nicht einer: „zu schnell" und „aufgebraucht" verlangen
     * verschiedene Reaktionen, und die entwickelnde Person liest genau diese
     * Zeile im Protokoll ihres SDK.
     */
    private function reasonKey(DiscardReason $reason): string
    {
        return $reason === DiscardReason::QuotaExceeded
            ? 'ingest.quota_exceeded'
            : 'ingest.rate_limited';
    }
}
