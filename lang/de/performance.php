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

];
