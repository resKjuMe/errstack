<?php

namespace App\Support\Ingest\Filtering;

use App\Support\Ingest\Normalization\EventNormalizer;

/**
 * Die wenigen Angaben aus einer Meldung, an denen die Filter entscheiden — aus
 * dem **rohen** Sentry-Rumpf gelesen.
 *
 * Roh und nicht normalisiert, weil das die ganze Aufgabe ist: der Filter steht
 * vor der Normalisierung, damit eine aussortierte Meldung deren Arbeit gar
 * nicht erst auslöst ({@see EventNormalizer} geht durch jeden Stapelrahmen,
 * jede Spur und jeden Abschnitt). Ein Filter, der auf dem fertigen Datensatz
 * arbeitet, spart nichts.
 *
 * Jede Angabe wird **einmal** geholt und gemerkt. Die Filterarten überschneiden
 * sich in dem, was sie brauchen — Erweiterungen und Muster sehen beide in die
 * Fehlertexte —, und ohne das Merken liefe der Rumpf je Meldung mehrfach durch.
 *
 * Fehlt eine Angabe, ist sie eine leere Liste und nicht ein leerer Text. Der
 * Unterschied entscheidet: ein Filter auf ein fehlendes Feld greift **nicht**,
 * statt auf den leeren Text zu passen — sonst würde eine Sperrliste ohne
 * passendes Feld jede Meldung aussortieren.
 */
final class EventFacts
{
    /** @var list<string>|null */
    private ?array $messages = null;

    /** @var list<string>|null */
    private ?array $framePaths = null;

    /** @var list<string>|null */
    private ?array $hosts = null;

    /** @var list<string>|null */
    private ?array $addresses = null;

    /** @var array<string, string>|null */
    private ?array $headers = null;

    /**
     * @param  array<mixed>  $data  der Rumpf, wie das SDK ihn geschickt hat
     */
    public function __construct(
        private readonly array $data,
    ) {}

    /**
     * Die Texte, unter denen die Meldung in der Liste erscheinen würde:
     * Meldungstext, Ausnahmetyp, Ausnahmetext und beides zusammen.
     *
     * Beides zusammen (`TypeError: x is not a function`), weil genau so ein
     * Fehler dasteht, wenn man ihn abschreibt, um ihn zu sperren — und ein
     * Muster, das nur auf den Text allein passt, ginge dann ins Leere.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        if ($this->messages !== null) {
            return $this->messages;
        }

        $messages = [];

        foreach (['message', 'logentry'] as $key) {
            $entry = $this->data[$key] ?? null;

            if (is_string($entry)) {
                $messages[] = $entry;

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            foreach (['formatted', 'message'] as $field) {
                if (is_string($entry[$field] ?? null)) {
                    $messages[] = $entry[$field];
                }
            }
        }

        foreach ($this->exceptions() as $exception) {
            $type = is_string($exception['type'] ?? null) ? $exception['type'] : null;
            $value = is_string($exception['value'] ?? null) ? $exception['value'] : null;

            if ($type !== null) {
                $messages[] = $type;
            }

            if ($value !== null) {
                $messages[] = $value;
            }

            if ($type !== null && $value !== null) {
                $messages[] = $type.': '.$value;
            }
        }

        return $this->messages = self::clean($messages);
    }

    /**
     * Die Datei-Angaben aller Stapelrahmen, dazu `culprit` und `transaction`.
     *
     * Aus Ausnahmen **und** Ausführungssträngen: eine Erweiterung, die einen
     * Zeitgeber überschreibt, taucht mitunter nur im Strang auf.
     *
     * @return list<string>
     */
    public function framePaths(): array
    {
        if ($this->framePaths !== null) {
            return $this->framePaths;
        }

        $paths = [];

        foreach ([$this->exceptions(), $this->threads()] as $entries) {
            foreach ($entries as $entry) {
                $frames = $entry['stacktrace']['frames'] ?? null;

                if (! is_array($frames)) {
                    continue;
                }

                foreach ($frames as $frame) {
                    if (! is_array($frame)) {
                        continue;
                    }

                    foreach (['abs_path', 'filename', 'module'] as $field) {
                        if (is_string($frame[$field] ?? null)) {
                            $paths[] = $frame[$field];
                        }
                    }
                }
            }
        }

        foreach (['culprit', 'transaction'] as $field) {
            if (is_string($this->data[$field] ?? null)) {
                $paths[] = $this->data[$field];
            }
        }

        return $this->framePaths = self::clean($paths);
    }

    /**
     * Die Rechnernamen, unter denen die Meldung entstanden ist: der Wirt der
     * aufgerufenen Adresse, die `Host`-Kopfzeile und `server_name`.
     *
     * Alle drei, weil keiner allein verlässlich ist: Server-SDKs schicken
     * `server_name` ohne Anfrage, Browser-SDKs eine Adresse ohne `server_name`,
     * und hinter einem Proxy steht der wahre Name nur in der Kopfzeile.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }

        $hosts = [];

        $url = $this->request()['url'] ?? null;

        if (is_string($url)) {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host)) {
                // `[::1]` kommt aus der Adresse mit Klammern; verglichen wird
                // mit der Adresse, wie sie jemand einträgt.
                $hosts[] = trim($host, '[]');
            }
        }

        foreach (['host', ':authority'] as $header) {
            $value = $this->header($header);

            if ($value !== null) {
                $hosts[] = self::withoutPort($value);
            }
        }

        if (is_string($this->data['server_name'] ?? null)) {
            $hosts[] = $this->data['server_name'];
        }

        return $this->hosts = self::clean($hosts);
    }

    /**
     * Die Absender-Adressen: die des Betroffenen und die aus der Umgebung des
     * Webservers.
     *
     * Die weitergereichten Kopfzeilen (`X-Forwarded-For`) bleiben bewusst
     * außen vor. Sie sind frei wählbar, und eine Sperrliste, die auf einen
     * selbst gesetzten Wert hört, sperrt am Ende den Falschen.
     *
     * @return list<string>
     */
    public function addresses(): array
    {
        if ($this->addresses !== null) {
            return $this->addresses;
        }

        $addresses = [];

        $user = $this->data['user'] ?? null;

        if (is_array($user) && is_string($user['ip_address'] ?? null)) {
            $addresses[] = $user['ip_address'];
        }

        $env = $this->request()['env'] ?? null;

        if (is_array($env) && is_string($env['REMOTE_ADDR'] ?? null)) {
            $addresses[] = $env['REMOTE_ADDR'];
        }

        return $this->addresses = self::clean($addresses);
    }

