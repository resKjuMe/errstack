<?php

// Überwachte Cronjobs (resources/js/shell/pages/projects/Crons.jsx,
// App\Http\Controllers\CronMonitorController und die Alarmtexte in
// App\Support\Crons\CronAlerts).
return [

    'title' => 'Cronjobs · :project',
    'help' => 'Ein überwachter Job meldet sich bei jedem Lauf. Bleibt die Meldung aus, läuft der Job zu lange oder scheitert er, kommt automatisch eine Nachricht — über dieselben Kanäle wie jeder andere Alarm. Die Kennung unten gehört in den Job; ändern lässt sie sich später nicht, damit die Meldungen weiter ankommen.',

    'name' => 'Name',
    'name_hint' => 'Nur zur Anzeige. Die Kennung für den Job entsteht daraus beim Anlegen und bleibt danach fest.',

    'schedule_type' => 'Art des Zeitplans',
    'expression' => 'Cron-Ausdruck',
    'expression_hint' => 'Fünf Felder wie in einer Crontab: Minute, Stunde, Tag, Monat, Wochentag. „0 2 * * *" heißt täglich um 02:00.',
    'interval_value' => 'Alle',
    'interval_unit' => 'Einheit',

    'timezone' => 'Zeitzone',
    'timezone_hint' => 'Die Zeitzone, in der der Job läuft — nicht die des Betrachters. Ohne sie ist „täglich 02:00" keine Angabe.',

    'margin' => 'Toleranz (Minuten)',
    'margin_hint' => 'So lange nach der geplanten Zeit gilt der Job noch als pünktlich. Danach gilt der Lauf als verpasst.',
    'max_runtime' => 'Maximale Laufzeit (Minuten)',
    'max_runtime_hint' => 'Meldet der Job nach dieser Zeit kein Ende, gilt der Lauf als hängen geblieben.',
    'failure_tolerance' => 'Alarm nach … Fehlschlägen',
    'failure_tolerance_hint' => 'Erst nach so vielen Fehlschlägen in Folge geht eine Meldung raus. 1 heißt: sofort.',
    'recovery_tolerance' => 'Entwarnung nach … Erfolgen',
    'recovery_tolerance_hint' => 'So viele erfolgreiche Läufe in Folge, bevor Entwarnung gegeben wird.',

    'active' => 'Überwachung aktiv',
    'save' => 'Speichern',
    'disable' => 'Überwachung abschalten',
    'enable' => 'Überwachung einschalten',
    'delete' => 'Löschen',
    'disable_hint' => 'Abgeschaltet bleibt alles stehen, es wird nur nichts mehr festgestellt — der Weg für eine geplante Wartung.',

    'copy' => 'Kopieren',
    'copied' => 'Kopiert',
    'check_in_url_hint' => 'Diese Adresse am Ende des Jobs aufrufen — das genügt als Lebenszeichen. Mit „?status=in_progress" zu Beginn und „?status=error" im Fehlerfall wird zusätzlich die Laufzeit erfasst.',

    'schedule' => [
        'every' => 'alle :value :unit',
        'invalid' => 'Der Zeitplan lässt sich nicht lesen — bitte korrigieren.',
    ],

    'facts' => [
        'slug' => 'Kennung',
        'last_check_in' => 'Letzte Meldung',
        'next_due' => 'Nächster Lauf',
        'never' => 'noch nie',
        'failures' => 'Fehlschläge in Folge',
        'failures_value' => ':count von :tolerance',
    ],

    'history' => [
        'title' => 'Verlauf (:count)',
        'empty' => 'Noch keine Ausführung aufgezeichnet.',
        'status' => 'Ergebnis',
        'expected' => 'Geplant',
        'started' => 'Begonnen',
        'duration' => 'Dauer',
        'environment' => 'Umgebung',
    ],

    'empty' => [
        'title' => 'Noch kein überwachter Cronjob',
        'description' => 'Lege einen an — oder schicke einen Check-in mit „monitor_config": dann entsteht die Überwachung beim ersten Lauf von selbst.',
    ],

    'create' => [
        'title' => 'Cronjob überwachen',
        'description' => 'Zeitplan und Toleranz bestimmen, ab wann ein ausgebliebener Lauf auffällt.',
        'submit' => 'Überwachung anlegen',
    ],

    'validation' => [
        'expression' => 'Das ist kein gültiger Cron-Ausdruck.',
    ],

    'flash' => [
        'created' => 'Cronjob „:name" wird überwacht — Kennung für den Check-in: :slug',
        'updated' => 'Cronjob „:name" gespeichert.',
        'enabled' => 'Überwachung von „:name" ist wieder aktiv.',
        'disabled' => 'Überwachung von „:name" ist abgeschaltet.',
        'deleted' => 'Überwachung von „:name" gelöscht.',
    ],

    // Alarm- und Entwarnungstexte (App\Support\Crons\CronAlerts). Sie gehen
    // über die Kanäle der Organisation raus und werden auch außerhalb der
    // Oberfläche gelesen — deshalb nennen sie Projekt und Job beim Namen.
    'alert' => [
        'title' => 'Cronjob „:monitor": :reason',
        'body_missed' => 'Der Job „:monitor" im Projekt „:project" hat sich nicht gemeldet. Erwartet war der Lauf um :expected.',
        'body_timeout' => 'Der Job „:monitor" im Projekt „:project" läuft seit mehr als :runtime Minuten und hat kein Ende gemeldet. Erwartet war der Lauf um :expected.',
        'body_error' => 'Der Job „:monitor" im Projekt „:project" hat sich als gescheitert gemeldet. Erwartet war der Lauf um :expected.',
        'unknown_time' => 'unbekannt',
        'unknown_schedule' => 'nicht lesbar',
        'context_project' => 'Projekt',
        'context_monitor' => 'Kennung',
        'context_schedule' => 'Zeitplan',
        'context_environment' => 'Umgebung',
        'context_duration' => 'Dauer',
    ],

    'recovery' => [
        'title' => 'Cronjob „:monitor" läuft wieder',
        'body' => 'Der Job „:monitor" im Projekt „:project" ist wieder durchgelaufen.',
    ],

];
