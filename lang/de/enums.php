<?php

// Beschriftungen der Aufzählungen (app/Enums). Ein Schlüssel je Fall, benannt
// nach dem gespeicherten Wert — so bleibt der Bezug auch im Export lesbar.
return [

    'alert_status' => [
        'ok' => 'In Ordnung',
        'warning' => 'Warnung',
        'critical' => 'Kritisch',
    ],

    'alert_direction' => [
        'above' => 'Überschreitet die Schwelle',
        'below' => 'Unterschreitet die Schwelle',
    ],

    'alert_comparison' => [
        'absolute' => 'Wert selbst',
        'percent_change_week' => 'Veränderung gegenüber der Vorwoche',
    ],

    'alert_metric' => [
        'error_count' => 'Fehlermeldungen',
        'transaction_throughput' => 'Durchsatz (Aufrufe)',
        'transaction_failure_rate' => 'Fehlerquote der Aufrufe',
        'transaction_duration_avg' => 'Antwortzeit (Mittelwert)',
        'transaction_duration_p50' => 'Antwortzeit (p50)',
        'transaction_duration_p95' => 'Antwortzeit (p95)',
        'transaction_duration_p99' => 'Antwortzeit (p99)',
    ],

    'grouping_source' => [
        'rule' => 'Projektweite Regel',
        'custom' => 'Eigene Angabe des SDK',
        'stacktrace' => 'Stacktrace',
        'exception' => 'Ausnahme',
        'message' => 'Meldungstext',
        'fallback' => 'Titel und Fehlerstelle',
        'empty' => 'Ohne unterscheidbaren Inhalt',
        'performance' => 'Leistungserkennung',
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
        'sampled' => 'nicht in die Stichprobe gefallen',
        'scrubbed' => 'aus Datenschutzgründen nicht gespeichert',
        'filtered' => 'vom Eingangsfilter aussortiert',
        'discarded' => 'gelöschter Fehler, künftig verworfen',
        'orphaned' => 'ohne zugehörige Meldung',
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

    'security_report_type' => [
        'csp' => 'Verstoß gegen die Sicherheitsrichtlinie',
        'expect-ct' => 'Certificate Transparency verletzt',
        'expect-staple' => 'OCSP-Stapling fehlgeschlagen',
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

    'issue_status' => [
        'unresolved' => 'Offen',
        'resolved' => 'Erledigt',
        'ignored' => 'Stummgeschaltet',
    ],

    'issue_priority' => [
        'high' => 'Hoch',
        'medium' => 'Mittel',
        'low' => 'Niedrig',
    ],

    'issue_resolve_mode' => [
        'now' => 'Sofort',
        'current_release' => 'In dieser Version',
        'next_release' => 'Mit der nächsten Auslieferung',
    ],

    'issue_ignore_mode' => [
        'forever' => 'Dauerhaft',
        'until_recurrence' => 'Bis er wieder auftritt',
        'until_count' => 'Bis zu einer Anzahl Ereignisse',
        'until_users' => 'Bis zu einer Anzahl Betroffener',
    ],

    'issue_activity' => [
        'resolved' => 'Erledigt',
        'unresolved' => 'Wieder geöffnet',
        'ignored' => 'Stummgeschaltet',
        'ignore_expired' => 'Stummschaltung beendet',
        'bookmarked' => 'Gemerkt',
        'unbookmarked' => 'Vormerkung aufgehoben',
        'subscribed' => 'Abonniert',
        'unsubscribed' => 'Abbestellt',
        'discarded' => 'Gelöscht und verworfen',
        'deleted' => 'Gelöscht',
    ],

    'count_period' => [
        'hour' => 'Stündlich',
        'day' => 'Täglich',
    ],

    'trend_direction' => [
        'new' => 'Neu — im Vorzeitraum nicht gemessen',
        'unknown' => 'Zu wenige Messungen für einen Vergleich',
        'flat' => 'Unverändert',
        'better' => 'Schneller geworden',
        'worse' => 'Langsamer geworden',
    ],

    'issue_category' => [
        'error' => 'Fehler',
        'performance' => 'Leistungsproblem',
    ],

    'performance_problem' => [
        'n_plus_one_queries' => 'N+1-Abfragen',
        'consecutive_queries' => 'Aufeinanderfolgende gleichartige Abfragen',
        'duplicate_queries' => 'Doppelte Abfragen',
        'slow_http_call' => 'Langsamer HTTP-Aufruf',
        'oversized_asset' => 'Übergroße oder unkomprimierte Datei',
        'render_blocking_asset' => 'Render-blockierende Ressource',
        'main_thread_block' => 'Hauptthread-Blockade',
        'cache_misses' => 'Cache-Fehlgriffe',
    ],

    'performance_problem_description' => [
        'n_plus_one_queries' => 'Eine Abfrage holt eine Liste, danach wird für jeden '
            .'Eintrag einzeln nachgefragt. Ein Join oder ein Vorabladen ersetzt die '
            .'ganze Serie durch eine Abfrage.',
        'consecutive_queries' => 'Dieselbe Abfrageform läuft mehrfach nacheinander, '
            .'jede wartet auf die vorige. Gebündelt oder nebenläufig kostet sie nur '
            .'einmal Wartezeit.',
        'duplicate_queries' => 'Dieselbe Abfrage mit denselben Werten läuft mehrfach '
            .'in einem Ablauf. Jede Wiederholung nach der ersten liefert dieselbe '
            .'Antwort und ist ersatzlos zu streichen.',
        'slow_http_call' => 'Ein Aufruf an einen fremden Dienst dauert länger als die '
            .'eingestellte Schwelle.',
        'oversized_asset' => 'Eine Datei ist sehr groß oder wird unkomprimiert '
            .'ausgeliefert — erkennbar daran, dass übertragene und entpackte Größe '
            .'übereinstimmen.',
        'render_blocking_asset' => 'Ein Skript oder Stylesheet hält den Browser auf, '
            .'bevor er überhaupt etwas anzeigen kann.',
        'main_thread_block' => 'Ein Stück Arbeit beschäftigt den Browser so lange, '
            .'dass er in der Zeit auf keine Eingabe reagiert.',
        'cache_misses' => 'Nachschläge im Zwischenspeicher gehen wiederholt ins Leere '
            .'— meist ein falsch gebauter Schlüssel oder ein Eintrag, der nie '
            .'geschrieben wird.',
    ],

    'performance_threshold' => [
        'min_count' => 'Mindestanzahl',
        'min_total_ms' => 'Mindestsumme in ms',
        'min_duration_ms' => 'Mindestdauer in ms',
        'min_size_kb' => 'Mindestgröße in KB',
    ],

    'commit_file_change' => [
        'A' => 'Hinzugefügt',
        'M' => 'Geändert',
        'D' => 'Gelöscht',
    ],

];
