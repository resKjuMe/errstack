<?php

namespace App\Support\Setup;

use App\Enums\Platform;

/**
 * Eine Anleitung des Einrichtungs-Assistenten: welches SDK, wie eingebunden,
 * womit geprüft.
 *
 * **Feiner als {@see Platform} — und das ist der Zweck.** Die Plattform eines
 * Projekts ist eine Sortierhilfe für die Liste („PHP", „JavaScript"); eine
 * Anleitung muss dagegen sagen, welches Paket installiert wird und wohin die
 * DSN gehört, und das ist bei Laravel ein anderer Text als bei einem PHP-Skript
 * und bei React ein anderer als beim nackten Browser. Ein Auswahlfeld über die
 * Plattform hätte hier nur die Wahl zwischen falsch und ungefähr.
 *
 * **Ausschließlich die offiziellen SDKs.** Der Einstieg der Datenaufnahme ist
 * Sentry-verträglich (siehe `docs/compat/`), und deshalb ist jede Anleitung hier
 * das unveränderte Original-SDK mit getauschter DSN — kein eigenes Paket, kein
 * Patch. Die Beispiele sind an den nachgewiesenen Beispielen aus `docs/compat/`
 * abgenommen; wer eine Fassung dort anhebt, sieht hier nach.
 *
 * Die Texte drumherum stehen in lang/<sprache>/setup.php, der Code hier: er
 * wird nicht übersetzt.
 */
enum SetupGuide: string
{
    case Laravel = 'php-laravel';
    case Php = 'php';
    case Browser = 'javascript-browser';
    case React = 'javascript-react';
    case Node = 'node';
    case Python = 'python';

    /**
     * Die Plattform, unter der diese Anleitung angeboten wird. Sie entscheidet
     * die Vorauswahl beim Öffnen des Assistenten, nicht das Angebot: gewählt
     * werden darf jede — ein als „PHP" angelegtes Projekt kann sein erstes
     * Ereignis trotzdem aus dem Browser schicken.
     */
    public function platform(): Platform
    {
        return match ($this) {
            self::Laravel, self::Php => Platform::Php,
            self::Browser, self::React => Platform::JavaScript,
            self::Node => Platform::Node,
            self::Python => Platform::Python,
        };
    }

    /** Name des Pakets, so wie es installiert wird. */
    public function package(): string
    {
        return match ($this) {
            self::Laravel => 'sentry/sentry-laravel',
            self::Php => 'sentry/sentry',
            self::Browser => '@sentry/browser',
            self::React => '@sentry/react',
            self::Node => '@sentry/node',
            self::Python => 'sentry-sdk',
        };
    }

    public function label(): string
    {
        return __('setup.guides.'.$this->value.'.label');
    }

    /** Die Anleitung des SDK-Herstellers — für alles, was hier nicht steht. */
    public function docsHref(): string
    {
        return match ($this) {
            self::Laravel => 'https://docs.sentry.io/platforms/php/guides/laravel/',
            self::Php => 'https://docs.sentry.io/platforms/php/',
            self::Browser => 'https://docs.sentry.io/platforms/javascript/',
            self::React => 'https://docs.sentry.io/platforms/javascript/guides/react/',
            self::Node => 'https://docs.sentry.io/platforms/javascript/guides/node/',
            self::Python => 'https://docs.sentry.io/platforms/python/',
        };
    }

