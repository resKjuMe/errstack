<?php

namespace App\Support\Ingest;

use App\Exceptions\IngestRejection;
use Illuminate\Http\Request;

/**
 * Holt die Nutzdaten aus dem Rumpf einer eingehenden Meldung — entpackt, in
 * Klartext, mit harter Obergrenze.
 *
 * Warum das mehr als ein `getContent()` ist: SDKs packen ihre Meldungen, um
 * Übertragungsvolumen zu sparen, und sie tun es nicht einheitlich. Im Umlauf
 * sind gzip und deflate (jeweils mit `Content-Encoding` angekündigt), dasselbe
 * ohne Ankündigung, und bei älteren SDKs Base64 über einem deflate-Strom. Wir
 * nehmen alles an, was wir erkennen — ein SDK, dessen Meldungen niemand liest,
 * weil es die ältere Verpackung benutzt, wäre für die überwachte Anwendung ein
 * stiller Datenverlust.
 *
 * Reihenfolge: erst der angekündigten Kodierung glauben, dann am Inhalt
 * erkennen. Die Umkehrung wäre naheliegend, aber Zwischenstationen (Proxy,
 * Load Balancer) entpacken gelegentlich und lassen die Kopfzeile stehen — dann
 * ist die Ankündigung falsch und der Inhalt hat recht.
 */
final class IngestBody
{
    /**
     * Häppchen, in denen gepackte Daten in den Entpacker gehen. Der Entpacker
     * hält nie mehr als das Ergebnis eines Häppchens auf einmal — bei deflate
     * höchstens das Tausendfache — und kann deshalb nach jedem Schritt gegen
     * die Grenze prüfen, statt erst am Ende.
     */
    private const CHUNK_BYTES = 4096;

    /**
     * Die entpackten Nutzdaten.
     *
     * Die Grenzen lassen sich übergeben, weil sie nicht überall dieselben sind:
     * eine einzelne Fehlermeldung ist auf 1 MiB begrenzt, ein Envelope darf
     * deutlich größer sein — er trägt oft einen Screenshot mit sich. Ohne diese
     * Möglichkeit müsste entweder die Grenze für Einzelmeldungen mit angehoben
     * werden oder jeder Anhang scheitern.
     *
     * @throws IngestRejection wenn die Meldung zu groß oder nicht lesbar ist
     */
    public static function decode(Request $request, ?int $requestLimit = null, ?int $payloadLimit = null): string
    {
        $requestLimit ??= (int) config('ingest.max_request_bytes');
        $payloadLimit ??= (int) config('ingest.max_payload_bytes');

        $raw = $request->getContent();

        // Zuerst das Rohmaß: alles Weitere kostet Rechenzeit, und die soll eine
        // überlange Meldung nicht bekommen.
        if (strlen($raw) > $requestLimit) {
            throw IngestRejection::tooLarge($requestLimit);
        }

        if (trim($raw) === '') {
            throw IngestRejection::unreadable('ingest.no_content');
        }

        $payload = self::unpack($raw, $request->header('Content-Encoding'), $payloadLimit);

        if ($payload === null) {
            throw IngestRejection::unreadable('ingest.not_decodable');
        }

        return $payload;
    }

    /**
     * Entpackt, was sich entpacken lässt, und gibt alles andere unverändert
     * zurück — was davon lesbare Nutzdaten sind, entscheidet der Aufrufer.
     *
     * `null` bleibt dem einen Fall vorbehalten, in dem das hier schon sicher
     * schiefgegangen ist: eine angekündigte Verpackung, die sich nicht öffnen
     * ließ. Dann ist die Meldung unterwegs beschädigt worden, und „das ist kein
     * JSON" wäre die falsche Auskunft.
     */
    private static function unpack(string $body, ?string $declaredEncoding, int $limit): ?string
    {
        if ($declaredEncoding !== null) {
            $announced = self::inflateAs($body, $declaredEncoding, $limit);

            if ($announced !== null) {
                return $announced;
            }
        }

        if (self::looksLikePayload($body)) {
            return $body;
        }

        // Base64 steht vor dem Erkennen am Inhalt, weil der Test dafür eindeutig
        // ist: nur die 64 Zeichen des Alphabets. Gepackte Ströme fallen dabei
        // schon an ihren Kennungsbytes heraus, Klartext-JSON an den
        // Anführungszeichen.
        $binary = self::fromBase64($body);

        if ($binary !== null) {
            $inner = self::looksLikePayload($binary) ? $binary : self::inflateSniffed($binary, $limit);

            if ($inner !== null && self::looksLikePayload($inner)) {
                return $inner;
            }
        }

        $sniffed = self::inflateSniffed($body, $limit);

        if ($sniffed !== null && self::looksLikePayload($sniffed)) {
            return $sniffed;
        }

        // Nichts erkannt. War eine Verpackung angekündigt, ist die Meldung
        // beschädigt; sonst war sie schlicht nicht verpackt und geht so weiter,
        // wie sie kam.
        return self::announcesPacking($declaredEncoding) ? null : $body;
    }

