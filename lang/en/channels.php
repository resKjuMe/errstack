<?php

// Notification routes of an organization (app/Notifications/Drivers). This text
// describes setting up a channel and appears in the form.
return [

    'mail' => [
        'label' => 'Email',
        'description' => 'Sends the message to a fixed list of addresses.',
        'recipients' => 'Recipients',
        'recipients_hint' => 'One address per line.',
        'summary_count' => ':count recipients',
        'no_recipients' => 'No recipient address is configured for this channel.',
    ],

    'test' => [
        'title' => 'Test message from Errstack',
        'body' => 'This is what a message from Errstack looks like in this channel. Triggered from the settings of :organization.',
        'context_organization' => 'Organization',
        'context_reason' => 'Occasion',
        'context_reason_value' => 'Test message',
    ],

    'slack' => [
        'label' => 'Slack',
        'description' => 'Sends the message into a Slack channel through an incoming webhook.',
        'webhook_url' => 'Webhook URL',
        'webhook_url_hint' => 'Slack › Apps › Incoming Webhooks. The target channel is part of the URL.',
        'summary' => 'Incoming webhook',
    ],

    'discord' => [
        'label' => 'Discord',
        'description' => 'Sends the message to Discord through a channel webhook.',
        'webhook_url' => 'Webhook URL',
        'webhook_url_hint' => 'Channel › Settings › Integrations › Webhooks.',
        'summary' => 'Channel webhook',
    ],

    'teams' => [
        'label' => 'Microsoft Teams',
        'description' => 'Sends the message as a card into a Teams channel.',
        'webhook_url' => 'Webhook URL',
        'webhook_url_hint' => 'Channel › Workflows or Connectors › set up a "Webhook" and paste the URL here.',
        'summary' => 'Incoming webhook',
    ],

    'webhook' => [
        'label' => 'Webhook',
        'description' => 'Sends the message as signed JSON to an address of your own.',
        'url' => 'Target URL',
        'secret' => 'Secret',
        'secret_hint' => 'Every delivery is signed with this value. Verification: see docs/webhooks.md.',
        'summary' => 'own address',
    ],

];
