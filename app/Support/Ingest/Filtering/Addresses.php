<?php

namespace App\Support\Ingest\Filtering;

/**
 * Der Vergleich einer Absender-Adresse mit einem Eintrag der Sperrliste.
 *
 * Eine einzelne Adresse oder ein ganzes Netz in CIDR-Schreibweise
 * (`203.0.113.0/24`, `2001:db8::/32`). Das Netz ist nicht die Zugabe, sondern
 * der eigentliche Fall: wer aus einem Rechenzentrum zugemüllt wird, sperrt
 * dessen Bereich und nicht die dreihundert Adressen darin einzeln.
 *
 * Kein Platzhalter-Muster wie bei den übrigen Listen. `203.0.113.*` sieht
 * richtig aus und ist es nicht: es trifft `203.0.113.5`, aber auch
 * `203.0.113.50` — und bei IPv6, wo dieselbe Adresse mehrere Schreibweisen
 * hat, ginge ein Textvergleich reihenweise daneben.
 */
final class Addresses
{
    /**
     * Trifft der Eintrag auf eine der Adressen zu?
     *
     * @param  list<string>  $addresses
     */
    public static function matchesAny(string $expression, array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (self::matches($expression, $address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ist der Eintrag eine brauchbare Adresse oder ein brauchbares Netz?
     *
     * Dieselbe Zerlegung wie beim Vergleich, damit die Prüfung des Formulars
     * und die Auswertung nicht auseinanderlaufen können: was hier durchgeht,
     * greift später auch, und was hier abgelehnt wird, hätte nie gegriffen.
     */
    public static function isValid(string $expression): bool
    {
        $normalized = self::parse(trim($expression));

        if ($normalized === null) {
            return false;
        }

        [$packed, $prefix] = $normalized;

        // Ab 1: `0.0.0.0/0` und `::/0` treffen jede Adresse und wären der eine
        // Eintrag, mit dem sich ein Projekt in einem Zug still stellen lässt —
        // dieselbe Falle, die bei den Mustern schon abgefangen wird.
        return $prefix === null || ($prefix >= 1 && $prefix <= strlen($packed) * 8);
    }

    private static function matches(string $expression, string $address): bool
    {
        $expression = trim($expression);

        if ($expression === '') {
            return false;
        }

        $candidate = @inet_pton(trim($address, '[]'));

        if ($candidate === false) {
            return false;
        }

        $normalized = self::parse($expression);

        if ($normalized === null) {
            // Ein unlesbarer Eintrag sperrt nichts, statt alles.
            return false;
        }

        [$packed, $prefix] = $normalized;

        $candidate = self::unmap($candidate);

        if (strlen($packed) !== strlen($candidate)) {
            // Verschiedene Adressfamilien treffen einander nie.
            return false;
        }

        $bits = strlen($packed) * 8;
        $prefix ??= $bits;

        if ($prefix > $bits) {
            return false;
        }

        return self::sharePrefix($packed, $candidate, $prefix);
    }

    /**
     * Zerlegt den Eintrag in Netz und Länge — beides in der Form, in der
     * verglichen wird.
     *
     * Prüfung und Vergleich nehmen **denselben** Weg hier hindurch. Täten sie
     * es nicht, entstünde genau der Eintrag, den die Prüfung ausschließen soll:
     * einer, der angenommen wird, in der Liste steht und nie greift.
     *
     * @return array{0: string, 1: int|null}|null
     */
    private static function parse(string $expression): ?array
    {
        [$network, $prefix] = self::split($expression);

        $packed = @inet_pton($network);

        if ($packed === false) {
            return null;
        }

        if (! self::isMapped($packed)) {
            return [$packed, $prefix];
        }

        // Die IPv4-Adresse im IPv6-Kleid wird auf ihre IPv4-Form gebracht — und
        // mit ihr die Länge, die ja in 128 Bit gezählt war. Ohne den Abzug
        // stünde am Ende eine Länge von 120 an einer Adresse mit 32 Bit, und
        // der Eintrag träfe nie.
        if ($prefix === null) {
            return [substr($packed, 12), null];
        }

        if ($prefix < 96) {
            // Ein solches Netz reicht über den IPv4-Raum hinaus und lässt sich
            // nicht als IPv4-Netz ausdrücken. Wer das meint, schreibt es in
            // IPv6 ohne die Einbettung.
            return null;
        }

        return [substr($packed, 12), $prefix - 96];
    }

    /**
     * Macht aus einer IPv4-Adresse in IPv6-Kleid wieder eine IPv4-Adresse.
     *
     * `::ffff:203.0.113.5` ist dieselbe Maschine wie `203.0.113.5`, steht aber
     * als sechzehn Bytes da. Genau diese Form liefern PHP-FPM und Node auf
     * einem Anschluss, der beide Familien bedient — ohne diesen Schritt ginge
     * eine Sperre `203.0.113.0/24` dort ins Leere, und zwar unbemerkt.
     */
    private static function unmap(string $packed): string
    {
        return self::isMapped($packed) ? substr($packed, 12) : $packed;
    }

    /**
     * Steckt hier eine IPv4-Adresse im IPv6-Kleid?
     */
    private static function isMapped(string $packed): bool
    {
        return strlen($packed) === 16
            && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff");
    }

    /**
     * Zerlegt `2001:db8::/32` in Netz und Länge. Ohne Schrägstrich ist es eine
     * einzelne Adresse, erkennbar an der fehlenden Länge.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function split(string $expression): array
    {
        $slash = strrpos($expression, '/');

        if ($slash === false) {
            return [$expression, null];
        }

        $prefix = substr($expression, $slash + 1);

        if ($prefix === '' || ! ctype_digit($prefix)) {
            return [$expression, null];
        }

        return [substr($expression, 0, $slash), (int) $prefix];
    }

    /**
     * Stimmen die ersten `$prefix` Bits beider Adressen überein?
     *
     * Byteweise, weil die Adressen als Bytefolge vorliegen: die vollen Bytes im
     * Ganzen, das angebrochene über eine Maske.
     */
    private static function sharePrefix(string $network, string $candidate, int $prefix): bool
    {
        $whole = intdiv($prefix, 8);

        if ($whole > 0 && strncmp($network, $candidate, $whole) !== 0) {
            return false;
        }

        $rest = $prefix % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $rest) & 0xFF;

        return (ord($network[$whole]) & $mask) === (ord($candidate[$whole]) & $mask);
    }
}
