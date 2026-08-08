<?php

// Die Trend-Liste (app/Http/Controllers/PerformanceTrendController,
// app/Support/Performance/Trends, resources/js/shell/pages/performance/Trends).
return [

    'title' => 'Leistungstrends',
    'help' => 'Transaktionen, deren Antwortzeit umgeschlagen ist — nicht für einen '
        .'Augenblick, sondern dauerhaft. Gesucht wird der Zeitpunkt, an dem sich die '
        .'Verteilung verschoben hat; verglichen werden die Perzentile davor und danach '
        .'mit einem Rangsummentest. Ein einzelner Ausschlag reicht dafür nicht, und '
        .'ohne genügend Messungen wird gar nichts ausgewiesen.',

    'list' => [
        'empty' => 'Keine Trendbrüche im gewählten Zeitraum.',
        'empty_hint' => 'Die Suche läuft stündlich über die Antwortzeiten der letzten '
            .'Woche. Gemeldet wird nur, was sich statistisch belegen lässt.',
        'count' => ':count Trendbrüche',
        'from_to' => 'von :before auf :after',
        'samples' => ':before Messungen davor, :after danach',
        'confidence' => 'Aussagekraft :value σ',
        'breakpoint' => 'Umschlag: :value',
        'deploy' => 'Auslieferung :version (:at)',
        'no_deploy' => 'Keine Auslieferung in zeitlicher Nähe',
        'seen_by' => 'Gesehen von :name, :at',
        'seen_at' => 'Gesehen: :at',
    ],

    'columns' => [
        'transaction' => 'Transaktion',
        'change' => 'Änderung',
        'breakpoint' => 'Umschlag',
        'cause' => 'Mögliche Ursache',
        'state' => 'Stand',
    ],

    'actions' => [
        'mark_seen' => 'Als gesehen markieren',
        'mark_unseen' => 'Doch nicht gesehen',
    ],

    // Die Werte von App\Support\Performance\Trends\TrendListSort.
    'sort' => [
        'impact' => 'Größte Änderung',
        'recent' => 'Zuletzt umgeschlagen',
    ],

    'filter' => [
        'sort' => 'Sortierung',
        'direction' => 'Richtung',
        'any_direction' => 'Alle Richtungen',
        'worse' => 'Verschlechterungen',
        'better' => 'Verbesserungen',
        'seen' => 'Stand',
        'open' => 'Offen',
        'done' => 'Gesehen',
        'any_seen' => 'Alle',
    ],

    // Was die Liste ausweist und was nicht — die Antwort auf „warum steht das
    // hier nicht drin".
    'thresholds' => 'Ausgewiesen wird ein Umschlag ab :change Änderung, mit mindestens '
        .':samples Messungen und :windows Stunden auf jeder Seite und einer Aussagekraft '
        .'von :confidence σ.',

    'overview' => 'Zur Übersicht',
    'link' => 'Trendbrüche',

    'flash' => [
        'seen' => '„:transaction" ist als gesehen markiert.',
        'unseen' => '„:transaction" steht wieder als offen.',
    ],

    'notification' => [
        'title' => ':transaction ist langsamer geworden (:project)',
        'body' => 'Die Antwortzeit von „:transaction" ist seit :at dauerhaft höher: '
            .'das 95. Perzentil liegt bei :after statt bei :before, also :change über '
            .'dem vorherigen Stand.',
        'samples' => ':before davor, :after danach',
        'deploy' => ':version, ausgeliefert :at',
        'context_project' => 'Projekt',
        'context_environment' => 'Umgebung',
        'context_transaction' => 'Transaktion',
        'context_before' => 'Vorher (p95)',
        'context_after' => 'Nachher (p95)',
        'context_samples' => 'Messungen',
        'context_deploy' => 'Mögliche Ursache',
    ],

];
