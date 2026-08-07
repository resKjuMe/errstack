<?php

// Benachrichtigungswege einer Organisation (app/Notifications/Drivers). Diese
// Texte beschreiben die Einrichtung eines Kanals und stehen im Formular.
return [

    'mail' => [
        'label' => 'E-Mail',
        'description' => 'Schickt die Meldung an eine feste Liste von Adressen.',
        'recipients' => 'Empfänger',
        'recipients_hint' => 'Eine Adresse je Zeile.',
        'summary_count' => ':count Empfänger',
        'no_recipients' => 'Für diesen Kanal ist keine Empfängeradresse hinterlegt.',
    ],

    'test' => [
        'title' => 'Testnachricht aus Errstack',
        'body' => 'So sieht eine Meldung von Errstack in diesem Kanal aus. Ausgelöst aus den Einstellungen von :organization.',
        'context_organization' => 'Organisation',
        'context_reason' => 'Anlass',
        'context_reason_value' => 'Testnachricht',
    ],

    'slack' => [
        'label' => 'Slack',
        'description' => 'Schickt die Meldung über einen eingehenden Webhook in einen Slack-Kanal.',
        'webhook_url' => 'Webhook-URL',
        'webhook_url_hint' => 'Slack › Apps › Incoming Webhooks. Der Ziel-Kanal steckt in der URL.',
        'summary' => 'Eingehender Webhook',
    ],

    'discord' => [
        'label' => 'Discord',
        'description' => 'Schickt die Meldung über einen Kanal-Webhook nach Discord.',
        'webhook_url' => 'Webhook-URL',
        'webhook_url_hint' => 'Kanal › Einstellungen › Integrationen › Webhooks.',
        'summary' => 'Kanal-Webhook',
    ],

    'teams' => [
        'label' => 'Microsoft Teams',
        'description' => 'Schickt die Meldung als Karte in einen Teams-Kanal.',
        'webhook_url' => 'Webhook-URL',
        'webhook_url_hint' => 'Kanal › Workflows bzw. Connectors › „Webhook" einrichten und die URL hier eintragen.',
        'summary' => 'Eingehender Webhook',
    ],

    'webhook' => [
        'label' => 'Webhook',
        'description' => 'Schickt die Meldung als signiertes JSON an eine eigene Adresse.',
        'url' => 'Ziel-URL',
        'secret' => 'Geheimnis',
        'secret_hint' => 'Mit diesem Wert wird jede Zustellung unterschrieben. Prüfung: siehe docs/webhooks.md.',
        'summary' => 'eigene Adresse',
    ],

];
