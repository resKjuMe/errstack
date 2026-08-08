<?php

// Alarm-Regeln für Fehler (app/Http/Controllers/IssueAlertRuleController,
// app/Support/IssueAlerts, resources/js/shell/pages/projects/IssueAlerts.jsx).
return [

    'title' => 'Alarm-Regeln — :project',
    'help' => 'Eine Regel besteht aus drei Teilen: einem Anlass („neuer Fehler"), '
        .'beliebig vielen Einschränkungen („nur Grad error, nur Produktion") und '
        .'einer Aktion („an Slack"). Geprüft wird sie unmittelbar nach der '
        .'Verarbeitung jeder eingehenden Meldung. Die Häufigkeitsbegrenzung sorgt '
        .'dafür, dass derselbe Fehler höchstens einmal je Zeitfenster meldet — '
        .'sonst wäre ein Ausfall ein Postfach voll derselben Nachricht.',

    'intro' => [
        'title' => 'Wie eine Regel funktioniert',
        'description' => 'Der Anlass entscheidet, wann überhaupt hingesehen wird; die '
            .'Einschränkungen nehmen davon wieder weg. Ohne Einschränkung greift die Regel '
            .'für jeden Fehler, auf den der Anlass zutrifft.',
        'pending_hint' => 'Zuweisung und Ticket-Erstellung als Aktion kommen mit der '
            .'Zuständigkeit und den Ticket-Anbindungen; bis dahin stehen die '
            .'Benachrichtigungswege der Organisation und die persönlichen '
            .'Benachrichtigungen zur Verfügung.',
    ],

    'list' => [
        'empty' => 'Noch keine Regel eingerichtet.',
        'inactive_badge' => 'abgeschaltet',
        'subtitle' => 'höchstens eine Meldung je Fehler in :minutes Minuten',
        'conditions' => 'Anlass',
        'filters' => 'Einschränkungen',
        'no_filters' => 'keine',
        'actions' => 'Aktionen',
        'triggers' => 'Auslösungen',
    ],

    'create' => [
        'title' => 'Neue Regel anlegen',
        'description' => 'Mindestens ein Anlass und mindestens eine Aktion sind nötig — '
            .'eine Regel ohne beides stünde da und täte nichts.',
    ],

    'fields' => [
        'name' => 'Name',
        'condition_match' => 'Anlass trifft zu, wenn',
        'filter_match' => 'Einschränkung trifft zu, wenn',
        'frequency' => 'Höchstens eine Meldung je Fehler alle (Minuten, :min–:max)',
        'value' => 'Anzahl',
        'window_minutes' => 'Zeitfenster (Minuten)',
        'window_hours' => 'Zeitfenster (Stunden)',
        'comparison' => 'Vergleich',
        'filter_value' => 'Wert',
        'tag_key' => 'Merkmal',
        'channel' => 'Kanal',
        'all_channels' => 'Alle aktiven Kanäle',
        'channel_inactive' => '(abgeschaltet)',
        'add_condition' => 'Anlass hinzufügen',
        'add_filter' => 'Einschränkung hinzufügen',
        'add_action' => 'Aktion hinzufügen',
        'remove' => 'Entfernen',
    ],

    'actions' => [
        'create' => 'Regel anlegen',
        'save' => 'Speichern',
        'preview' => 'Vorschau',
        'previewing' => 'Wird geprüft …',
        'enable' => 'Einschalten',
        'disable' => 'Abschalten',
        'delete' => 'Löschen',
    ],

    'preview' => [
        'caption' => 'Fehler der letzten :days Tage, auf die diese Regel derzeit zuträfe.',
        'empty' => 'Kein Fehler der letzten :days Tage träfe diese Regel.',
        'summary' => ':matched von :scanned geprüften Fehlern.',
        'truncated' => 'Es werden nur die ersten :limit gezeigt.',
        'reasons' => 'Anlass:',
        'note' => 'Die Vorschau spielt den Verlauf nicht nach, sondern prüft den heutigen '
            .'Stand: „neuer Fehler" heißt hier „in den letzten :days Tagen zum ersten Mal '
            .'aufgetreten". Die Häufigkeitsbegrenzung bleibt außen vor.',
    ],

    'history' => [
        'title' => 'Alarm-Verlauf',
        'description' => 'Was wann gefeuert hat — über alle Regeln dieses Projekts.',
        'empty' => 'Noch keine Auslösung.',
        'deliveries' => ':count Zustellungen',
        'no_deliveries' => 'keine Zustellung — kein aktiver Kanal',
    ],

    'notification' => [
        'title' => ':rule — :project',
        'body' => ':title (:reason)',
        'untitled' => 'Fehler ohne Titel',
        'context_project' => 'Projekt',
        'context_rule' => 'Regel',
        'context_reason' => 'Anlass',
        'context_level' => 'Grad',
        'context_times_seen' => 'Bisher gesehen',
        'context_environment' => 'Umgebung',
        'context_release' => 'Fassung',
    ],

    'flash' => [
        'created' => 'Regel „:name" angelegt.',
        'updated' => 'Regel „:name" gespeichert.',
        'enabled' => 'Regel „:name" eingeschaltet.',
        'disabled' => 'Regel „:name" abgeschaltet.',
        'deleted' => 'Regel „:name" gelöscht.',
    ],

    'validation' => [
        'too_many' => 'Mehr als :max Regeln je Projekt sind nicht möglich.',
        'value_required' => 'Für diesen Anlass ist eine Anzahl größer als null nötig.',
        'window_range' => 'Das Zeitfenster muss zwischen 1 und :max liegen.',
        'comparison_invalid' => 'Dieser Vergleich passt nicht zu dieser Einschränkung.',
        'key_required' => 'Für diese Einschränkung ist ein Merkmal nötig.',
        'filter_value_required' => 'Für diese Einschränkung ist ein Wert nötig.',
        'filter_value_numeric' => 'Für diese Einschränkung ist eine Zahl nötig.',
        'channel_unknown' => 'Dieser Kanal gehört nicht zu dieser Organisation.',
    ],

];
