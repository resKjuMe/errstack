<?php

// Navigation, Nutzer-Menü und die Beschriftungen des Grundgerüsts
// (app/Support/ShellData, resources/js/shell).
return [

    'guest' => 'Gast',
    'sign_in' => 'Anmelden',
    'sign_out' => 'Abmelden',
    'menu' => 'Menü',

    'sidebar' => [
        'collapse' => 'Leiste einklappen',
        'expand' => 'Leiste ausklappen',
    ],

    // Umschalter für die Organisation am Kopf der Seitenleiste.
    'org' => [
        'label' => 'Organisation',
        'switch' => 'Organisation wechseln',
        'create' => 'Organisation anlegen',
        'none' => 'Keine Organisation',
    ],

    // Feste Anker im Fuß der Seitenleiste.
    'footer' => [
        'settings' => 'Einstellungen',
        'notifications' => 'Benachrichtigungen',
    ],

    // Unter-Navigation des Einstellungsbereichs (app/Support/SettingsNav).
    'settings' => [
        'groups' => [
            'organization' => 'Organisation',
            'projects' => 'Projekte',
            'privacy' => 'Datenschutz und Aufnahme',
            'notifications' => 'Benachrichtigungen',
            'account' => 'Konto',
        ],

        'links' => [
            'organization' => 'Stammdaten',
            'organizations' => 'Alle Organisationen',
            'audit_log' => 'Änderungsprotokoll',
            'repositories' => 'Repositories',
            'organization_quotas' => 'Kontingente',
            'projects' => 'Alle Projekte',
            'project' => 'Stammdaten',
            'project_setup' => 'Einrichtung',
            'project_keys' => 'Schlüssel und DSN',
            'project_ownership' => 'Zuständigkeit',
            'project_grouping' => 'Gruppierung',
            'project_alerts' => 'Alarme',
            'project_issue_alerts' => 'Alarmregeln',
            'project_crons' => 'Cronjobs',
            'project_uptime' => 'Erreichbarkeit',
            'project_performance' => 'Leistungserkennung',
            'project_quotas' => 'Kontingente',
            'organization_privacy' => 'Datenschutz der Organisation',
            'project_privacy' => 'Datenschutz des Projekts',
            'project_filters' => 'Eingangsfilter',
            'project_sampling' => 'Stichproben',
            'project_spikes' => 'Ausschlag-Schutz',
            'notification_channels' => 'Kanäle der Organisation',
            'notification_preferences' => 'Eigene Benachrichtigungen',
            'project_digest' => 'Bündelung',
            'profile' => 'Profil',
            'api_tokens' => 'Zugriffstoken',
        ],
    ],

    // Überschriften der Navigationsgruppen in der Seitenleiste.
    'groups' => [
        'monitor' => 'Überwachen',
        'investigate' => 'Untersuchen',
        'ship' => 'Ausliefern',
    ],

    'links' => [
        'dashboard' => 'Übersicht',
        'issues' => 'Fehler',
        'tags' => 'Merkmale',
        'feedback' => 'Rückmeldungen',
        'releases' => 'Versionen',
        'discover' => 'Auswertung',
        'performance' => 'Leistung',
        'performance_issues' => 'Leistungsprobleme',
        'web_vitals' => 'Ladeerlebnis',
        'profiling' => 'Profile',
    ],

    'menu_items' => [
        'profile' => 'Profil',
        'operations' => 'Betrieb',
        'components' => 'Bausteine',
    ],

    'theme' => [
        'light' => 'Helles Design',
        'dark' => 'Dunkles Design',
        'system' => 'Design des Systems',
    ],

];
