<?php

// Schwellwert-Alarme auf Kennzahlen (app/Http/Controllers/MetricAlertController,
// app/Support/Alerts, resources/js/shell/pages/projects/Alerts.jsx).
return [

    'title' => 'Alarme — :project',
    'help' => 'Ein Alarm schaut regelmäßig auf eine Kennzahl und meldet sich, wenn '
        .'sie eine Schwelle verletzt — und noch einmal, wenn sie sich wieder '
        .'normalisiert hat. Gerechnet wird über ein Zeitfenster, das jede Minute '
        .'weiterwandert. Gemeldet wird nur der Wechsel des Zustands, nicht jede '
        .'Auswertung: eine anhaltende Störung ergibt eine Meldung und nicht '
        .'sechzig in der Stunde.',

    'intro' => [
        'title' => 'Wie ein Alarm funktioniert',
        'description' => 'Warnschwelle und kritische Schwelle bestimmen, wann der Alarm '
            .'auslöst. Die Auflösungsschwelle bestimmt, wann er wieder aufgeht — sie liegt '
            .'bewusst jenseits der auslösenden, damit ein Wert, der um die Grenze pendelt, '
            .'nicht abwechselnd Alarm und Entwarnung schickt.',
        'gaps_hint' => 'Kommen im Zeitfenster keine Messungen an, bleibt der Zustand stehen: '
            .'aus null Messungen folgt keine Antwortzeit und keine Fehlerquote. Bei Anzahlen '
            .'ist das anders — dort heißt „nichts" tatsächlich null, und der Alarm gibt '
            .'Entwarnung.',
    ],

    'list' => [
        'empty' => 'Noch kein Alarm eingerichtet.',
        'inactive_badge' => 'abgeschaltet',
        'subtitle' => ':metric über :minutes Minuten',
        'last_value' => 'Zuletzt gemessen',
        'no_value' => 'kein Wert',
        'last_evaluated' => 'Zuletzt ausgewertet',
        'never_evaluated' => 'noch nie',
        'status_since' => 'Zustand seit',
    ],

    'create' => [
        'title' => 'Neuen Alarm anlegen',
        'description' => 'Mindestens eine der beiden auslösenden Schwellen ist nötig — '
            .'ein Alarm ohne Schwelle rechnet jede Minute und sagt nie etwas.',
    ],

    'fields' => [
        'name' => 'Name',
        'metric' => 'Kennzahl',
        'direction' => 'Richtung',
        'comparison' => 'Vergleich',
        'window' => 'Zeitfenster (Minuten, :min–:max)',
        'environment' => 'Umgebung',
        'all_environments' => 'Alle Umgebungen',
        'transaction' => 'Vorgang',
        'all_transactions' => 'Alle Vorgänge',
        'warning' => 'Warnschwelle',
        'critical' => 'Kritische Schwelle',
        'resolve' => 'Auflösungsschwelle',
        'minimum_samples' => 'Mindestzahl Messungen',
        'percent_change_hint' => 'Die Schwellen gelten als Veränderung in Prozent gegenüber '
            .'demselben Zeitfenster vor einer Woche.',
    ],

    'thresholds' => [
        'warning' => 'Warnung',
        'critical' => 'Kritisch',
        'resolve' => 'Auflösung',
    ],

    'actions' => [
        'create' => 'Alarm anlegen',
        'save' => 'Speichern',
        'preview' => 'Vorschau anzeigen',
        'previewing' => 'Wird berechnet …',
        'enable' => 'Einschalten',
        'disable' => 'Abschalten',
        'delete' => 'Löschen',
    ],

    'preview' => [
        'caption' => 'Die letzten :windows Zeitfenster à :minutes Minuten, mit den Schwellen darüber.',
        'label' => 'Verlauf der Kennzahl, Fenster von :minutes Minuten',
        'empty' => 'Für den gezeigten Zeitraum liegen keine Werte vor.',
    ],

    'history' => [
        'title' => 'Verlauf',
        'description' => 'Die letzten Zustandswechsel aller Alarme dieses Projekts.',
        'empty' => 'Noch kein Zustandswechsel aufgetreten.',
    ],

    // Die Art des Übergangs (App\Models\MetricAlertTransition::kind()).
    'kind' => [
        'fired' => 'Ausgelöst',
        'escalated' => 'Verschärft',
        'eased' => 'Entspannt',
        'resolved' => 'Entwarnung',
    ],

    'notification' => [
        'fired_title' => 'Alarm: :alert',
        'fired_body' => 'Der Alarm „:alert" im Projekt :project hat ausgelöst. :metric liegt '
            .'in den letzten :minutes Minuten bei :value, die Schwelle steht bei :threshold.',

        'escalated_title' => 'Alarm verschärft: :alert',
        'escalated_body' => 'Der Alarm „:alert" im Projekt :project hat sich verschärft. '
            .':metric liegt in den letzten :minutes Minuten bei :value, die Schwelle steht '
            .'bei :threshold.',

        'eased_title' => 'Alarm entspannt: :alert',
        'eased_body' => 'Der Alarm „:alert" im Projekt :project hat sich entspannt, ist aber '
            .'noch nicht aufgelöst. :metric liegt in den letzten :minutes Minuten bei :value.',

        'resolved_title' => 'Entwarnung: :alert',
        'resolved_body' => 'Der Alarm „:alert" im Projekt :project ist aufgelöst. :metric '
            .'liegt in den letzten :minutes Minuten wieder bei :value.',

        'no_threshold' => 'keiner',
        'minutes' => ':minutes Minuten',
        'context_project' => 'Projekt',
        'context_metric' => 'Kennzahl',
        'context_window' => 'Zeitfenster',
        'context_value' => 'Wert',
        'context_status' => 'Zustand',
        'context_environment' => 'Umgebung',
        'context_transaction' => 'Vorgang',
        'context_baseline' => 'Vorwoche',
    ],

    'flash' => [
        'created' => 'Alarm „:name" angelegt.',
        'updated' => 'Alarm „:name" gespeichert.',
        'enabled' => 'Alarm „:name" eingeschaltet.',
        'disabled' => 'Alarm „:name" abgeschaltet.',
        'deleted' => 'Alarm „:name" gelöscht.',
    ],

    'validation' => [
        'threshold_required' => 'Mindestens eine der beiden Schwellen muss gesetzt sein.',
        'critical_above' => 'Die kritische Schwelle muss über der Warnschwelle liegen.',
        'critical_below' => 'Die kritische Schwelle muss unter der Warnschwelle liegen.',
        'resolve_below' => 'Die Auflösungsschwelle muss unter der auslösenden Schwelle liegen.',
        'resolve_above' => 'Die Auflösungsschwelle muss über der auslösenden Schwelle liegen.',
        'transaction_not_supported' => 'Diese Kennzahl kennt keinen Vorgang — eine '
            .'Fehlermeldung trägt keinen Vorgangsnamen.',
        'too_many' => 'Ein Projekt kann höchstens :max Alarme haben.',
    ],

];
