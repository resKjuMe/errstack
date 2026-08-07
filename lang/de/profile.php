<?php

// Profilseite (resources/js/shell/pages/Profile.jsx).
return [

    'title' => 'Profil',
    'help' => 'Name, E-Mail-Adresse, Sprache und Passwort ändern — oder das Konto endgültig löschen.',
    'saved' => 'Gespeichert.',

    'information' => [
        'title' => 'Stammdaten',
        'hint' => 'Name, E-Mail-Adresse und Sprache dieses Kontos.',
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'locale' => 'Sprache der Oberfläche',
        'locale_hint' => 'Gilt auch für E-Mails und Benachrichtigungen. Ohne eigene Wahl folgt die Anzeige der Spracheinstellung des Browsers.',
        'locale_browser' => 'Sprache des Browsers',
        'unverified' => 'Diese E-Mail-Adresse ist noch nicht bestätigt.',
        'resend' => 'Bestätigungslink erneut senden',
        'resent' => 'Bestätigungslink verschickt.',
        'submit' => 'Speichern',
    ],

    'password' => [
        'title' => 'Passwort ändern',
        'hint' => 'Ein langes, zufälliges Passwort schützt das Konto am besten.',
        'current' => 'Aktuelles Passwort',
        'new' => 'Neues Passwort',
        'confirmation' => 'Neues Passwort wiederholen',
        'submit' => 'Speichern',
    ],

    'delete' => [
        'title' => 'Konto löschen',
        'hint' => 'Mit dem Konto verschwinden alle daran hängenden Daten — unwiderruflich.',
        'button' => 'Konto löschen',
        'dialog_title' => 'Konto wirklich löschen?',
        'dialog_hint' => 'Zur Bestätigung bitte das Passwort eingeben. Danach ist das Konto endgültig gelöscht.',
        'password' => 'Passwort',
        'cancel' => 'Abbrechen',
        'confirm' => 'Konto löschen',
    ],

];
