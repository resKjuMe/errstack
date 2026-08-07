<?php

// Gruppierung gleichartiger Meldungen (resources/js/shell/pages/projects/Grouping.jsx,
// App\Http\Controllers\FingerprintRuleController). Die Beschriftungen von
// App\Enums\GroupingSource stehen wie alle Aufzählungen in enums.php.
return [

    'title' => 'Gruppierung · :project',
    'help' => 'Gleichartige Meldungen bekommen denselben Fingerabdruck und werden zu einem Eintrag zusammengefasst — sonst stünden zehntausend gleiche Abstürze zehntausendmal in der Liste. Wonach das geschieht, entscheidet das Standardverfahren; die Regeln hier korrigieren es dort, wo es zu grob oder zu fein greift.',

    'rules' => [
        'title' => 'Fingerprint-Regeln',
        'description' => 'Die erste Regel, deren sämtliche Bedingungen zutreffen, gewinnt — auch über eine Angabe des SDK. Ohne passende Regel greift das Standardverfahren.',
        'empty' => 'Noch keine Regel. Das Standardverfahren gruppiert nach Ausnahme-Typ und Stacktrace, bei Nachrichten nach der Vorlage des Meldungstextes.',
        'inactive_badge' => 'abgeschaltet',
        'position' => 'Rang :position',
        'position_label' => 'Rang',
        'retroactive_hint' => 'Regeln wirken auf künftige Meldungen. Bereits ausgewertete behalten ihre Gruppe — sonst würden Zähler und Zeitverläufe rückwirkend nicht mehr stimmen.',
    ],

    'name' => 'Name',
    'name_hint' => 'Wofür die Regel da ist. Nach einem halben Jahr ist das die einzige Erklärung, die noch da ist.',

    'matchers' => [
        'label' => 'Bedingungen',
        'hint' => 'Alle müssen zutreffen. Muster mit * und ? — kein regulärer Ausdruck. Ohne Platzhalter trifft das Muster genau.',
        'attribute' => 'Feld',
        'pattern' => 'Muster',
        'negated' => 'trifft nicht zu',
        'add' => 'Bedingung hinzufügen',
        'remove' => 'Entfernen',
        'placeholder' => '*TimeoutException',
    ],

    'fingerprint' => [
        'label' => 'Fingerabdruck',
        'hint' => '{{ default }} setzt die Bestandteile des Standardverfahrens ein — damit lässt sich verfeinern statt ersetzen. Feld-Platzhalter wie {{ error.type }} oder {{ tags.mandant }} sind ebenfalls erlaubt.',
        'add' => 'Bestandteil hinzufügen',
        'remove' => 'Entfernen',
        'placeholder' => 'abrechnung',
    ],

    'save' => 'Speichern',
    'disable' => 'Abschalten',
    'enable' => 'Wieder einschalten',
    'delete' => 'Löschen',

    'create' => [
        'title' => 'Regel anlegen',
        'description' => 'Neue Regeln kommen ans Ende. Sie überstimmen bestehende erst, wenn sie davor einsortiert werden.',
        'submit' => 'Regel anlegen',
    ],

    'validation' => [
        'attribute' => 'Dieses Feld gibt es nicht. Erlaubt sind die aufgeführten Felder sowie tags.<name>.',
        'only_default' => 'Eine Regel, die nur {{ default }} setzt, tut dasselbe wie das Standardverfahren — sie sieht aber so aus, als täte sie etwas.',
        'too_many' => 'Mehr als :max Regeln je Projekt sind nicht möglich. Jede Regel wird bei jeder Meldung geprüft.',
    ],

    'flash' => [
        'created' => 'Regel „:name" angelegt — sie greift ab der nächsten Meldung.',
        'updated' => 'Regel „:name" gespeichert.',
        'enabled' => 'Regel „:name" ist wieder aktiv.',
        'disabled' => 'Regel „:name" ist abgeschaltet und greift nicht mehr.',
        'deleted' => 'Regel „:name" gelöscht. Bereits gruppierte Meldungen bleiben, wo sie sind.',
    ],

];
