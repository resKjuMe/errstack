<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setzt die Sprache der laufenden Anfrage. Hängt in der Gruppe „web" hinter
 * StartSession, damit sowohl das angemeldete Konto als auch die Wahl eines
 * Gasts aus der Sitzung gelesen werden können.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(Locales::resolve($request));

        return $next($request);
    }
}
