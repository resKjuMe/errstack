<?php

// Text of the outgoing emails (app/Mail, resources/views/mail). They follow the
// recipient's language, not the sender's — Laravel picks it up through
// HasLocalePreference on the account.
return [

    'invitation' => [
        'subject' => 'Invitation to :organization',
        'heading' => 'Invitation to :organization',
        'invited_by' => ':name invites you to the organization **:organization**.',
        'invited' => 'You have been invited to the organization **:organization**.',
        'role' => 'Your role there: **:role**.',
        'button' => 'Accept invitation',
        'expires' => 'The invitation is valid until :date. If you did not expect it, you can simply delete this message.',
    ],

    'notification' => [
        'open' => 'Open in Errstack',
        'reference' => 'Reference: :reference',
        'origin' => 'This message comes from :organization (:level). To stop receiving it, change the notification route in the organization settings.',
        'personal_origin' => 'This message comes from :origin (:level) and reaches you because ":event" is switched on in your notifications.',
        'critical' => 'This is a critical alert. It arrives even when unsubscribed and during quiet hours — switching it off is only possible explicitly in the',
        'settings_link' => 'notification settings',
        'unsubscribe_link' => 'Unsubscribe from ":event"',
        'all_settings_link' => 'All settings',
    ],

    'regards' => 'Kind regards',

];
