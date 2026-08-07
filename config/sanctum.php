<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Errstack hat keine getrennte Single-Page-Anwendung: die Oberfläche läuft
    | über Inertia in derselben Anwendung und authentifiziert sich per Sitzung.
    | Es gibt deshalb keine Domain, die Sitzungs-Cookies für die Schnittstelle
    | erhalten soll.
    |
    */

    'stateful' => [],

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | Bewusst leer: die öffentliche Schnittstelle akzeptiert ausschließlich
    | API-Tokens. Stünde hier `web`, würde eine offene Browser-Sitzung
    | mitauthentifizieren — dann käme man ohne Token an Daten, für die gerade
    | keine Geltungsbereiche geprüft wurden.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | Keine pauschale Frist für alle Tokens — die Gültigkeit wird je Token beim
    | Anlegen gewählt und steht in `expires_at`.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Fester Anfang jedes Token-Werts. Damit erkennen die Geheimnis-Scanner von
    | GitHub & Co. ein versehentlich eingecheckter Token als solchen.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'errstack_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
