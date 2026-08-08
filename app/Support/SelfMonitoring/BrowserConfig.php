<?php

namespace App\Support\SelfMonitoring;

/**
 * Was der Browser braucht, um mitzumelden.
 *
 * Die Angaben stehen an der Inertia-Antwort und nicht in einer eigenen
 * `VITE_…`-Variable: die DSN wäre sonst zweimal zu pflegen, und die zweite
 * Fassung steckte im gebauten Bündel — ein Wechsel der Installation
 * verlangte einen neuen Build statt einer neuen Zeile in der `.env`.
 *
 * Dass die DSN dabei im Quelltext der Seite landet, ist kein Versehen: sie
 * enthält den **öffentlichen** Schlüssel, und der ist für genau diesen Zweck
 * gemacht — jedes Browser-SDK der Welt trägt ihn offen. Er erlaubt das
 * Einliefern von Meldungen und sonst nichts.
 *
 * `null`, solange keine DSN eingerichtet ist. Die Oberfläche fragt dann gar
 * nicht erst nach dem SDK.
 */
final class BrowserConfig
{
    /**
     * @return array{dsn: string, environment: string, release: string|null, tracesSampleRate: float|null}|null
     */
    public static function build(): ?array
    {
        if (! config('selfmonitoring.browser.enabled')) {
            return null;
        }

        $dsn = Dsn::parse(config('sentry.dsn'));

        if ($dsn === null) {
            return null;
        }

        $rate = config('selfmonitoring.browser.traces_sample_rate');

        return [
            'dsn' => $dsn->toString(),
            // Dieselbe Umgebung wie serverseitig. Zwei Angaben dafür wären zwei
            // Filter für eine Anwendung: ein Fehler im Browser und der
            // Serverfehler, der ihn ausgelöst hat, gehören nebeneinander.
            'environment' => (string) (config('sentry.environment') ?? config('app.env')),
            'release' => config('sentry.release'),
            'tracesSampleRate' => $rate === null ? null : (float) $rate,
        ];
    }
}
