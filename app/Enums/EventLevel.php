<?php

namespace App\Enums;

/**
 * Der Schweregrad einer Meldung.
 *
 * Die Werte sind die von Sentry, weil die SDKs genau diese schicken — eine
 * eigene Skala müsste an jeder Schnittstelle wieder übersetzt werden, und beim
 * nächsten SDK würde die Übersetzung vergessen.
 *
 * Anders als beim Ereignistyp ist ein unbekannter Grad hier kein eigener Fall:
 * er wird auf {@see self::Error} zurückgeführt. Ein Fehler-Werkzeug, das eine
 * Meldung wegen eines unbekannten Wortes im Feld `level` verwirft, verliert
 * genau die Meldungen, die ein neues SDK schickt.
 */
enum EventLevel: string
{
    case Fatal = 'fatal';
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
    case Debug = 'debug';

    /**
     * Der Grad, den eine Meldung ohne brauchbare Angabe bekommt.
     *
     * `error` und nicht `info`: was ohne Angabe hereinkommt, ist im Zweifel
     * ein Absturz — die SDKs setzen das Feld vor allem dann, wenn sie
     * *abweichen* wollen. Der stillere Vorgabewert würde echte Fehler in der
     * Liste nach unten schieben.
     */
    public const DEFAULT = self::Error;

    /**
     * Führt zurück, was ein SDK geschickt hat.
     *
     * Neben Schreibweise und Leerraum werden die Wörter abgefangen, die
     * einzelne SDKs statt der Sentry-Werte verwenden: `critical` aus der
     * Python-Protokollierung, `warn` aus dem JavaScript-Umfeld, `err` aus
     * syslog-nahen Quellen.
     */
    public static function normalize(mixed $level): self
    {
        if ($level instanceof self) {
            return $level;
        }

        if (! is_string($level)) {
            return self::DEFAULT;
        }

        $value = strtolower(trim($level));

        return match ($value) {
            'critical', 'crit', 'panic', 'emergency', 'alert' => self::Fatal,
            'err' => self::Error,
            'warn' => self::Warning,
            'notice', 'log' => self::Info,
            'trace', 'verbose' => self::Debug,
            default => self::tryFrom($value) ?? self::DEFAULT,
        };
    }

    public function label(): string
    {
        return __('enums.event_level.'.$this->value);
    }

    /**
     * Rang für Sortierung und Schwellen („ab Warnung benachrichtigen"). Größer
     * heißt dringender; die Zahlen sind Abstände, keine Kennungen, und dürfen
     * deshalb nicht in der Datenbank landen.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Fatal => 50,
            self::Error => 40,
            self::Warning => 30,
            self::Info => 20,
            self::Debug => 10,
        };
    }
}
