<?php

namespace App\Support\Tags;

use App\Models\Event;

/**
 * Die Merkmale einer Meldung: ein flaches Verzeichnis aus Name und Wert.
 *
 * Ein Merkmal ist das, wonach jemand eine Fehlerliste einschränkt — „nur
 * Chrome", „nur Fassung 3.4.1", „nur auf web-07". Die Meldung trägt das alles
 * schon, aber verstreut: der Browser steht im Kontext-Abschnitt, die Fassung in
 * einer eigenen Spalte, die Adresse in der Anfrage, und was die Anwendung selbst
 * angehängt hat, steht unter `tags`. Hier wird daraus **eine** Form, und ab
 * dieser Stelle ist „Merkmal" ein Begriff und keine Sammlung von Sonderfällen.
 *
 * **Zusammengesetzte Werte sind Absicht.** `browser` ist `Chrome 124.0`, also
 * Name **und** Fassung — denn genau so wird gefragt: „tritt das nur in Chrome
 * 124 auf?". Daneben steht `browser.name` mit `Chrome` allein, für die andere
 * Frage: „tritt das in allen Chrome-Fassungen auf?". Beide sind je eine Zeile in
 * der Auswertung, und beide werden gebraucht — dieselbe Aufteilung, die Sentry
 * verwendet, und der Grund, warum ein von dort kommender Filterausdruck hier
 * dasselbe bedeutet.
 *
 * **Nichts Personenbezogenes.** Die Nutzer-Angaben der Meldung (`user.id`,
 * `user.email`, `user.ip_address`) werden bewusst **nicht** zu Merkmalen: sie
 * würden dauerhaft in einer Zähltabelle landen, die das Aufräumen der Ereignisse
 * (O2) überlebt — das Scrubbing (I7) hätte dann an einer Stelle gearbeitet, an
 * der die Daten längst wieder herausgeschrieben sind. Wie viele Nutzer betroffen
 * sind, sagt der Zähler am Eintrag; **wer**, steht am Ereignis und nirgends
 * sonst.
 */
final class EventTags
{
    /**
     * Merkmale, die nicht aus `tags` stammen, sondern aus den festen Feldern der
     * Meldung — und die deshalb Vorrang haben, wenn eine Anwendung zufällig
     * denselben Namen benutzt.
     *
     * Der Vorrang ist wichtig: würde ein selbst gesetztes `release` das aus der
     * Meldung überschreiben, stünde in der Auswertung eine Fassung, nach der
     * sich die Fehlerliste nicht filtern lässt.
     *
     * @var list<string>
     */
    public const RESERVED = [
        'level',
        'platform',
        'environment',
        'release',
        'dist',
        'server_name',
        'transaction',
        'logger',
        'url',
        'browser',
        'browser.name',
        'os',
        'os.name',
        'device',
        'device.family',
        'runtime',
        'runtime.name',
        'sdk',
    ];

    /**
     * Wie lang ein Merkmalswert höchstens wird.
     *
     * Dieselbe Grenze wie die Spalte hat. Abgeschnitten wird hier und nicht in
     * der Datenbank: ein stillschweigend gekürzter Wert wäre unter MySQL je nach
     * Betriebsart entweder ein anderer Wert oder ein Abbruch der Verarbeitung.
     */
    private const VALUE_CHARS = 400;

    /**
     * Die Merkmale einer ausgewerteten Meldung.
     *
     * @return array<string, string>
     */
    public static function forEvent(Event $event): array
    {
        $tags = [];

        // Die eigenen Marken der Anwendung zuerst, damit die festen Felder sie
        // überschreiben und nicht umgekehrt. Dass hier Zeichenketten stehen,
        // hat die Aufnahme bereits erledigt (Sanitizer::entries) — geprüft wird
        // nur noch, was auch für die festen Felder gilt: leer fällt weg.
        foreach ($event->tags ?? [] as $name => $value) {
            self::put($tags, $name, $value);
        }

        self::put($tags, 'level', $event->level->value);
        self::put($tags, 'platform', $event->platform);
        self::put($tags, 'environment', $event->environment);
        self::put($tags, 'release', $event->release);
        self::put($tags, 'dist', $event->dist);
        self::put($tags, 'server_name', $event->server_name);
        self::put($tags, 'transaction', $event->transaction);
        self::put($tags, 'logger', $event->logger);
        self::put($tags, 'url', self::url($event));
        self::put($tags, 'sdk', $event->sdkIdentifier());

        foreach (['browser' => 'browser', 'os' => 'os', 'runtime' => 'runtime'] as $context => $name) {
            [$full, $short] = self::nameAndVersion($event->contexts[$context] ?? null);

            self::put($tags, $name, $full);
            self::put($tags, $name.'.name', $short);
        }

        // Das Gerät nennt seine Bauart nicht in `version`, sondern in `family`
        // („iPhone", „Pixel 7") — deshalb hier und nicht in der Schleife oben.
        $device = $event->contexts['device'] ?? null;

        if (is_array($device)) {
            self::put($tags, 'device', self::text($device['model'] ?? null) ?? self::text($device['family'] ?? null));
            self::put($tags, 'device.family', self::text($device['family'] ?? null));
        }

        return $tags;
    }

    /**
     * Nimmt ein Merkmal auf — sofern es eines ist.
     *
     * Leere Werte fallen heraus und werden **nicht** durch „unbekannt" ersetzt.
     * Ein erfundener Wert wäre in der Verteilung ein Balken, in der Suche ein
     * Treffer und in beiden Fällen eine Aussage, die niemand gemacht hat: dass
     * ein Merkmal fehlt, heißt nicht, dass es einen gemeinsamen Wert hat.
     *
     * @param  array<string, string>  $tags
     */
    private static function put(array &$tags, string $name, ?string $value): void
    {
        $name = trim($name);
        $value = self::text($value);

        if ($name === '' || $value === null) {
            return;
        }

        $tags[$name] = $value;
    }

    /**
     * Ein Wert in brauchbarer Form, oder `null`.
     */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, self::VALUE_CHARS);
    }

    /**
     * „Chrome 124.0" und „Chrome" aus einem Kontext-Fach.
     *
     * @param  mixed  $context  das Fach, wie die Normalisierung es abgelegt hat
     * @return array{0: string|null, 1: string|null}
     */
    private static function nameAndVersion(mixed $context): array
    {
        if (! is_array($context)) {
            return [null, null];
        }

        $name = self::text($context['name'] ?? null);

        if ($name === null) {
            return [null, null];
        }

        $version = self::text($context['version'] ?? null);

        return [$version === null ? $name : $name.' '.$version, $name];
    }

    /**
     * Die aufgerufene Adresse — ohne Abfrageteil.
     *
     * Der Abfrageteil bleibt draußen, und das ist der Unterschied zwischen einem
     * brauchbaren Merkmal und einer Liste mit einer Zeile je Aufruf: `?id=4711`
     * macht jede Adresse einzigartig. Wonach gefragt wird, ist „welche Seite",
     * und die steht im Pfad.
     */
    private static function url(Event $event): ?string
    {
        $url = self::text($event->request['url'] ?? null);

        if ($url === null) {
            return null;
        }

        $cut = strpos($url, '?');

        return $cut === false ? $url : substr($url, 0, $cut);
    }
}
