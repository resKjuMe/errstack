<?php

namespace App\Providers;

use App\Notifications\ChannelRegistry;
use App\Notifications\Contracts\ChannelDriver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

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
        //
    }
}
