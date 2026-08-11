<?php

namespace App\Support\Integrations\GitHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Der Weg herein: Anmeldung bei GitHub und Eintausch des Codes gegen ein Token.
 *
 * Kein Teil von {@see GitHubClient} — dort steht, was man **mit** einem Token
 * tut, hier, wie man an eines kommt. Der Unterschied ist nicht bloß Ordnung:
 * die Aufrufe hier laufen ohne Token und gegen einen anderen Host
 * (`github.com`, nicht `api.github.com`), und sie dürfen keine Anbindung als
 * „verloren" kennzeichnen — es gibt noch keine.
 *
 * **Der `state`-Wert ist keine Formalie.** Ohne ihn nimmt die Rückkehr-Adresse
 * jeden Code entgegen, den ihr jemand unterschiebt — und verbindet die
 * Organisation des Angemeldeten mit dem GitHub-Konto des Angreifers. Er wird
 * deshalb in der Sitzung hinterlegt und beim Rückweg verglichen; welche
 * Organisation gemeint war, steckt gleich mit darin, damit die Rückkehr ohne
 * einen zweiten Sitzungswert auskommt.
 */
final class GitHubOAuth
{
    /**
     * Die Berechtigungen, die diese Anbindung braucht.
     *
     * `repo` ist grob, und das ist GitHubs Zuschnitt, nicht unserer: Commits
     * privater Repositories lesen und Tickets anlegen gibt es dort nicht
     * kleiner. `read:org` kommt dazu, damit in der Auswahlliste auch die
     * Repositories einer Organisation stehen und nicht nur die persönlichen.
     */
    public const SCOPES = 'repo read:org';

    /**
     * Ob diese Installation überhaupt eingerichtet ist.
     *
     * Die Antwort entscheidet, ob die Oberfläche das Verbinden anbietet: ein
     * Knopf, der bei GitHub in einer Fehlerseite endet, ist schlechter als der
     * Hinweis, dass hier nichts eingerichtet ist.
     */
    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /**
     * Der Wert, der die Rückkehr an diese Anmeldung bindet.
     *
     * Zufall und Organisation in einem, getrennt durch einen Doppelpunkt: der
     * Zufall macht ihn unvorhersagbar, der zweite Teil sagt beim Rückweg, wofür
     * er galt.
     */
    public static function state(string $organizationSlug): string
    {
        return Str::random(40).':'.$organizationSlug;
    }

    /**
     * Die Organisation aus einem `state` — oder `null`, wenn er nicht zu dem
     * passt, der hinterlegt wurde.
     *
     * Der Vergleich läuft über {@see hash_equals()}: ein Vergleich, dessen
     * Laufzeit von der Anzahl übereinstimmender Zeichen abhängt, verrät den
     * erwarteten Wert Zeichen für Zeichen.
     */
    public static function organizationFrom(?string $expected, ?string $received): ?string
    {
        if (! is_string($expected) || ! is_string($received) || $expected === '') {
            return null;
        }

        if (! hash_equals($expected, $received)) {
            return null;
        }

        $slug = Str::after($expected, ':');

        return $slug === '' ? null : $slug;
    }

    /**
     * Wohin der Browser geschickt wird.
     */
    public static function authorizeUrl(string $state, string $callback): string
    {
        return Str::finish((string) config('services.github.url', 'https://github.com'), '/')
            .'login/oauth/authorize?'.http_build_query([
                'client_id' => self::clientId(),
                'redirect_uri' => $callback,
                'scope' => self::SCOPES,
                'state' => $state,
                // Wer bei GitHub noch kein Konto hat, soll sich hier keines
                // anlegen: der Weg führt aus einer Anwendung heraus, in der
                // jemand bereits angemeldet ist und ein bestehendes Repository
                // verbinden will — die Registrierung mittendrin ist eine
                // Abzweigung, die nie ans Ziel führt.
                'allow_signup' => 'false',
            ]);
    }

    /**
     * Den Code gegen ein Token tauschen.
     *
     * @throws GitHubException wenn GitHub den Tausch ablehnt
     */
    public static function exchange(string $code, string $callback): string
    {
        try {
            $response = Http::asForm()
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout((int) config('services.github.timeout', 10))
                ->post(
                    Str::finish((string) config('services.github.url', 'https://github.com'), '/').'login/oauth/access_token',
                    [
                        'client_id' => self::clientId(),
                        'client_secret' => self::clientSecret(),
                        'code' => $code,
                        'redirect_uri' => $callback,
                    ],
                );
        } catch (ConnectionException $e) {
            throw GitHubException::failed($e->getMessage());
        }

        // GitHub antwortet auf einen abgelehnten Tausch mit `200` und einem
        // `error`-Feld — nicht mit einem Fehlerstatus. Wer nur auf `failed()`
        // prüft, hält einen abgelaufenen Code für einen gelungenen Tausch und
        // legt eine Anbindung mit leerem Token an.
        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            $error = $response->json('error_description') ?? $response->json('error');

            throw GitHubException::failed(
                is_string($error) && $error !== ''
                    ? Str::limit($error, 200, '')
                    : __('integrations.errors.token_exchange'),
            );
        }

        return $token;
    }

    private static function clientId(): string
    {
        return trim((string) config('services.github.client_id'));
    }

    private static function clientSecret(): string
    {
        return trim((string) config('services.github.client_secret'));
    }
}
