<?php

// Beschriftungen der Aufzählungen (app/Enums). Ein Schlüssel je Fall, benannt
// nach dem gespeicherten Wert — so bleibt der Bezug auch im Export lesbar.
return [

    'grouping_source' => [
        'rule' => 'Projektweite Regel',
        'custom' => 'Eigene Angabe des SDK',
        'stacktrace' => 'Stacktrace',
        'exception' => 'Ausnahme',
        'message' => 'Meldungstext',
        'fallback' => 'Titel und Fehlerstelle',
        'empty' => 'Ohne unterscheidbaren Inhalt',
    ],

    'api_scope' => [
        'org:read' => 'Organisation lesen',
        'org:write' => 'Organisation ändern',
        'member:read' => 'Mitglieder lesen',
        'member:write' => 'Mitglieder verwalten',
        'team:read' => 'Teams lesen',
        'team:write' => 'Teams verwalten',
        'project:read' => 'Projekte lesen',
        'project:write' => 'Projekte ändern',
        'project:admin' => 'Projekte verwalten',
        'event:read' => 'Ereignisse lesen',
        'event:write' => 'Ereignisse einliefern',
        'issue:read' => 'Fehler lesen',
        'issue:write' => 'Fehler bearbeiten',
        'alerts:read' => 'Alarme lesen',
        'alerts:write' => 'Alarme verwalten',
    ],

    'api_scope_group' => [
        'org' => 'Organisation',
        'member' => 'Mitglieder',
        'team' => 'Teams',
        'project' => 'Projekte',
        'event' => 'Ereignisse',
        'issue' => 'Fehler',
        'alerts' => 'Alarme',
        'other' => 'Weitere',
    ],

    'api_token_kind' => [
        'personal' => 'Persönlich',
        'organization' => 'Organisationsweit',
    ],

    'api_token_kind_description' => [
        'personal' => 'Handelt in deinem Namen und endet mit deiner Mitgliedschaft.',
        'organization' => 'Gehört der Organisation und gilt unabhängig von einzelnen Konten.',
    ],

    'audit_action' => [
        'organization_created' => 'Organisation angelegt',
        'organization_updated' => 'Organisation geändert',
        'invitation_sent' => 'Einladung verschickt',
        'invitation_role_changed' => 'Rolle einer Einladung geändert',
        'invitation_revoked' => 'Einladung zurückgezogen',
        'invitation_accepted' => 'Einladung angenommen',
        'membership_role_changed' => 'Rolle geändert',
        'membership_removed' => 'Mitglied entfernt',
        'membership_left' => 'Organisation verlassen',
        'team_created' => 'Team angelegt',
        'team_updated' => 'Team geändert',
        'team_deleted' => 'Team gelöscht',
        'team_member_added' => 'Mitglied zu Team hinzugefügt',
        'team_member_removed' => 'Mitglied aus Team entfernt',
    ],

    'cron_schedule_type' => [
        'crontab' => 'Cron-Ausdruck',
        'interval' => 'Abstand',
    ],

    'cron_interval_unit' => [
        'minute' => 'Minuten',
        'hour' => 'Stunden',
        'day' => 'Tage',
        'week' => 'Wochen',
        'month' => 'Monate',
        'year' => 'Jahre',
    ],

    'cron_check_in_status' => [
        'in_progress' => 'läuft',
        'ok' => 'durchgelaufen',
        'error' => 'gescheitert',
        'missed' => 'verpasst',
        'timeout' => 'zu lange gelaufen',
    ],

    'cron_monitor_status' => [
        'unknown' => 'noch keine Ausführung',
        'ok' => 'in Ordnung',
        'running' => 'läuft',
        'missed' => 'verpasst',
        'timeout' => 'zu lange gelaufen',
        'error' => 'gescheitert',
        'disabled' => 'abgeschaltet',
    ],

    'delivery_status' => [
        'pending' => 'unterwegs',
        'sent' => 'zugestellt',
        'failed' => 'fehlgeschlagen',
    ],

    'discard_origin' => [
        'server' => 'vom Server verworfen',
        'client' => 'vom SDK verworfen',
    ],

    'discard_reason' => [
        'unknown_type' => 'unbekannter Typ',
        'unreadable' => 'nicht lesbar',
        'too_large' => 'zu groß',
        'too_many_items' => 'zu viele Elemente',
        'duplicate' => 'doppelte Zustellung',
        'scrubbed' => 'aus Datenschutzgründen nicht gespeichert',
        'filtered' => 'vom Eingangsfilter aussortiert',
    ],

    'inbound_filter_kind' => [
        'browser_extension' => 'Browser-Erweiterungen',
        'legacy_browser' => 'Veraltete Browser',
        'localhost' => 'Lokale Entwicklung',
        'crawler' => 'Web-Crawler',
        'message_pattern' => 'Fehlermeldungen nach Muster',
        'ip_address' => 'Absender-Sperrliste',
        'release' => 'Release-Sperrliste',
    ],

    'event_level' => [
        'fatal' => 'Kritisch',
        'error' => 'Fehler',
        'warning' => 'Warnung',
        'info' => 'Hinweis',
        'debug' => 'Debug',
    ],

    'filter_period' => [
        '1h' => 'Letzte Stunde',
        '24h' => 'Letzte 24 Stunden',
        '7d' => 'Letzte 7 Tage',
        '14d' => 'Letzte 14 Tage',
        '30d' => 'Letzte 30 Tage',
        '90d' => 'Letzte 90 Tage',
        'custom' => 'Eigener Zeitraum',
    ],

    'ingest_type' => [
        'event' => 'Fehlermeldung',
        'transaction' => 'Transaktion',
        'session' => 'Sitzung',
        'sessions' => 'Sitzungen',
        'attachment' => 'Anhang',
        'check_in' => 'Cronjob-Lebenszeichen',
        'replay_event' => 'Aufzeichnung (Kopfdaten)',
        'replay_recording' => 'Aufzeichnung (Daten)',
        'profile' => 'Laufzeitmessung',
        'client_report' => 'Verworfen-Meldung des SDK',
        'user_report' => 'Nutzer-Rückmeldung',
    ],

    'processing_state' => [
        'pending' => 'wartet auf Auswertung',
        'processed' => 'ausgewertet',
        'duplicate' => 'doppelt',
        'dropped' => 'aussortiert',
        'failed' => 'fehlgeschlagen',
    ],

    'notification_event' => [
        'alert' => 'Alarme',
        'assignment' => 'Zuweisungen',
        'mention' => 'Erwähnungen',
        'workflow_change' => 'Workflow-Änderungen',
        'deploy' => 'Deploys',
        'weekly_digest' => 'Wochenbericht',
        'quota_warning' => 'Kontingent-Warnungen',
    ],

    'notification_event_description' => [
        'alert' => 'Ein Alarm hat ausgelöst — etwas ist kaputt.',
        'assignment' => 'Ein Fehler wurde dir zugewiesen.',
        'mention' => 'Jemand hat dich in einem Kommentar genannt.',
        'workflow_change' => 'Ein Fehler wurde erledigt, ignoriert oder wieder geöffnet.',
        'deploy' => 'Eine neue Version ist ausgeliefert worden.',
        'weekly_digest' => 'Die wöchentliche Zusammenfassung deiner Projekte.',
        'quota_warning' => 'Das Aufnahme-Kontingent geht zur Neige.',
    ],

    'notification_level' => [
        'info' => 'Information',
        'warning' => 'Warnung',
        'error' => 'Fehler',
    ],

    'notification_transport' => [
        'mail' => 'E-Mail',
        'in_app' => 'In der Anwendung',
    ],

    'notification_transport_description' => [
        'mail' => 'An die E-Mail-Adresse dieses Kontos.',
        'in_app' => 'Im Postfach innerhalb von Errstack.',
    ],

    'organization_role' => [
        'owner' => 'Besitzer',
        'admin' => 'Verwaltung',
        'member' => 'Mitglied',
        'viewer' => 'Lesend',
    ],

    'platform' => [
        'php' => 'PHP',
        'javascript' => 'JavaScript',
        'python' => 'Python',
        'node' => 'Node.js',
        'java' => 'Java',
        'go' => 'Go',
        'ruby' => 'Ruby',
        'dotnet' => '.NET',
        'other' => 'Sonstige',
    ],

    'scrub_rule_type' => [
        'field' => 'Feldname',
        'pattern' => 'Muster im Wert',
    ],

    'resolution_behavior' => [
        'manual' => 'Nur von Hand auflösen',
        'after_week' => 'Nach 7 Tagen ohne neues Auftreten',
        'after_month' => 'Nach 30 Tagen ohne neues Auftreten',
    ],

];
