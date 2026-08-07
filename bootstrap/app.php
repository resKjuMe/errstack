<?php

use App\Http\Middleware\EnsureApiOrganization;
use App\Http\Middleware\EnsureApiScope;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveApiToken;
use App\Http\Middleware\ResolveIngestKey;
use App\Http\Middleware\SetLocale;
use App\Support\Api\ApiErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);

        // Kurznamen für die Routen der öffentlichen Schnittstelle:
        // `api.token` legt Token, Organisation und Konto an der Anfrage ab,
        // `api.organization` hält den Slug in der Adresse an der Organisation des
        // Tokens fest, `scope:…` prüft den nötigen Geltungsbereich.
        //
        // `ingest.key` ist die Anmeldung der Datenaufnahme — dort meldet keine
        // Person mit einem Token, sondern eine Anwendung mit ihrem
        // Client-Schlüssel.
        $middleware->alias([
            'api.token' => ResolveApiToken::class,
            'api.organization' => EnsureApiOrganization::class,
            'scope' => EnsureApiScope::class,
            'ingest.key' => ResolveIngestKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Alle Fehler der Schnittstelle in derselben Form. Für die Weboberfläche
        // liefert ApiErrors null, dort bleibt es bei Weiterleitungen und
        // Fehlerseiten.
        $exceptions->render(
            fn (Throwable $e, Request $request) => ApiErrors::render($e, $request),
        );
    })->create();
