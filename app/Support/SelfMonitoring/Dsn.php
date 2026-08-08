<?php

namespace App\Support\SelfMonitoring;

use App\Models\ProjectKey;

/**
 * Die DSN, mit der Errstack sich selbst meldet — zerlegt in das, was die
 * einzelnen Meldewege davon brauchen.
 *
 * Vier Wege führen zur selben Installation, und alle vier lassen sich aus
 * derselben Angabe herleiten: das SDK schickt an `/api/{projekt}/envelope`, der
 * Browser ebenso, die Sicherheitsberichte gehen an `/api/{projekt}/security`
 * und das Lebenszeichen des Zeitplans an `/api/{projekt}/cron/{monitor}/{key}`.
 * Sie einzeln zu konfigurieren hieße, vier Angaben auseinanderlaufen zu lassen,
 * die zwangsläufig zusammengehören — eine davon vergessen, und die
 * Selbstüberwachung meldet still auf zwei Installationen.
 *
 * Der Aufbau ist der von Sentry, weil die SDKs nichts anderes lesen:
 * `{schema}://{öffentlicher Schlüssel}@{rechner}[:{port}][{pfad}]/{projekt}`.
 * Er entsteht in {@see ProjectKey::dsn()} und wird hier zurückgelesen.
 *
 * Unbrauchbares ergibt `null` und keine Ausnahme: eine halb ausgefüllte
 * Zeile in der `.env` darf die Anwendung nicht am Starten hindern. Was nicht
 * gemeldet werden kann, wird nicht gemeldet — sichtbar wird das daran, dass
 * nichts ankommt, und nicht an einer weißen Seite.
 */
final readonly class Dsn
{
    private function __construct(
        public string $scheme,
        public string $host,
        public ?int $port,
        public string $path,
        public string $publicKey,
        public string $projectId,
    ) {}

    /**
     * Liest eine DSN. `null`, wenn sie fehlt oder nicht vollständig ist.
     */
    public static function parse(?string $dsn): ?self
    {
        $dsn = trim((string) $dsn);

        if ($dsn === '') {
            return null;
        }

        $parts = parse_url($dsn);

        if ($parts === false) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $publicKey = $parts['user'] ?? null;
        $path = $parts['path'] ?? '';

        if ($scheme === null || $host === null || $publicKey === null || $publicKey === '') {
            return null;
        }

        // Das letzte Pfadstück ist die Projekt-Nummer, alles davor ein
        // Unterverzeichnis der Installation. Beides zu trennen ist nötig, weil
        // die eigenen Endpunkte hinter `/api` liegen und nicht hinter der
        // Nummer: aus `…/errstack/7` wird `…/errstack/api/7/envelope`.
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        $projectId = array_pop($segments);

        if ($projectId === null || ! ctype_digit($projectId)) {
            return null;
        }

        return new self(
            scheme: $scheme,
            host: $host,
            port: isset($parts['port']) ? (int) $parts['port'] : null,
            path: $segments === [] ? '' : '/'.implode('/', $segments),
            publicKey: $publicKey,
            projectId: $projectId,
        );
    }

    /**
     * Die Adresse der Installation ohne Projekt und ohne Schlüssel — die, die
     * jemand in den Browser tippen würde.
     */
    public function baseUrl(): string
    {
        $port = $this->port === null ? '' : ':'.$this->port;

        return $this->scheme.'://'.$this->host.$port.$this->path;
    }

    /**
     * Wohin die Sicherheitsberichte des Browsers gehen.
     *
     * Der Schlüssel steht im Abfrageteil und nicht in einer Kopfzeile:
     * `report-uri` nimmt eine Adresse und sonst nichts, der Browser schickt den
     * Bericht ohne unser Zutun.
     */
    public function securityReportUrl(): string
    {
        return $this->baseUrl().'/api/'.$this->projectId.'/security/?sentry_key='.$this->publicKey;
    }

    /**
     * Das Lebenszeichen eines überwachten Cronjobs. `$monitor` ist die Kennung,
     * unter der er auf der empfangenden Installation angelegt ist.
     */
    public function cronCheckInUrl(string $monitor): string
    {
        return $this->baseUrl().'/api/'.$this->projectId.'/cron/'.rawurlencode($monitor).'/'.$this->publicKey;
    }

    /**
     * Die DSN, wie ein SDK sie erwartet — hergestellt aus den zerlegten Teilen
     * und nicht durchgereicht, damit die Fassung für den Browser dieselbe ist,
     * die auch hier gelesen wurde.
     */
    public function toString(): string
    {
        $port = $this->port === null ? '' : ':'.$this->port;

        return $this->scheme.'://'.$this->publicKey.'@'.$this->host.$port.$this->path.'/'.$this->projectId;
    }
}
