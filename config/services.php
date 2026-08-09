<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub (X1)
    |--------------------------------------------------------------------------
    |
    | Die Zugangsdaten der OAuth-App dieser Installation — nicht die einer
    | Organisation. Was einer Organisation gehört, ist ihr Zugriffstoken, und
    | das steht verschlüsselt an ihrer Anbindung (App\Models\Integration).
    |
    | Ohne `client_id`/`client_secret` bietet die Oberfläche das Verbinden gar
    | nicht erst an: ein Knopf, der bei GitHub in einer Fehlerseite endet, ist
    | schlechter als der Hinweis, dass die Installation dafür nicht eingerichtet
    | ist.
    |
    | `webhook_secret` unterschreibt die eingehenden Ereignisse. Es gilt für die
    | Installation und nicht je Anbindung: geprüft wird damit die Herkunft der
    | Anfrage, nicht die Zugehörigkeit — zu welcher Organisation ein Ereignis
    | gehört, sagt das Repository darin.
    |
    | `url` und `api_url` stehen getrennt, damit dieselbe Anbindung auch gegen
    | ein selbst betriebenes GitHub Enterprise läuft: dort liegt die
    | Schnittstelle unter `<host>/api/v3` und nicht auf einem eigenen Namen.
    */
    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'url' => env('GITHUB_URL', 'https://github.com'),
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'timeout' => (int) env('GITHUB_TIMEOUT', 10),
    ],

];
