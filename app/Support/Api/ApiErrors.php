<?php

namespace App\Support\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Einheitliches Fehlerformat der öffentlichen Schnittstelle.
 *
 * Jeder Fehler — egal ob Anmeldung, Recht, Validierung, Ratenbegrenzung oder
 * Panne — kommt in derselben Form zurück:
 *
 *     {"message": "…", "errors": {"feld": ["…"]}}
 *
 * `errors` ist immer vorhanden, auch leer. Sonst müsste jeder Client zwei Fälle
 * unterscheiden, nur weil ausgerechnet die Validierung ein Feld mehr liefert.
 *
 * Angebunden in bootstrap/app.php. Für Anfragen außerhalb der Schnittstelle
 * liefert render() null — dann bleibt es bei der normalen Behandlung (Weboberf-
 * läche mit Weiterleitungen und Fehlerseiten).
 */
final class ApiErrors
{
    public static function handles(Request $request): bool
    {
        return $request->is('api/*');
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::handles($request)) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return self::response($e->getMessage(), $e->status, $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return self::response('Nicht angemeldet: es fehlt ein gültiges API-Token.', 401);
        }

        if ($e instanceof AuthorizationException) {
            return self::response($e->getMessage() ?: 'Für diese Anfrage fehlt die Berechtigung.', 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return self::response('Nicht gefunden.', 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            // Deckt 404, 405, 429 und alles andere ab, was als HTTP-Fehler
            // geworfen wird. Die Kopfzeilen kommen mit — bei 429 steckt darin
            // `Retry-After`, ohne das ein Client nur raten kann, wann er es
            // wieder versuchen darf.
            return self::response(
                $e->getMessage() ?: self::defaultMessage($e->getStatusCode()),
                $e->getStatusCode(),
                [],
                self::stringHeaders($e->getHeaders()),
            );
        }

        // Unerwartetes: nach außen nur die nackte Auskunft. Was wirklich passiert
        // ist, steht im Log — und in der Entwicklung zusätzlich hier.
        return self::response(
            config('app.debug') ? $e->getMessage() : 'Unerwarteter Fehler.',
            500,
        );
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, string>  $headers
     */
    private static function response(string $message, int $status, array $errors = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'errors' => (object) $errors,
        ], $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            if (is_scalar($value)) {
                $result[$name] = (string) $value;
            }
        }

        return $result;
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            401 => 'Nicht angemeldet: es fehlt ein gültiges API-Token.',
            403 => 'Für diese Anfrage fehlt die Berechtigung.',
            404 => 'Nicht gefunden.',
            405 => 'Diese Methode ist für die Adresse nicht vorgesehen.',
            429 => 'Zu viele Anfragen.',
            default => 'Die Anfrage konnte nicht bearbeitet werden.',
        };
    }
}
