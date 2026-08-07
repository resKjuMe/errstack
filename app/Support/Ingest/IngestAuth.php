<?php

namespace App\Support\Ingest;

use Illuminate\Http\Request;

/**
 * Liest die Zugangsdaten einer eingehenden Meldung aus der Anfrage.
 *
 * Die DSN eines Projekts sieht so aus:
 *
 *     https://<public_key>@errstack.example/<project_id>
 *
 * Der öffentliche Schlüssel steht darin im Klartext — er ist kein Geheimnis,
 * sondern die Adresse. Wie ein SDK ihn überträgt, hat sich über die Jahre
 * dreimal geändert, und alle drei Wege sind noch im Umlauf:
 *
 *   1. Kopfzeile `X-Sentry-Auth: Sentry sentry_version=7, sentry_key=…`
 *      — der Regelfall bei Servern.
 *   2. Abfrageteil `?sentry_key=…` — der Weg des JavaScript-SDK: eine
 *      eigene Kopfzeile würde dort eine Vorab-Anfrage (OPTIONS) erzwingen.
 *   3. Kopfzeile `Authorization` — mit demselben `Sentry …`-Inhalt wie (1)
 *      oder als schlichtes `Bearer <public_key>`.
 *
 * Wir nehmen alle drei an, in dieser Reihenfolge. Ein SDK auszuschließen, weil
 * es den älteren Weg nimmt, wäre genau das, was diese Nachbildung vermeiden
 * soll.
 */
final class IngestAuth
{
    /**
     * Der öffentliche Schlüssel der Anfrage, oder `null`, wenn keiner mitkam.
     */
    public static function publicKey(Request $request): ?string
    {
        $fromHeader = self::fromSentryAuth($request->header('X-Sentry-Auth'));

        if ($fromHeader !== null) {
            return $fromHeader;
        }

        $fromQuery = $request->query('sentry_key');

        if (is_string($fromQuery) && $fromQuery !== '') {
            return $fromQuery;
        }

        return self::fromAuthorization($request->header('Authorization'));
    }

    /**
     * Name und Fassung des meldenden SDK (`sentry_client`), sofern angegeben.
     * Steht nur in der `Sentry …`-Form, nicht bei `Bearer`.
     */
    public static function client(Request $request): ?string
    {
        $pairs = self::pairs($request->header('X-Sentry-Auth'))
            + self::pairs($request->header('Authorization'));

        $client = $pairs['sentry_client'] ?? null;

        // Länger als die Spalte darf es nicht werden; die Angabe kommt vom
        // Client und ist damit nichts, worauf man sich verlassen darf.
        return $client === null ? null : mb_substr($client, 0, 255);
    }

    private static function fromSentryAuth(?string $header): ?string
    {
        return self::pairs($header)['sentry_key'] ?? null;
    }

    /**
     * `Authorization` trägt entweder denselben `Sentry …`-Inhalt wie
     * `X-Sentry-Auth` oder ein schlichtes `Bearer <public_key>`.
     */
    private static function fromAuthorization(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        $fromPairs = self::fromSentryAuth($header);

        if ($fromPairs !== null) {
            return $fromPairs;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Zerlegt `Sentry sentry_version=7, sentry_key=abc, sentry_client=x/1.0` in
     * seine Paare. Ohne das führende `Sentry` gilt die Kopfzeile nicht als
     * Sentry-Zugangsdaten — sonst würde ein fremdes Anmeldeverfahren, das
     * zufällig `=` enthält, hier mitgelesen.
     *
     * @return array<string, string>
     */
    private static function pairs(?string $header): array
    {
        if ($header === null || preg_match('/^Sentry\s+(.*)$/is', trim($header), $matches) !== 1) {
            return [];
        }

        $pairs = [];

        foreach (preg_split('/\s*,\s*/', trim($matches[1])) ?: [] as $part) {
            $halves = explode('=', $part, 2);

            if (count($halves) === 2) {
                $value = trim($halves[1], " \t\"'");

                if ($value !== '') {
                    $pairs[strtolower(trim($halves[0]))] = $value;
                }
            }
        }

        return $pairs;
    }
}
