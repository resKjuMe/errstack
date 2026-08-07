<?php

use App\Notifications\Drivers\DiscordDriver;
use App\Notifications\Drivers\MailDriver;
use App\Notifications\Drivers\SlackDriver;
use App\Notifications\Drivers\TeamsDriver;
use App\Notifications\Drivers\WebhookDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Verfügbare Kanäle
    |--------------------------------------------------------------------------
    |
    | Die Reihenfolge ist zugleich die Reihenfolge im Auswahlfeld der
    | Einrichtung. Ein neuer Benachrichtigungsweg ist eine Klasse, die
    | App\Notifications\Contracts\ChannelDriver umsetzt, und ein Eintrag hier —
    | am Alert-Kern und an der Oberfläche ist dafür nichts zu ändern.
    |
    */

    'channels' => [
        MailDriver::class,
        SlackDriver::class,
        DiscordDriver::class,
        TeamsDriver::class,
        WebhookDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Zustellung
    |--------------------------------------------------------------------------
    |
    | `timeout` begrenzt, wie lange auf das Ziel gewartet wird. `tries` und
    | `backoff` gelten für den Zustell-Job: nach dem letzten Versuch gilt die
    | Zustellung als fehlgeschlagen und ist im Protokoll von Hand wiederholbar.
    |
    */

    'timeout' => (int) env('NOTIFICATIONS_TIMEOUT', 10),

    'tries' => (int) env('NOTIFICATIONS_TRIES', 5),

    'backoff' => [10, 60, 300, 900],

    /*
    |--------------------------------------------------------------------------
    | Protokoll
    |--------------------------------------------------------------------------
    |
    | Wie viele Zustellversuche die Oberfläche je Organisation zeigt.
    |
    */

    'log_limit' => 50,

];