    /**
     * Die drei Schritte mit **eingesetzter** DSN — fertig zum Kopieren.
     *
     * Der dritte Schritt ist der eigentliche Grund für den Assistenten: er löst
     * einen Fehler aus. Ohne ihn stünde der Wartebildschirm da und niemand
     * wüsste, was er als Nächstes tun soll.
     *
     * @return array{install: string, configure: string, verify: string}
     */
    public function steps(string $dsn): array
    {
        return match ($this) {
            self::Laravel => [
                'install' => 'composer require sentry/sentry-laravel',
                'configure' => <<<TEXT
                    # Schreibt die DSN in die .env und legt config/sentry.php an
                    php artisan sentry:publish --dsn={$dsn}
                    TEXT,
                'verify' => 'php artisan sentry:test',
            ],
            self::Php => [
                'install' => 'composer require sentry/sentry',
                'configure' => <<<TEXT
                    <?php

                    require __DIR__ . '/vendor/autoload.php';

                    \\Sentry\\init([
                        'dsn' => '{$dsn}',
                        'environment' => 'production',
                        // Ohne das schickt das SDK keine Transaktionen, sondern
                        // verwirft sie als nicht gezogene Stichprobe.
                        'traces_sample_rate' => 1.0,
                    ]);
                    TEXT,
                'verify' => <<<'TEXT'
                    try {
                        throw new RuntimeException('Errstack: Probe aus sentry/sentry');
                    } catch (Throwable $fehler) {
                        \Sentry\captureException($fehler);
                    }
                    TEXT,
            ],
            self::Browser => [
                'install' => 'npm install @sentry/browser',
                'configure' => <<<TEXT
                    // Möglichst früh laden — was vorher passiert, sieht das SDK nicht.
                    import * as Sentry from '@sentry/browser';

                    Sentry.init({
                        dsn: '{$dsn}',
                        environment: 'production',
                        integrations: [
                            Sentry.browserTracingIntegration(),
                            // Sitzungs-Aufzeichnung. Maskierung ausdruecklich
                            // eingeschaltet: sie ersetzt Texte und Eingaben
                            // **im Browser**, bevor etwas gesendet wird. Wer sie
                            // abschaltet, schickt Bildschirminhalte seiner
                            // Nutzer im Klartext.
                            Sentry.replayIntegration({
                                maskAllText: true,
                                maskAllInputs: true,
                                blockAllMedia: true,
                            }),
                        ],
                        tracesSampleRate: 1.0,
                        // Nicht jede Sitzung aufzeichnen, aber jede mit Fehler:
                        // die zweite Quote ist die, die zaehlt.
                        replaysSessionSampleRate: 0.1,
                        replaysOnErrorSampleRate: 1.0,
                    });
                    TEXT,
                'verify' => <<<'TEXT'
                    Sentry.captureException(new Error('Errstack: Probe aus @sentry/browser'));
                    TEXT,
            ],
            self::React => [
                'install' => 'npm install @sentry/react',
                'configure' => <<<TEXT
                    // In der Einstiegsdatei (main.jsx / index.jsx), vor dem Rendern.
                    import * as Sentry from '@sentry/react';

                    Sentry.init({
                        dsn: '{$dsn}',
                        environment: 'production',
                        integrations: [
                            Sentry.browserTracingIntegration(),
                            // Wie beim Browser-SDK: die Maskierung steht hier,
                            // damit sie beim Kopieren mitkommt und nicht beim
                            // Nachlesen vergessen wird.
                            Sentry.replayIntegration({
                                maskAllText: true,
                                maskAllInputs: true,
                                blockAllMedia: true,
                            }),
                        ],
                        tracesSampleRate: 1.0,
                        replaysSessionSampleRate: 0.1,
                        replaysOnErrorSampleRate: 1.0,
                    });
                    TEXT,
                'verify' => <<<'TEXT'
                    // Fehler aus dem Baum fängt die Fehlergrenze ab und meldet sie:
                    <Sentry.ErrorBoundary fallback={<p>Etwas ist schiefgegangen.</p>}>
                        <App />
                    </Sentry.ErrorBoundary>

                    // Und zum Ausprobieren, irgendwo im Baum:
                    <button onClick={() => Sentry.captureException(new Error('Errstack: Probe aus @sentry/react'))}>
                        Testfehler senden
                    </button>
                    TEXT,
            ],
            self::Node => [
                'install' => 'npm install @sentry/node',
                'configure' => <<<TEXT
                    // instrument.mjs — als Allererstes laden:
                    //     node --import ./instrument.mjs app.mjs
                    // Was vor dem SDK geladen wird, kann es nicht mehr überwachen.
                    import * as Sentry from '@sentry/node';

                    Sentry.init({
                        dsn: '{$dsn}',
                        environment: 'production',
                        tracesSampleRate: 1.0,
                    });
                    TEXT,
                'verify' => <<<'TEXT'
                    Sentry.captureException(new Error('Errstack: Probe aus @sentry/node'));

                    // Ein kurzlebiges Skript ist sonst weg, bevor gesendet wurde.
                    await Sentry.flush(5000);
                    TEXT,
            ],
            self::Python => [
                'install' => 'pip install sentry-sdk',
                'configure' => <<<TEXT
                    import sentry_sdk

                    sentry_sdk.init(
                        dsn="{$dsn}",
                        environment="production",
                        traces_sample_rate=1.0,
                    )
                    TEXT,
                'verify' => <<<'TEXT'
                    try:
                        1 / 0
                    except ZeroDivisionError as fehler:
                        sentry_sdk.capture_exception(fehler)

                    # Ein kurzlebiges Skript ist sonst weg, bevor gesendet wurde.
                    sentry_sdk.flush()
                    TEXT,
            ],
        };
    }

    /**
     * Die Anleitung, mit der der Assistent aufgeht. Die Plattform des Projekts
     * ist der einzige Anhaltspunkt, den wir vor der ersten Meldung haben —
     * passt keine (etwa bei „Sonstige"), fällt die Wahl auf die erste, damit
     * der Assistent nicht leer beginnt.
     */
    public static function defaultFor(Platform $platform): self
    {
        foreach (self::cases() as $guide) {
            if ($guide->platform() === $platform) {
                return $guide;
            }
        }

        return self::cases()[0];
    }

    /**
     * Auswahlfeld des Assistenten.
     *
     * @return list<array{value: string, label: string, platform: string, platformShort: string, package: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $guide): array => [
            'value' => $guide->value,
            'label' => $guide->label(),
            'platform' => $guide->platform()->value,
            'platformShort' => $guide->platform()->shortLabel(),
            'package' => $guide->package(),
        ], self::cases());
    }
}
