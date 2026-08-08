<?php

// Bündelung von Benachrichtigungen (resources/js/shell/pages/projects/Digest.jsx,
// App\Http\Controllers\ProjectDigestController, resources/views/mail/digest.blade.php).
return [

    'title' => 'Bündelung · :project',
    'help' => 'Mehrere Meldungen innerhalb eines Zeitfensters werden zu einer Nachricht zusammengefasst. Gedacht ist das für die Fehlerwelle: zwanzig Mails über dasselbe Problem liest niemand, eine Sammelnachricht mit zwanzig Einträgen schon.',

    'intro' => [
        'title' => 'Wie die Bündelung wirkt',
        'description' => 'Sie betrifft nur die persönlichen Mails an die Mitglieder. Die Kanäle der Organisation (Slack, Webhook) bekommen ihre Meldungen unverändert einzeln.',
        'critical_hint' => 'Dringende Meldungen werden nie gebündelt — ein Absturz („fatal") geht sofort und einzeln hinaus, auch mitten im Zeitfenster.',
        'personal_hint' => 'Jedes Mitglied darf die Bündelung für sich abschalten und bekommt dann wieder Einzelmeldungen:',
        'personal_link' => 'persönliche Benachrichtigungen',
        'waiting' => 'Gerade warten :count Meldungen auf ihre Sammelnachricht.',
    ],

    'settings' => [
        'title' => 'Zeitfenster und Grenzen',
        'description' => 'Ein Fenster von 0 Minuten schaltet die Bündelung ab — dann geht jede Meldung sofort einzeln hinaus.',
        'read_only_description' => 'Ändern darf diese Werte die Verwaltung der Organisation.',
        'window' => 'Zeitfenster (Minuten)',
        'window_hint' => 'Gerechnet ab der ersten wartenden Meldung. 0 schaltet die Bündelung ab.',
        'window_off' => 'abgeschaltet',
        'window_value' => ':minutes Minuten',
        'min' => 'Mindestanzahl',
        'min_hint' => 'Kommen weniger Meldungen zusammen, gehen sie einzeln hinaus statt als Sammelnachricht.',
        'max' => 'Höchstanzahl',
        'max_hint' => 'Ist sie erreicht, geht die Sammelnachricht sofort hinaus, ohne das Fenster abzuwarten.',
        'submit' => 'Speichern',
    ],

    'flash' => [
        'settings_saved' => 'Die Bündelung wurde gespeichert.',
    ],

    'mail' => [
        'subject' => ':count Meldungen aus :project',
        'heading' => ':count Meldungen aus :project',
        'intro' => 'Diese Meldungen sind im Zeitfenster der Bündelung zusammengekommen.',
        'open_item' => 'Ansehen',
        'origin' => 'Sammelnachricht aus :project · Grad: :level · Anlass: :event',
        'settings_link' => 'Benachrichtigungen einstellen',
    ],

];
