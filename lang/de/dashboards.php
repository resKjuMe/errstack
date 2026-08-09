<?php

return [

    'title' => 'Dashboards',
    'help' => 'Ein Dashboard ist eine Sammlung von Fragen, kein Abzug von Zahlen: jede Kachel speichert ihre Abfrage und rechnet beim Aufschlagen neu. Zeitraum, Umgebung und Projekt kommen aus der Filterleiste oben — einzelne Kacheln dürfen davon abweichen und sagen es dann auch.',

    'list' => [
        'empty' => 'Noch kein Dashboard. Beginnen Sie mit einer Vorlage oder legen Sie ein leeres an.',
        'widgets' => ':count Kacheln',
        'widgets_one' => 'eine Kachel',
        'shared' => 'freigegeben',
        'owner' => 'von :name',
        'updated' => 'geändert :at',
        'open' => 'Öffnen',
    ],

    'create' => [
        'title' => 'Neues Dashboard',
        'name' => 'Name',
        'description' => 'Beschreibung',
        'template' => 'Vorlage',
        'template_none' => 'Leer — Kacheln selbst zusammenstellen',
        'shared' => 'Für die ganze Organisation freigeben',
        'submit' => 'Anlegen',
    ],

    'settings' => [
        'title' => 'Dashboard',
        'submit' => 'Speichern',
        'delete' => 'Löschen',
        'delete_confirm' => 'Dieses Dashboard samt seiner Kacheln löschen?',
        'duplicate' => 'Duplizieren',
        'shared_hint' => 'Freigegeben heißt sehen, nicht ändern: ändern darf weiterhin nur, wer es angelegt hat.',
        'readonly' => 'Dieses Dashboard gehört :name. Sie können es ansehen und duplizieren, aber nicht ändern.',
    ],

    'grid' => [
        'empty' => 'Noch keine Kachel. Fügen Sie die erste hinzu.',
        'add' => 'Kachel hinzufügen',
        'full' => 'Dieses Dashboard hat die größtmögliche Zahl an Kacheln (:limit).',
        'move' => 'Kachel verschieben',
        'resize' => 'Größe ändern',
        'keyboard_hint' => 'Mit den Pfeiltasten verschieben, mit Umschalt + Pfeiltasten die Größe ändern.',
    ],

    'widget' => [
        'add' => 'Kachel hinzufügen',
        'edit' => 'Kachel bearbeiten',
        'delete' => 'Kachel löschen',
        'delete_confirm' => 'Diese Kachel löschen?',
        'submit' => 'Speichern',
        'cancel' => 'Abbrechen',
        'title' => 'Überschrift',
        'type' => 'Darstellung',
        'dataset' => 'Datenquelle',
        'fields' => 'Gruppierung',
        'field_none' => '— kein Feld —',
        'metrics' => 'Kennzahlen',
        'metric_field' => 'Feld',
        'search' => 'Bedingung',
        'search_placeholder' => 'z. B. level:error',
        'sort' => 'Sortierung',
        'sort_placeholder' => 'z. B. -count',
        'limit' => 'Zeilen',
        'interval' => 'Schrittweite',
        'interval_auto' => 'automatisch (zum Zeitraum passend)',
        'explore' => 'In der Auswertung öffnen',

        'overrides' => [
            'title' => 'Eigene Sicht dieser Kachel',
            'hint' => 'Leer heißt: die Filterleiste oben gilt.',
            'period' => 'Zeitraum',
            'period_inherit' => '— wie die Filterleiste —',
            'from' => 'Von',
            'to' => 'Bis',
            'environment' => 'Umgebung',
            'environment_inherit' => '— wie die Filterleiste —',
            'project' => 'Projekt',
            'project_inherit' => '— wie die Filterleiste —',
        ],

        'loading' => 'wird geladen …',
        'empty' => 'Keine Daten im gewählten Zeitraum.',
        'cached' => 'aus dem Zwischenspeicher',
        'truncated' => 'gekürzt auf :limit Zeilen',
        'scope' => 'eigener Ausschnitt: :range',

        'error' => [
            'title' => 'Nicht gerechnet',
            'project_required' => 'Diese Kachel braucht genau ein Projekt. Wählen Sie oben eines aus oder legen Sie an der Kachel eines fest.',
            'project_missing' => 'Das Projekt „:project" dieser Kachel gibt es nicht mehr oder Sie können es nicht sehen.',
            'retry' => 'Erneut versuchen',
            'failed' => 'Die Zahlen dieser Kachel konnten nicht geladen werden.',
        ],

        'map' => [
            'missing_field' => 'Eine Weltkarte braucht eine Gruppierung nach Land (:field bei den Fehlermeldungen, country bei den Antwortzeiten).',
            'unknown' => 'ohne Land',
            'countries' => ':count Länder',
        ],

        'number' => [
            'no_value' => 'kein Wert',
        ],
    ],

    'templates' => [
        'errors' => [
            'name' => 'Fehlerübersicht',
            'description' => 'Wie viele Fehler, welche, wen sie treffen und womit.',
            'widgets' => [
                'volume' => 'Fehler im Verlauf',
                'total' => 'Fehler gesamt',
                'users' => 'Betroffene',
                'top' => 'Häufigste Fehler',
                'browsers' => 'Nach Browser',
            ],
        ],
        'performance' => [
            'name' => 'Performance',
            'description' => 'Antwortzeiten, Zufriedenheit und die langsamsten Aufrufe.',
            'widgets' => [
                'p95' => 'Antwortzeit p95',
                'apdex' => 'Zufriedenheit',
                'failure_rate' => 'Fehlerquote',
                'slowest' => 'Langsamste Aufrufe',
                'countries' => 'Antwortzeit nach Land',
            ],
        ],
        'release_health' => [
            'name' => 'Release-Gesundheit',
            'description' => 'Was die neue Fassung mitbringt — Fehler je Version.',
            'widgets' => [
                'by_release' => 'Fehler je Version',
                'releases' => 'Versionen im Zeitraum',
                'fatal' => 'Schwere Fehler',
                'table' => 'Versionen im Vergleich',
            ],
        ],
    ],

    'copy_name' => ':name (Kopie)',

    'flash' => [
        'created' => 'Dashboard angelegt.',
        'updated' => 'Dashboard gespeichert.',
        'deleted' => 'Dashboard gelöscht.',
        'duplicated' => 'Dashboard dupliziert.',
        'widget_created' => 'Kachel hinzugefügt.',
        'widget_updated' => 'Kachel gespeichert.',
        'widget_deleted' => 'Kachel gelöscht.',
        'layout_saved' => 'Anordnung gespeichert.',
    ],

    'errors' => [
        'too_many' => 'Mehr als :limit Dashboards je Organisation sind nicht vorgesehen.',
        'too_many_widgets' => 'Mehr als :limit Kacheln je Dashboard sind nicht vorgesehen.',
    ],

];
