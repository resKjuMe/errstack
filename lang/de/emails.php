<?php

// Texte der versendeten E-Mails (app/Mail, resources/views/mail). Sie folgen
// der Sprache des Empfängers, nicht der des Absenders — Laravel setzt sie über
// HasLocalePreference am Konto.
return [

    'invitation' => [
        'subject' => 'Einladung zu :organization',
        'heading' => 'Einladung zu :organization',
        'invited_by' => ':name lädt dich in die Organisation **:organization** ein.',
        'invited' => 'Du bist in die Organisation **:organization** eingeladen.',
        'role' => 'Deine Rolle dort: **:role**.',
        'button' => 'Einladung annehmen',
        'expires' => 'Die Einladung gilt bis zum :date. Wer sie nicht erwartet hat, kann diese Nachricht einfach löschen.',
    ],

    'notification' => [
        'open' => 'In Errstack öffnen',
        'reference' => 'Kennung: :reference',
        'origin' => 'Diese Meldung stammt aus :organization (:level). Wer sie nicht mehr erhalten möchte, ändert den Benachrichtigungsweg in den Einstellungen der Organisation.',
        'personal_origin' => 'Diese Meldung stammt aus :origin (:level) und erreicht dich, weil „:event" in deinen Benachrichtigungen eingeschaltet ist.',
        'critical' => 'Es handelt sich um einen kritischen Alarm. Auch abbestellt und in der Ruhezeit kommt er an — abschalten lässt er sich nur ausdrücklich in den',
        'settings_link' => 'Benachrichtigungs-Einstellungen',
        'unsubscribe_link' => '„:event" abbestellen',
        'all_settings_link' => 'Alle Einstellungen',
    ],

    'regards' => 'Viele Grüße',

];
