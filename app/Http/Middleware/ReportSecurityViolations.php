<?php

namespace App\Http\Middleware;

use App\Support\SelfMonitoring\Dsn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Kopfzeile, mit der der Browser Sicherheitsverstöße an die eigene
 * Aufnahme meldet (M7).
 *
 * Es ist der einzige Meldeweg ohne SDK: die Anwendung sagt dem Browser, wohin
 * er berichten soll, und der tut es von sich aus — auch dann, wenn von der
 * Seite sonst nichts mehr läuft. Genau deshalb gehört er zur
 * Selbstüberwachung: was ein eingeschleustes Skript tut, sieht kein
 * serverseitiger Fehler-Handler.
 *
 * **`Report-Only` und nicht die scharfe Regel.** Eine Richtlinie, die
 * blockiert, ist eine Aussage über die Anwendung und veraltet mit ihr; hier
 * geht es darum, dass die Berichte ankommen. Der Unterschied ist im Ernstfall
 * der zwischen einer gemeldeten und einer weißen Seite.
 *
 * Ohne DSN ({@see config/selfmonitoring.php}) bleibt die Kopfzeile weg: eine
 * Richtlinie ohne Empfänger sagt dem Browser, er solle ins Leere berichten.
 *
 * `report-uri` ist formal abgelöst und steht trotzdem allein hier: der
 * Nachfolger `report-to` verlangt eine zweite Kopfzeile mit einer
 * Empfängergruppe, und die Aufnahme (`/api/{projekt}/security`) nimmt das
 * Format, das die Browser über `report-uri` schicken. Zwei Wege gleichzeitig
 * hieße, denselben Verstoß doppelt zu zählen.
 */
class ReportSecurityViolations
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('selfmonitoring.csp.enabled')) {
            return $response;
        }

        $dsn = Dsn::parse(config('sentry.dsn'));

        if ($dsn === null) {
            return $response;
        }

        // Nur an Seiten, die jemand im Browser öffnet. Eine Richtlinie an einer
        // JSON-Antwort wertet kein Browser aus, und an einem Download stünde
        // sie in einer Datei, die niemand ansieht.
        if (! $this->isDocument($response)) {
            return $response;
        }

        /** @var list<string> $directives */
        $directives = config('selfmonitoring.csp.directives', []);

        $response->headers->set(
            'Content-Security-Policy-Report-Only',
            implode('; ', [...$directives, 'report-uri '.$dsn->securityReportUrl()]),
        );

        return $response;
    }

    private function isDocument(Response $response): bool
    {
        $type = $response->headers->get('Content-Type');

        return $type !== null && str_contains($type, 'text/html');
    }
}
