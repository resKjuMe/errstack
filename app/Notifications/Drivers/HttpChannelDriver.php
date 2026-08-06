<?php

namespace App\Notifications\Drivers;

use App\Notifications\Contracts\ChannelDriver;
use App\Notifications\DeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Gemeinsamer Unterbau der Kanäle, die eine JSON-Nutzlast an eine URL
 * schicken (Slack, Discord, Teams, allgemeiner Webhook). Er kümmert sich um
 * Zeitgrenze, Antwortcode und darum, dass ein nicht erreichbares Ziel als
 * Fehlschlag im Protokoll landet statt als Ausnahme aus dem Nichts.
 */
abstract class HttpChannelDriver implements ChannelDriver
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function post(string $url, array $payload, array $headers = []): DeliveryResult
    {
        return $this->postBody($url, $this->encode($payload), $headers);
    }

    /**
     * Nutzlast als fertige Zeichenkette senden. Der signierte Webhook braucht
     * genau das: unterschrieben wird der Rumpf, der auch übertragen wird —
     * würde die Nutzlast danach noch einmal umkodiert, ginge die Unterschrift
     * nicht mehr auf.
     *
     * @param  array<string, string>  $headers
     */
    protected function postBody(string $url, string $body, array $headers = []): DeliveryResult
    {
        try {
            $response = Http::withHeaders($headers)
                ->withBody($body, 'application/json')
                ->timeout((int) config('notifications.timeout', 10))
                ->post($url);
        } catch (ConnectionException $exception) {
            // Zeitüberschreitung oder kein Netz: ein Fehlschlag wie jeder
            // andere — der Job versucht es später erneut.
            return DeliveryResult::failure($exception->getMessage());
        }

        if ($response->successful()) {
            return DeliveryResult::success($response->status());
        }

        return DeliveryResult::failure(
            $this->errorFrom($response->status(), $response->body()),
            $response->status(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Fehlertext fürs Protokoll: Antwortcode plus die Antwort des Ziels, denn
     * dort steht der eigentliche Grund („invalid_token", „channel_not_found").
     */
    protected function errorFrom(int $status, string $body): string
    {
        $body = trim(Str::limit(strip_tags($body), 300));

        return $body === ''
            ? "Das Ziel hat mit HTTP {$status} geantwortet."
            : "HTTP {$status}: {$body}";
    }
}
