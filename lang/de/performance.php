<?php

// Performance-Übersicht (resources/js/shell/pages/Performance.jsx,
// App\Http\Controllers\PerformanceController).
return [

    'title' => 'Leistung',

    'help' => [
        'purpose' => 'Die Liste zeigt jede Seite und jeden Endpunkt, für die im gewählten Zeitraum Antwortzeiten gemeldet wurden — sortiert nach dem größten Problem.',
        'percentiles' => 'p95 heißt: 95 von 100 Aufrufen waren schneller. Der Mittelwert versteckt genau die Ausreißer, wegen derer man hier nachsieht.',
        'sampling' => 'Der Durchsatz ist auf die tatsächliche Zahl der Aufrufe hochgerechnet, falls für das Projekt eine Stichprobe eingestellt ist. Antwortzeiten und Fehlerrate werden nicht hochgerechnet — sie lassen sich aus einer Stichprobe unverzerrt schätzen.',
        'trend' => 'Der Trendpfeil vergleicht das p95 mit dem gleich langen Zeitraum davor. Unter fünf Messungen je Seite bleibt er weg, sonst zeigte ein einzelner Ausreißer eine Verschlechterung an.',
        'search' => 'Die Suche trifft Namen und Operation. Mehrere Begriffe werden UND-verknüpft; „op:http.server" schränkt auf eine bestimmte Operation ein.',
    ],

    'columns' => [
        'name' => 'Transaktion',
        'throughput' => 'Durchsatz',
        'p50' => 'p50',
        'p75' => 'p75',
        'p95' => 'p95',
        'p99' => 'p99',
        'avg' => 'Mittelwert',
        'failureRate' => 'Fehlerrate',
        'users' => 'Nutzer',
        'userMisery' => 'Unzufrieden',
        'count' => 'Messungen',
        'trend' => 'Trend',
    ],

    'search' => [
        'label' => 'Suche',
        'placeholder' => 'Name oder op:http.server',
        'submit' => 'Suchen',
        'clear' => 'Suche zurücksetzen',
    ],

    'sort' => [
        'ascending' => 'Aufsteigend nach :column sortieren',
        'descending' => 'Absteigend nach :column sortieren',
    ],

    'row' => [
        'no_op' => 'ohne Operation',
        'measurements' => ':measured von :extrapolated hochgerechneten Aufrufen gemessen',
        'users' => ':miserable von :users Nutzern mussten zu lange warten',
        'trend_change' => ':label (:change gegenüber dem Vorzeitraum)',
    ],

    'units' => [
        'per_minute' => '/min',
        'microseconds' => 'µs',
        'milliseconds' => 'ms',
        'seconds' => 's',
        'bytes' => 'B',
        'kilobytes' => 'KB',
        'megabytes' => 'MB',
    ],

    'empty' => [
        'no_data' => 'Im gewählten Zeitraum wurden für diese Projekte keine Antwortzeiten gemeldet.',
        'no_data_hint' => 'Antwortzeiten kommen aus dem Performance-SDK der überwachten Anwendung. Solange dort nichts eingerichtet ist, bleibt diese Liste leer, auch wenn Fehlermeldungen ankommen.',
        'no_results' => 'Keine Transaktion passt zu dieser Suche.',
        'no_results_hint' => 'Suchbegriffe werden UND-verknüpft — je weniger davon, desto mehr Treffer.',
    ],

    'truncated' => 'Es gibt mehr als :limit Transaktionen im Zeitraum. Gezeigt werden die :limit verkehrsreichsten; bitte die Suche verfeinern.',

    'pagination' => [
        'summary' => 'Seite :page von :pages · :total Transaktionen',
        'previous' => 'Zurück',
        'next' => 'Weiter',
    ],

    'transaction' => [
        'title' => 'Transaktion',
        'back' => 'Zurück zur Übersicht',

        'help' => [
            'purpose' => 'Diese Seite beantwortet, warum eine Transaktion langsam ist: wie sich die Antwortzeiten verteilen, wie sie sich entwickelt haben, welche Vorgangsart die Zeit verbraucht und welche Aufrufe sich ansehen lassen.',
            'histogram' => 'Die Verteilung zeigt, ob alle Aufrufe gleich langsam sind oder ob es zwei Gruppen gibt. Ein zweiter Hügel weit rechts heißt: es gibt einen Sonderweg, der viel länger braucht.',
            'sample' => 'Kennzahlen und Verlauf beruhen auf allen Messungen des Zeitraums. Zeitfresser, Merkmale und Beispiele werden aus einer begrenzten Stichprobe der jüngsten Aufrufe berechnet — ihre Größe steht an den jeweiligen Abschnitten.',
            'samples' => 'Die Beispielfälle sind gezielt aus den Perzentil-Bereichen gewählt und nicht zufällig: ein zufälliger Aufruf wäre fast immer ein schneller.',
            'facets' => 'Als auffällig markiert wird ein Wert, dessen p95 mindestens das Anderthalbfache der übrigen Werte beträgt — der Fall „nur diese eine Version ist langsam".',
        ],

        'empty' => 'Für diese Transaktion wurden im gewählten Zeitraum keine Antwortzeiten gemeldet.',
        'empty_hint' => 'Der Zeitraum lässt sich oben ändern. Ein Link auf „letzte 24 Stunden" zeigt morgen andere Daten — das ist kein Fehler, sondern der Zeitraum.',

        'histogram' => [
            'title' => 'Verteilung der Antwortzeiten',
            'bar' => ':count Messungen zwischen :from und :to',
            'open_end' => 'darüber',
            'hint' => 'Die Klassen verdoppeln sich jeweils: links die schnellen Aufrufe, rechts die langsamen.',
        ],

        'series' => [
            'title' => 'Verlauf (p95)',
            'point' => ':at · p95 :p95 aus :count Messungen',
            'period_hour' => 'Ein Balken je Stunde.',
            'period_day' => 'Ein Balken je Tag.',
        ],

        'spans' => [
            'title' => 'Größte Zeitfresser',
            'description' => 'Nach Vorgangsart, aus :transactions Aufrufen. Die Anteile beziehen sich auf die Gesamtzeit aller Schritte — Schritte liegen ineinander, ihre Summe ist deshalb größer als die Antwortzeit.',
            'detail' => ':count Schritte · :total gesamt · :average im Mittel',
            'empty' => 'Zu diesen Aufrufen wurden keine Einzelschritte gemeldet. Ohne sie ist nur die Gesamtdauer bekannt — Einzelschritte kommen aus dem Tracing des SDK.',
        ],

        'facets' => [
            'title' => 'Auffällige Merkmale',
            'description' => 'Das p95 je Version, Umgebung und Plattform — aus derselben Stichprobe.',
            'empty' => 'Es gibt kein Merkmal mit mehr als einem Wert; damit lässt sich nichts vergleichen.',
            'outlier' => 'auffällig',
            'keys' => [
                'release' => 'Version',
                'environment' => 'Umgebung',
                'platform' => 'Plattform',
            ],
        ],

        'samples' => [
            'title' => 'Beispielfälle',
            'description' => 'Je Perzentil-Bereich ein tatsächlicher Aufruf.',
            'empty' => 'Zu dieser Transaktion liegen keine Einzelmessungen mehr vor.',
            'detail' => ':at · :spans Schritte · :release',
            'no_release' => 'ohne Version',
            'no_trace_view' => 'Die Trace-Ansicht steht noch nicht zur Verfügung.',
        ],

        'issues' => [
            'title' => 'Verknüpfte Fehler',
            'description' => 'Fehler, die im Zeitraum unter diesem Transaktionsnamen gemeldet wurden.',
            'empty' => 'Unter diesem Namen wurde im Zeitraum kein Fehler gemeldet.',
        ],
    ],

];
