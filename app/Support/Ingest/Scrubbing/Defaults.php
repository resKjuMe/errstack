<?php

namespace App\Support\Ingest\Scrubbing;

use App\Enums\ScrubRuleType;

/**
 * Was ohne jede Einstellung aus einer Meldung verschwindet.
 *
 * Die Liste ist der eigentliche Datenschutz dieser Aufgabe. Eigene Regeln sind
 * die Ergänzung für das, was nur die betreibende Anwendung weiß — aber ein
 * Passwort im Anfrage-Rumpf darf nicht davon abhängen, dass jemand daran
 * gedacht hat, eine Regel dafür anzulegen. Ein frisch angelegtes Projekt ist
 * deshalb ab der ersten Meldung geschützt.
 *
 * Sie ist absichtlich großzügig. Ein zu Unrecht geschwärztes Feld kostet eine
 * Auskunft, die in den Rohdaten der Anwendung noch steht; ein zu Unrecht
 * gespeichertes Passwort kostet mehr. Wer ein Feld zurückhaben will, kann es
 * nicht — das ist der Preis, und er ist bewusst so gewählt.
 */
final class Defaults
{
    /**
     * Feldnamen, die immer geschwärzt werden. Groß- und Kleinschreibung ist
     * gleichgültig, `*` steht für beliebig viele Zeichen.
     *
     * Platzhalter dort, wo dieselbe Sache ein Dutzend Schreibweisen hat
     * (`db_password`, `passwordConfirmation`, `old-password`) — ohne sie wäre
     * die Liste dreimal so lang und träfe trotzdem die nächste Schreibweise
     * nicht.
     *
     * @var list<string>
     */
    public const FIELDS = [
        // Kennwörter in jeder Schreibweise.
        '*password*', '*passwort*', 'passwd', 'pwd', 'passphrase',

        // Geheimnisse und Nachweise.
        '*secret*', '*credential*', 'authorization', 'proxy-authorization',
        'auth', 'private_key', 'privatekey', 'signature', 'hmac',

        // Sitzungen und Token. `*token*` nimmt `csrf_token`, `access_token` und
        // `_token` in einem.
        '*token*', '*api_key*', '*apikey*', '*session*', 'phpsessid', 'jsessionid',
        'x-csrf-token', 'x-xsrf-token', 'csrf',

        // Kekse. Sie tragen den Sitzungsnachweis und damit die Anmeldung selbst.
        'cookie', 'cookies', 'set-cookie',

        // Zahlungsdaten.
        '*credit_card*', 'creditcard', 'card_number', 'cardnumber', 'ccnumber',
        'cvv', 'cvc', 'iban', 'bic', 'bank_account',

        // Kennungen, die eine Person unmittelbar bezeichnen.
        'ssn', 'social_security_number', 'tax_id', 'steuernummer',
    ];

    /**
     * Muster, die im **Wert** gesucht werden — für alles, dessen Feldname nichts
     * verrät.
     *
     * Bewusst nur vier, und alle vier eng gefasst. Ein Muster, das „irgendeine
     * lange Ziffernfolge" trifft, schwärzt auch Zeitstempel, Bestellnummern und
     * Speicheradressen; nach einer Woche traut dann niemand mehr dem, was er
     * sieht. Die vier hier erkennen jeweils eine Form, die außer der gesuchten
     * kaum etwas hat.
     *
     * @var list<string>
     */
    public const PATTERNS = [
        // Kartennummern der verbreiteten Anbieter (Visa, Mastercard, Amex,
        // Discover, Diners, JCB) — an der Anbieter-Vorwahl festgemacht und nicht
        // an der Länge allein.
        '\b(?:4\d{12}(?:\d{3})?|5[1-5]\d{14}|3[47]\d{13}|6(?:011|5\d{2})\d{12}|3(?:0[0-5]|[68]\d)\d{11}|(?:2131|1800|35\d{3})\d{11})\b',

        // Ein privater Schlüssel im Klartext. Der Kopf allein genügt als
        // Erkennung; geschwärzt wird von dort bis zum Ende des Blocks.
        '-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----',

        // Ein Nachweis, wie er in Kopfzeilen und Protokollzeilen steht.
        '\b(?:Bearer|Basic)\s+[A-Za-z0-9\-._~+/]{8,}={0,2}',

        // Ein JSON Web Token. Der Kopf beginnt praktisch immer mit `eyJ`, weil
        // dahinter `{"` in Base64 steckt.
        '\beyJ[A-Za-z0-9_\-]{6,}\.[A-Za-z0-9_\-]{6,}\.[A-Za-z0-9_\-]{4,}',
    ];

    /**
     * Die einmal gebauten Anweisungen.
     *
     * @var list<Directive>|null
     */
    private static ?array $directives = null;

    /**
     * Die Standardregeln als Anweisungen.
     *
     * Einmal je Prozess und nicht je Meldung: es sind über vierzig Anweisungen,
     * jede prüft beim Bauen ihren übersetzten Ausdruck, und die Liste ist für
     * jedes Projekt dieselbe. Ein Arbeiter der Warteschlange wertet tausende
     * Meldungen hintereinander aus — dort wäre das vierzigtausendmal dieselbe
     * Arbeit mit demselben Ergebnis.
     *
     * @return list<Directive>
     */
    public static function directives(): array
    {
        if (self::$directives !== null) {
            return self::$directives;
        }

        $directives = [];

        foreach (self::FIELDS as $field) {
            $directives[] = new Directive(ScrubRuleType::Field, $field);
        }

        foreach (self::PATTERNS as $pattern) {
            $directives[] = new Directive(ScrubRuleType::Pattern, $pattern);
        }

        return self::$directives = $directives;
    }
}
