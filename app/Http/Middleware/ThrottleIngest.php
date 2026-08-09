<?php

namespace App\Http\Middleware;

use App\Exceptions\IngestRejection;
use App\Support\Ingest\IngestAuth;
use App\Support\Ingest\Quotas\QuotaCounter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die grobe Bremse **vor** der Anmeldung: wie viele Anfragen eine Herkunft je
 * Minute an die Datenaufnahme richten darf.
 *
 * Sie steht bewusst vor {@see ResolveIngestKey} und nicht dahinter. Die feinen
 * Kontingente (O1) hängen an einem gültigen Schlüssel — wer keinen hat, käme
 * an ihnen vorbei und dürfte unbegrenzt Schlüssel durchprobieren. Genau dagegen
 * ist diese Stufe da, und deshalb zählt sie an der Anfrage und nicht am
 * gefundenen Datensatz: eine Abfrage je Versuch wäre schon die halbe Last, die
 * ein solcher Versuch anrichten will.
 *
 * Gezählt wird je Absender-Adresse **und** mitgeschicktem Schlüssel. Nur je
 * Adresse wäre zu streng — hinter einer Adresse steht ein ganzes Rechenzentrum,
 * und dessen Anwendungen melden alle von dort. Nur je Schlüssel wäre beim
 * Durchprobieren wirkungslos, denn dabei ist jeder Versuch ein anderer.
 *
 * Der Wert ist absichtlich hoch: hier soll niemand gedrosselt werden, der
 * berechtigt meldet. Was ein einzelnes Projekt tatsächlich verbrauchen darf,
 * steht in seinen Kontingenten und wird eine Stufe später geprüft.
 */
class ThrottleIngest
{
    private const TTL_SECONDS = 120;

    public function __construct(private readonly QuotaCounter $counter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('ingest.quotas.requests_per_minute');

        // Null schaltet die Stufe ab — für Installationen hinter einem
        // vorgelagerten Wächter, der dasselbe besser kann.
        if ($limit < 1) {
            return $next($request);
        }

        $cacheKey = $this->cacheKey($request);

        $used = Cache::get($cacheKey);
        $used = is_numeric($used) ? (int) $used : 0;

        if ($used >= $limit) {
            throw IngestRejection::rateLimited(
                'ingest.rate_limited',
                $this->counter->secondsUntilNextMinute(),
            );
        }

        Cache::add($cacheKey, 0, self::TTL_SECONDS);
        Cache::increment($cacheKey);

        return $next($request);
    }

    /**
     * Der Zählschlüssel: Absender, mitgeschickter Schlüssel und laufende Minute.
     *
     * Der Schlüssel geht als Streuwert ein — er stammt aus der Anfrage, ist
     * also beliebig lang und beliebig geformt, und er landete sonst im Klartext
     * im Zwischenspeicher.
     */
    private function cacheKey(Request $request): string
    {
        $publicKey = IngestAuth::publicKey($request);

        return 'ingest:requests:'
            .$request->ip().':'
            .($publicKey === null ? 'anonym' : md5($publicKey)).':'
            .now()->format('YmdHi');
    }
}