    /**
     * Kündigt die Kopfzeile eine Verpackung an, die wir öffnen können? Ein
     * unbekanntes Verfahren zählt nicht dazu: dann haben wir es nicht versucht
     * und können auch nicht sagen, dass es kaputt ist.
     */
    private static function announcesPacking(?string $encoding): bool
    {
        return $encoding !== null && in_array(
            strtolower(trim($encoding)),
            ['gzip', 'x-gzip', 'deflate'],
            strict: true,
        );
    }

    /**
     * Entpackt nach angekündigter Kodierung. `null`, wenn die Ankündigung nicht
     * zum Inhalt passt oder das Verfahren unbekannt ist.
     */
    private static function inflateAs(string $body, string $encoding, int $limit): ?string
    {
        return match (strtolower(trim($encoding))) {
            'gzip', 'x-gzip' => self::inflate($body, ZLIB_ENCODING_GZIP, $limit),
            // `deflate` meint in der Praxis beides: den Strom mit zlib-Kopf (so
            // schreibt es der Standard) und den nackten ohne (so schicken ihn
            // einige SDKs).
            'deflate' => self::inflate($body, ZLIB_ENCODING_DEFLATE, $limit)
                ?? self::inflate($body, ZLIB_ENCODING_RAW, $limit),
            default => null,
        };
    }

    /**
     * Entpackt anhand der Kennung im Inhalt. Rohes deflate hat keine, deshalb
     * bleibt es der letzte Versuch — er scheitert bei allem anderen von selbst.
     */
    private static function inflateSniffed(string $body, int $limit): ?string
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            return self::inflate($body, ZLIB_ENCODING_GZIP, $limit);
        }

        if (self::looksLikeZlib($body)) {
            return self::inflate($body, ZLIB_ENCODING_DEFLATE, $limit);
        }

        return self::inflate($body, ZLIB_ENCODING_RAW, $limit);
    }

    /**
     * Entpackt einen Strom häppchenweise und bricht ab, sobald das Ergebnis die
     * Grenze überschreitet — daran scheitert eine „Zip-Bombe": wenige Kilobyte,
     * die zu Gigabyte werden. Ein Entpacker, der erst fertig entpackt und dann
     * die Größe prüft, hätte den Speicher zu diesem Zeitpunkt längst gefüllt.
     *
     * `null` heißt „so war es nicht verpackt" und ist kein Fehler, sondern die
     * Antwort auf einen Versuch beim Erkennen. `@` unterdrückt die Warnung, die
     * zlib dabei abgibt; ohne sie würde der erste Fehlversuch die ganze Anfrage
     * abbrechen.
     *
     * @throws IngestRejection wenn die entpackten Daten zu groß sind
     */
    private static function inflate(string $body, int $encoding, int $limit): ?string
    {
        $context = @inflate_init($encoding);

        if ($context === false) {
            return null;
        }

        $payload = '';
        $length = strlen($body);

        for ($offset = 0; $offset < $length; $offset += self::CHUNK_BYTES) {
            $isLast = $offset + self::CHUNK_BYTES >= $length;

            $piece = @inflate_add(
                $context,
                substr($body, $offset, self::CHUNK_BYTES),
                $isLast ? ZLIB_FINISH : ZLIB_NO_FLUSH,
            );

            if ($piece === false) {
                return null;
            }

            $payload .= $piece;

            if (strlen($payload) > $limit) {
                throw IngestRejection::tooLarge($limit);
            }

            // Steht das Ende des Stroms, ist alles Weitere Anhang und geht den
            // Entpacker nichts mehr an — ein weiterer Aufruf wäre ein Fehler.
            if (@inflate_get_status($context) === ZLIB_STREAM_END) {
                break;
            }
        }

        // Ein abgebrochener Strom liefert Daten, die nur zufällig lesbar sind.
        // Solche Meldungen gelten als unlesbar, nicht als halb angenommen.
        return @inflate_get_status($context) === ZLIB_STREAM_END ? $payload : null;
    }

    /**
     * Kopf eines zlib-Stroms: unteres Halbbyte 8 (Verfahren „deflate") und die
     * ersten zwei Byte als Zahl durch 31 teilbar — die Prüfregel des Formats.
     */
    private static function looksLikeZlib(string $body): bool
    {
        if (strlen($body) < 2) {
            return false;
        }

        $first = ord($body[0]);
        $second = ord($body[1]);

        return ($first & 0x0F) === 8 && ((($first << 8) + $second) % 31) === 0;
    }

    /**
     * Sieht das nach Nutzdaten aus? Sowohl eine Fehlermeldung als auch die
     * Kopfzeile eines Envelope beginnt mit einem JSON-Objekt — daran lässt sich
     * ein geglücktes Entpacken erkennen, ohne den Inhalt schon auszuwerten.
     */
    private static function looksLikePayload(string $body): bool
    {
        return str_starts_with(ltrim($body), '{');
    }

    private static function fromBase64(string $body): ?string
    {
        // Zeilenumbrüche kommen vor: Base64 wird gern umgebrochen.
        $compact = preg_replace('/\s+/', '', $body) ?? '';

        if ($compact === '' || strlen($compact) % 4 !== 0) {
            return null;
        }

        if (preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $compact) !== 1) {
            return null;
        }

        $decoded = base64_decode($compact, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }
}