    /**
     * Das Release, aus dem die Meldung stammt.
     *
     * Gekürzt wie alles andere: ein Release, das aus `git rev-parse HEAD`
     * kommt, trägt den Zeilenumbruch der Ausgabe mit sich. Verglichen wird auf
     * den ganzen Wert, und dieser eine Umbruch entschiede sonst darüber, ob die
     * Sperre greift.
     */
    public function release(): ?string
    {
        $release = $this->data['release'] ?? null;

        if (! is_string($release)) {
            return null;
        }

        $release = trim($release);

        return $release === '' ? null : $release;
    }

    /**
     * Der User-Agent aus den Kopfzeilen der Anfrage.
     */
    public function userAgent(): ?string
    {
        return $this->header('user-agent');
    }

    /**
     * Name und Fassung des Browsers, wie das SDK sie meldet.
     *
     * Aus `contexts.browser` und nicht aus dem User-Agent: dessen Auswertung
     * ist eine Wissenschaft für sich, sie liegt bei jedem zweiten neuen Browser
     * daneben, und das SDK hat die Angabe bereits. Fehlt der Abschnitt, greift
     * der Filter für veraltete Browser eben nicht — das ist die richtige Seite
     * des Irrtums.
     *
     * @return array{name: string, version: string}|null
     */
    public function browser(): ?array
    {
        $contexts = $this->data['contexts'] ?? null;

        if (! is_array($contexts)) {
            return null;
        }

        $browser = $contexts['browser'] ?? null;

        if (! is_array($browser)) {
            return null;
        }

        $name = $browser['name'] ?? null;
        $version = $browser['version'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return [
            'name' => strtolower(trim($name)),
            // Die Fassung darf fehlen: „Internet Explorer, Fassung unbekannt"
            // ist immer noch der Internet Explorer.
            'version' => is_string($version) ? trim($version) : '',
        ];
    }

    /**
     * Der Rechnername aus einer Angabe der Form `name:anschluss`.
     *
     * Ohne Anschluss, weil `localhost:5173` derselbe Rechner ist wie
     * `localhost` und niemand jeden Anschluss einzeln einträgt.
     *
     * Der Sonderfall ist IPv6: dort besteht die Adresse selbst aus
     * Doppelpunkten. Ein `:\d+$` darauf losgelassen macht aus `::1` ein `:` —
     * der Eintrag `::1` griffe dann nie. Die Schreibweise mit Anschluss setzt
     * die Adresse deshalb in Klammern, und nur dann darf hinten etwas
     * abgeschnitten werden.
     */
    private static function withoutPort(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, '[')) {
            $closing = strpos($value, ']');

            return $closing === false ? $value : substr($value, 1, $closing - 1);
        }

        // Ohne Klammern und mit mehr als einem Doppelpunkt ist es eine nackte
        // IPv6-Adresse — dort gehört nichts weggeschnitten.
        if (substr_count($value, ':') > 1) {
            return $value;
        }

        return preg_replace('/:\d+$/', '', $value) ?? $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exceptions(): array
    {
        return self::entries($this->data['exception'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function threads(): array
    {
        return self::entries($this->data['threads'] ?? null);
    }

    /**
     * Die Einträge eines Abschnitts, der beides sein darf: `{"values": […]}`
     * wie in der Spezifikation oder gleich die Liste, wie ältere SDKs sie
     * schicken.
     *
     * @return list<array<string, mixed>>
     */
    private static function entries(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $values = array_is_list($section) ? $section : ($section['values'] ?? null);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    private function request(): array
    {
        $request = $this->data['request'] ?? null;

        return is_array($request) ? $request : [];
    }

    /**
     * Eine Kopfzeile, ohne auf ihre Schreibweise zu achten.
     *
     * Die SDKs geben sie mal als `User-Agent`, mal als `user-agent` weiter, und
     * ein Filter, der die falsche Schreibweise erwischt, tut stillschweigend
     * nichts.
     */
    private function header(string $name): ?string
    {
        if ($this->headers === null) {
            $headers = [];
            $raw = $this->request()['headers'] ?? null;

            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $headers[strtolower($key)] = $value;
                    }
                }
            }

            $this->headers = $headers;
        }

        $value = $this->headers[$name] ?? null;

        if ($value === null) {
            return null;
        }

        // Gekürzt aus demselben Grund wie das Release: verglichen wird der
        // ganze Wert, und Kopfzeilen tragen regelmäßig führende Leerzeichen.
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function clean(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', $values),
            static fn (string $value): bool => $value !== '',
        )));
    }
}
