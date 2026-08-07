<?php

// Stichproben der Antwortzeiten (resources/js/shell/pages/projects/Sampling.jsx,
// App\Http\Controllers\SamplingRuleController). Die Beschriftung des
// Verwerfungsgrundes „sampled" steht wie alle Aufzählungen in enums.php.
return [

    'title' => 'Stichproben · :project',
    'help' => 'Von den gemeldeten Antwortzeiten wird nur ein Anteil gespeichert und in den Auswertungen wieder hochgerechnet. Gebraucht wird nicht jeder einzelne Aufruf, sondern die Verteilung — und die steht in einer Stichprobe genauso. Fehlermeldungen sind davon nicht betroffen: sie werden immer vollständig behalten.',

    'rules' => [
        'title' => 'Stichproben-Regeln',
        'description' => 'Die erste Regel, deren sämtliche Bedingungen zutreffen, gewinnt. Trifft keine zu, wird alles behalten.',
        'empty' => 'Noch keine Regel — es wird alles behalten. Das ist die richtige Voreinstellung: eine Stichprobe soll eine Entscheidung sein.',
        'inactive_badge' => 'abgeschaltet',
        'position' => 'Rang :position',
        'position_label' => 'Rang',
        'irreversible_hint' => 'Regeln wirken auf künftige Meldungen. Was eine Regel aussiebt, ist endgültig weg — anders als bei der Gruppierung hilft hier keine erneute Auswertung.',
        'errors_hint' => 'Fehlermeldungen sind von diesen Regeln nie betroffen, auch wenn eine Bedingung auf sie zuträfe. Ein Absturz ist ein Einzelfall, und ein Einzelfall lässt sich nicht hochrechnen.',
    ],

    'name' => 'Name',
    'name_hint' => 'Wofür die Regel da ist. Nach einem halben Jahr ist das die einzige Erklärung, die noch da ist.',

    'conditions' => [
        'label' => 'Bedingungen',
        'hint' => 'Alle ausgefüllten müssen zutreffen; leere sind der Regel gleichgültig. Muster mit * — kein regulärer Ausdruck. Groß- und Kleinschreibung zählt.',
        'all' => 'Ohne Bedingung: trifft auf jeden Aufruf zu und ist damit die Vorgabe des Projekts.',
        'transaction_name' => 'Transaktionsname',
        'environment' => 'Umgebung',
        'release' => 'Version',
        'op' => 'Anfragetyp',
        'placeholder' => [
            'transaction_name' => 'GET /health',
            'environment' => 'production',
            'release' => 'errstack@1.*',
            'op' => 'http.server',
        ],
    ],

    'rate' => [
        'label' => 'Behalten',
        'hint' => 'Der Anteil, der gespeichert wird — 1 % heißt: eine von hundert Messungen. Der Durchsatz wird in den Auswertungen wieder hochgerechnet, Antwortzeiten und Fehlerrate bleiben unverändert richtig.',
        'suffix' => '%',
    ],

    'minimum' => [
        'label' => 'Mindestens',
        'hint' => 'So viele Meldungen eines Vorgangs werden je Zeitfenster (:seconds s) immer behalten, auch wenn die Quote es nicht vorsieht. Ohne diese Untergrenze verschwindet der nächtliche Import, der einmal je Stunde läuft, bei 1 % Quote fast sicher ganz.',
        'suffix' => 'je Fenster',
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
        'too_many' => 'Mehr als :max Regeln je Projekt sind nicht möglich. Jede Regel wird bei jeder gemeldeten Transaktion geprüft.',
    ],

    'flash' => [
        'created' => 'Regel „:name" angelegt — sie greift ab der nächsten Meldung.',
        'updated' => 'Regel „:name" gespeichert.',
        'enabled' => 'Regel „:name" ist wieder aktiv.',
        'disabled' => 'Regel „:name" ist abgeschaltet; es wird wieder alles behalten, worauf sie zutraf.',
        'deleted' => 'Regel „:name" gelöscht. Bereits ausgesiebte Messungen kommen nicht zurück.',
    ],

];
