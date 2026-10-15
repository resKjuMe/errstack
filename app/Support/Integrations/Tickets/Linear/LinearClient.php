<?php

namespace App\Support\Integrations\Tickets\Linear;

use App\Models\Integration;
use App\Support\Integrations\Tickets\TicketException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Die eine Stelle, an der mit Linear gesprochen wird (X4).
 *
 * Linear hat **eine** Adresse und **eine** Anfrageart: alles läuft als POST
 * gegen `/graphql`, und was man will, steht in der Abfrage. Das dreht die
 * gewohnte Aufteilung um — es gibt hier keine Pfade, sondern Abfragetexte, und
 * die stehen in {@see LinearTicketProvider}, wo die Fachfrage steht, zu der sie
 * gehören.
 *
 * **Zwei Fallen, die diese Klasse abfängt:**
 *
 * 1. **Ein Fehler kommt mit Status 200.** GraphQL antwortet auf eine
 *    fehlgeschlagene Abfrage mit `200 OK` und einer Liste `errors` im Rumpf.
 *    Wer nur `$response->failed()` prüft, hält eine leere Antwort für ein
 *    erfolgreiches Ergebnis und schreibt eine Verknüpfung ohne Ticket.
 * 2. **Der abgelehnte Zugang steckt ebenfalls darin.** Ein ungültiger
 *    API-Schlüssel ist bei Linear ein `errors`-Eintrag mit der Erweiterung
 *    `AUTHENTICATION_ERROR` — nicht durchweg ein `401`. Er muss dort erkannt
 *    werden, sonst bleibt die Anbindung in der Oberfläche für immer „verbunden".
 *
 * Angemeldet wird mit dem API-Schlüssel **ohne** `Bearer`. Das ist keine
 * Nachlässigkeit, sondern Linears Vorgabe für persönliche Schlüssel; ein
 * `Bearer` gilt dort nur für OAuth-Zugänge.
 */
final readonly class LinearClient
{
    public function __construct(private Integration $integration) {}

    /**
     * Eine Abfrage oder Änderung ausführen.
     *
     * @param  array<string, mixed>  $variables
     * @return array<mixed> der Inhalt von `data`
     *
     * @throws TicketException
     */
    public function query(string $query, array $variables = []): array
    {
        $token = $this->integration->token();

        if ($token === null) {
            throw TicketException::accessRejected(__('integrations.errors.no_token'));
        }

        try {
            $response = Http::withHeaders(['Authorization' => $token])
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.linear.timeout', 10))
                ->post($this->url(), ['query' => $query, 'variables' => $variables]);
        } catch (ConnectionException $e) {
            throw TicketException::failed($e->getMessage());
        }

        return $this->result($response);
    }

    /**
     * @return array<mixed>
     *
     * @throws TicketException
     */
    private function result(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            $this->reject(self::httpReason($response));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw TicketException::failed(__('integrations.errors.unexpected_response', [
                'provider' => __('enums.integration_provider.linear'),
            ]));
        }

        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            $reason = self::graphReason($errors);

            if (self::isAuthenticationError($errors)) {
                $this->reject($reason);
            }

            throw TicketException::failed($reason);
        }

        if ($response->failed()) {
            throw TicketException::failed(self::httpReason($response));
        }

        $data = $body['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * Den Zugang als abgelehnt festhalten und die Ausnahme werfen.
     *
     * Festgehalten wird hier und nicht in einem Prüflauf: der nächste Aufruf
     * käme sonst genauso weit, und in der Oberfläche stünde weiterhin
     * „verbunden".
     *
     * @throws TicketException
     */
    private function reject(string $reason): never
    {
        $this->integration->markDisconnected($reason);

        throw TicketException::accessRejected($reason);
    }

    /**
     * Ob unter den Fehlern einer steckt, der den Zugang betrifft.
     *
     * Über die Erweiterung und nicht über den Meldungstext: der ist
     * übersetzbar und ändert sich, der Code nicht.
     *
     * @param  array<mixed>  $errors
     */
    private static function isAuthenticationError(array $errors): bool
    {
        foreach ($errors as $error) {
            $code = data_get($error, 'extensions.code');
            $type = data_get($error, 'extensions.type');

            if (in_array($code, ['AUTHENTICATION_ERROR', 'FORBIDDEN', 'UNAUTHENTICATED'], true)) {
                return true;
            }

            if ($type === 'authentication error') {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Meldungen, die Linear in `errors` schickt — zusammengezogen.
     *
     * @param  array<mixed>  $errors
     */
    private static function graphReason(array $errors): string
    {
        $messages = [];

        foreach ($errors as $error) {
            $message = data_get($error, 'message');

            if (is_string($message) && $message !== '') {
                $messages[] = $message;
            }
        }

        return $messages === []
            ? __('integrations.errors.unexpected_response', [
                'provider' => __('enums.integration_provider.linear'),
            ])
            : Str::limit(implode(' ', $messages), 200, '');
    }

    private static function httpReason(Response $response): string
    {
        $message = $response->json('message') ?? $response->json('error');

        return is_string($message) && $message !== ''
            ? Str::limit($message, 200, '')
            : __('integrations.errors.http_status', [
                'provider' => __('enums.integration_provider.linear'),
                'status' => $response->status(),
            ]);
    }

    private function url(): string
    {
        return (string) config('services.linear.api_url', 'https://api.linear.app/graphql');
    }
}
