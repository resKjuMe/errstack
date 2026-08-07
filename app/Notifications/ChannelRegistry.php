<?php

namespace App\Notifications;

use App\Notifications\Contracts\ChannelDriver;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Verzeichnis der verfügbaren Kanäle. Es kennt nur die Liste der
 * Treiber-Klassen aus `config/notifications.php` — wer einen Kanal ergänzt,
 * trägt dort eine Klasse nach und ist fertig.
 *
 * Die Treiber werden über den Container erzeugt und danach behalten: sie sind
 * zustandslos, und ein Aufruf fragt sie mehrfach (Formular, Prüfung, Versand).
 */
final class ChannelRegistry
{
    /** @var array<string, ChannelDriver> */
    private array $drivers = [];

    /**
     * @param  list<class-string<ChannelDriver>>  $driverClasses
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $driverClasses,
    ) {}

    /**
     * Alle Kanäle in der Reihenfolge der Konfiguration.
     *
     * @return array<string, ChannelDriver>
     */
    public function all(): array
    {
        foreach ($this->driverClasses as $class) {
            $this->drivers[$class::key()] ??= $this->container->make($class);
        }

        return $this->drivers;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Treiber zu einer Kanal-Kennung.
     *
     * @throws InvalidArgumentException wenn der Kanal nicht (mehr) eingetragen ist
     */
    public function driver(string $key): ChannelDriver
    {
        return $this->all()[$key]
            ?? throw new InvalidArgumentException("Unbekannter Benachrichtigungskanal: {$key}");
    }
}
