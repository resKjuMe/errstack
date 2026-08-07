<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Notifications\ChannelRegistry;
use App\Notifications\Contracts\ChannelDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Das Kanal-Verzeichnis kennt nur die Liste aus config/notifications.php.
        // Als Singleton, damit die Treiber einmal erzeugt und danach
        // wiederverwendet werden — sie sind zustandslos.
        $this->app->singleton(ChannelRegistry::class, function (Application $app): ChannelRegistry {
            /** @var list<class-string<ChannelDriver>> $channels */
            $channels = $app->make('config')->get('notifications.channels', []);

            return new ChannelRegistry($app, $channels);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureSanctum();
        $this->configureRateLimiting();
    }

    /**
     * Sanctum arbeitet mit unserem eigenen Token-Modell — Organisation und
     * Aussteller kommen darin hinzu. Die Tabelle legt unsere eigene Migration an;
     * die des Pakets wird nur auf Wunsch veröffentlicht und läuft nie von selbst
     * mit, deshalb ist hier nichts abzuschalten.
     */
    private function configureSanctum(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
    }

    /**
     * Ratenbegrenzung der öffentlichen Schnittstelle: gezählt wird je Token,
     * ohne Token je Absender-Adresse.
     *
     * Bewusst am Token-Wert aus der Anfrage statt am gefundenen Datensatz: so
     * greift die Grenze auch für Aufrufe, die gar nicht erst durch die
     * Anmeldung kommen — sonst wäre ein Durchprobieren von Tokens unbegrenzt.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api-v0', function (Request $request): Limit {
            $bearer = $request->bearerToken();

            $key = $bearer === null
                ? 'ip:'.$request->ip()
                : 'token:'.hash('sha256', $bearer);

            return Limit::perMinutes(
                (int) config('api.rate_limit.decay_minutes', 1),
                (int) config('api.rate_limit.max_attempts', 60),
            )->by($key);
        });
    }
}
