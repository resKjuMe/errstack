<?php

// Zugriffstoken (resources/js/shell/pages/api-tokens/Index.jsx und
// App\Http\Controllers\ApiTokenController).
return [

    'title' => 'Zugriffstoken',
    'help' => 'Mit einem Token sprechen Skripte, CI-Läufe und Werkzeuge mit der Schnittstelle unter /api/0/ — im Namen der Organisation „:organization" und nur in den Grenzen der gewählten Geltungsbereiche. Der Wert ersetzt ein Passwort: er gehört in einen Geheimnis-Speicher, nicht ins Repository.',
    'organization' => 'Organisation: :name',

    'empty_title' => 'Noch kein Token',
    'empty_description' => 'Lege eines an, um die Schnittstelle von außen zu nutzen.',

    'created' => [
        'title' => 'Token „:name" ist bereit',
        'description' => 'Jetzt kopieren und sicher ablegen — dieser Wert wird nie wieder angezeigt.',
        'copy' => 'Kopieren',
    ],

    'card' => [
        'expired' => 'Abgelaufen',
        'owner' => 'Gehört: :owner',
        'created_by' => 'Angelegt von :name',
        'last_used' => 'Zuletzt benutzt am :date',
        'never_used' => 'Noch nicht benutzt',
        'valid_until' => 'Gültig bis :date',
        'unlimited' => 'Unbefristet',
        'revoke' => 'Widerrufen',
    ],

    'create' => [
        'title' => 'Neues Token',
        'description' => 'Der Wert wird nur einmal angezeigt.',
        'name' => 'Name',
        'name_placeholder' => 'z. B. Auslieferung aus der CI',
        'kind' => 'Art',
        'scopes' => 'Geltungsbereiche',
        'scope_forbidden' => 'Die eigene Rolle erlaubt diesen Bereich nicht.',
        'expires' => 'Gültigkeit',
        'expires_never' => 'Unbefristet',
        'expires_30' => '30 Tage',
        'expires_90' => '90 Tage',
        'expires_365' => '1 Jahr',
        'submit' => 'Token anlegen',
    ],

    'flash' => [
        'created' => 'Token „:name" angelegt.',
        'revoked' => 'Token „:name" widerrufen.',
    ],

];
