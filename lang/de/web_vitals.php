<?php

// Ladeerlebnis im Browser — Übersicht und Detailseite
// (resources/js/shell/pages/performance/WebVitals.jsx und WebVital.jsx,
// App\Http\Controllers\WebVitalController).
return [

    'title' => 'Ladeerlebnis',

    'help' => [
        'purpose' => 'Die Liste zeigt jede Seite, für die im gewählten Zeitraum Browser-Messwerte gemeldet wurden — sortiert danach, wie viele Besucher ein schlechtes Erlebnis hatten.',
        'percentile' => 'Jeder Wert ist das p75: drei von vier Ladevorgängen waren mindestens so gut. Diese Stelle gibt die Web-Vitals-Spezifikation vor — ein Mittelwert verschwiege die langsame Hälfte.',
        'thresholds' => 'Die Schwellen für gut, mäßig und schlecht stammen aus der Spezifikation und sind nicht einstellbar. Das ist ihr Wert: die Zahl lässt sich mit anderen Anwendungen vergleichen.',
        'core' => 'Über die Bewertung einer Seite entscheiden die drei Kernwerte LCP, INP und CLS. FCP und TTFB erklären sie, sie sind nicht selbst ein Befund.',
        'no_data' => 'Seiten ohne Messwerte stehen mit dem Hinweis „keine Daten" in der Liste. Sie fehlen nicht, weil sie in Ordnung wären, sondern weil nichts gemeldet wurde — meist fehlt die Einbindung des Browser-SDK.',
    ],

    'search' => [
        'label' => 'Suche',
        'placeholder' => 'Teil eines Seitennamens',
        'submit' => 'Suchen',
        'clear' => 'Suche zurücksetzen',
    ],

    'truncated' => 'Es gibt mehr als :limit Seiten. Gezeigt werden die mit dem größten Handlungsbedarf — schränke den Zeitraum oder die Suche ein.',

    'columns' => [
        'page' => 'Seite',
        'rating' => 'Bewertung',
        'measurements' => 'Ladevorgänge',
    ],

    'row' => [
        'no_data' => 'keine Daten',
        'no_data_hint' => 'Für diese Seite sind Aufrufe bekannt, aber keine Browser-Messwerte.',
        'measurements' => ':count Ladevorgänge mit diesem Messwert',
        'distribution' => ':good gut · :needs mäßig · :poor schlecht',
        'threshold' => 'Gut bis :good, schlecht ab :poor',
    ],

    'pagination' => [
        'summary' => 'Seite :page von :pages · :total Seiten',
        'previous' => 'Zurück',
        'next' => 'Weiter',
    ],

    'empty' => [
        'no_data' => 'Im gewählten Zeitraum wurden keine Browser-Messwerte gemeldet.',
        'no_data_hint' => 'Binde das Browser-SDK mit aktiviertem Performance-Monitoring ein und lade ein paar Seiten — die Messwerte erscheinen dann hier.',
        'no_results' => 'Keine Seite passt zu dieser Suche.',
        'no_results_hint' => 'Gesucht wird ein Teil des Seitennamens.',
    ],

    'detail' => [
        'title' => 'Ladeerlebnis einer Seite',
        'back' => 'Zurück zur Übersicht',

        'help' => [
            'purpose' => 'Alle Browser-Messwerte dieser Seite, dazu der Verlauf und die Aufschlüsselung des ausgewählten Messwerts.',
            'select' => 'Verlauf, Verteilung und Aufschlüsselung gelten für den ausgewählten Messwert. Sechs Grafiken nebeneinander beantworten keine Frage, die eine nicht schon beantwortet.',
            'facets' => 'Die Aufschlüsselung beruht auf einer Stichprobe der Einzelmessungen — sie zeigt Anteile, keine vollständigen Zahlen. Die Kennzahlen darüber sind vollständig.',
            'thresholds' => 'Die Bewertung wird beim Eintreffen jeder Messung mit deren genauem Wert gebildet und ist damit exakt; die angezeigte Zahl ist auf wenige Prozent genau.',
        ],

        'empty' => 'Für diese Seite wurden im gewählten Zeitraum keine Browser-Messwerte gemeldet.',
        'empty_hint' => 'Vielleicht liegt der Zeitraum daneben, oder das Browser-SDK meldet für diese Seite nichts.',

        'select' => 'Messwert auswählen',
        'no_measurement' => 'Für diesen Messwert liegt nichts vor.',

        'summary' => [
            'p75' => 'p75',
            'avg' => 'Mittelwert',
            'min' => 'Bestes',
            'max' => 'Schlechtestes',
            'count' => 'Messungen',
        ],

        'histogram' => [
            'title' => 'Verteilung',
            'bar' => ':count Messungen zwischen :from und :to',
            'open_end' => 'darüber',
            'hint' => 'Ein zweiter Hügel weit rechts ist ein anderer Befund als ein breiter Berg: im ersten Fall gibt es eine Gruppe von Geräten mit einem eigenen Problem, im zweiten ist die Seite als Ganzes zu schwer.',
        ],

        'series' => [
            'title' => 'Verlauf',
            'point' => ':at — :value aus :count Messungen',
            'period_hour' => 'Ein Balken je Stunde.',
            'period_day' => 'Ein Balken je Tag.',
        ],

        'facets' => [
            'title' => 'Aufschlüsselung',
            'hint' => 'Aus :sampled von höchstens :limit gelesenen Einzelmessungen.',
            'truncated' => 'Weitere Werte sind nicht aufgeführt.',
            'empty' => 'Zu wenige Einzelmessungen für eine Aufschlüsselung.',
            'value' => 'Wert',
            'count' => 'Messungen',
            'measured' => 'p75',
        ],
    ],

    'facets' => [
        'device' => 'Gerät',
        'browser' => 'Browser',
        'country' => 'Land',
    ],

];
