<?php

namespace App\Http\Middleware;

use App\Exceptions\IngestRejection;
use App\Models\ProjectKey;
use App\Support\Ingest\IngestAuth;
use App\Support\Ingest\IngestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüft die Zugangsdaten einer eingehenden Meldung und legt den erkannten
 * Client-Schlüssel an der Anfrage ab ({@see IngestContext}).
 *
 * Die Aufnahme hat ihre eigene Anmeldung, getrennt von `auth:sanctum` der
 * öffentlichen Schnittstelle: hier meldet keine Person mit einem Token, sondern
 * eine Anwendung mit ihrer DSN. Der Schlüssel darin ist öffentlich — er steht in
 * jedem JavaScript-Bundle — und berechtigt ausschließlich zum Einstellen von
 * Meldungen für genau ein Projekt, zu keinem Lesezugriff.
 *
 * Deshalb wird die Projektnummer aus der Adresse gegen die des Schlüssels
 * gehalten. Ohne diese Prüfung könnte jeder gültige Schlüssel Meldungen in
 * fremde Projekte einstellen, indem er einfach eine andere Nummer in die Adresse
 * schreibt.
 */
class ResolveIngestKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $publicKey = IngestAuth::publicKey($request);

        if ($publicKey === null) {
            throw IngestRejection::unauthorized();
        }

        $key = ProjectKey::findActive($publicKey);
        $projectId = $request->route('project');

        if ($key === null || ! is_numeric($projectId) || (int) $projectId !== $key->project_id) {
            throw IngestRejection::unauthorized();
        }

        $request->attributes->set(IngestContext::KEY, $key);
        $request->attributes->set(IngestContext::CLIENT, IngestAuth::client($request));

        return $next($request);
    }
}
