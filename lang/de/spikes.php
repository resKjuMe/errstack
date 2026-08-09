<?php

return [
    'title' => 'Ausschlag-Schutz — :project',
    'help' => 'Erkennt ungewöhnliche Fehlerfluten anhand des bisherigen Verlaufs, drosselt die Aufnahme und meldet das dem Team. Was dabei verworfen wird, steht auf dieser Seite.',

    'intro' => [
        'title' => 'Wozu der Schutz da ist',
        'description' => 'Eine fehlerhafte Auslieferung kann in Minuten mehr Meldungen erzeugen als sonst in Wochen. Der Schutz erkennt das am Verlauf dieses Projekts und deckelt die Aufnahme, damit Speicher und Kontingent nicht in einer Nacht aufgebraucht sind.',
        'counted_hint' => 'Gedrosselte Ereignisse werden nie stillschweigend verworfen: sie werden gezählt und stehen unten mit ihrer Anzahl.',
        'baseline_hint' => 'Gemessen wird gegen den Verlauf der letzten :minutes Minuten, nicht gegen einen festen Wert — zehntausend Meldungen je Minute sind bei der einen Anwendung Normalbetrieb und bei der anderen der Vorfall.',
    ],

    'detection' => [
        'title' => 'Woran gerade gemessen wird',
        'description' => 'Der Vergleichswert entsteht aus dem bisherigen Verlauf; gedrosselte Minuten zählen dabei nicht mit.',
        'baseline' => 'Vergleichswert',
        'baseline_value' => ':value Ereignisse/Minute',
        'threshold' => 'Schwelle',
        'threshold_value' => 'ab :value Ereignissen/Minute',
        'threshold_off' => 'noch keine',
        'samples' => 'Gemessene Minuten',
        'samples_value' => ':samples von :required',
        'not_ready' => 'Es liegen erst :samples von :required gemessenen Minuten vor. Solange entscheidet der Schutz bewusst nicht — ein Vergleichswert aus wenigen Minuten wäre keine Aussage über den Normalbetrieb.',
        'disabled' => 'Der Schutz ist für dieses Projekt ausgeschaltet. Es wird nichts gedrosselt und kein Verlauf mitgeschrieben.',
    ],

    'current' => [
        'title' => 'Es wird gerade gedrosselt',
        'description' => 'Seit :since. Neue Ereignisse werden gezählt und verworfen, bis sich die Menge beruhigt hat oder jemand die Drosselung aufhebt.',
        'discarded' => 'Bislang verworfen',
        'peak' => 'Höchste Minute',
        'threshold' => 'Schwelle beim Auslösen',
        'release' => 'Drosselung aufheben',
        'release_hint' => 'Danach werden die Meldungen wieder vollständig angenommen. Damit nicht sofort erneut gedrosselt wird, gilt anschließend eine Ruhefrist von :minutes Minuten.',
        'release_hint_none' => 'Danach werden die Meldungen wieder vollständig angenommen. Ohne Ruhefrist kann der Schutz schon in der nächsten Minute erneut auslösen.',
    ],

    'idle' => [
        'title' => 'Keine Drosselung',
        'description' => 'Die Aufnahme läuft vollständig. Insgesamt wurden bisher :count Ereignisse durch den Schutz verworfen.',
    ],

    'chart' => [
        'title' => 'Die letzte Stunde',
        'description' => 'Aufgenommene Ereignisse je Minute. Gedrosselte Minuten sind hervorgehoben; die letzte Minute läuft noch.',
        'empty' => 'Für dieses Projekt ist noch kein Verlauf mitgeschrieben.',
        'minute' => ':minute Uhr',
        'events' => ':count Ereignisse',
        'throttled' => 'gedrosselt',
    ],

    'history' => [
        'title' => 'Frühere Auslösungen',
        'description' => 'Die letzten Drosselungen dieses Projekts mit der Menge, die sie verworfen haben.',
        'empty' => 'Der Schutz hat für dieses Projekt noch nie ausgelöst.',
        'started' => 'Beginn',
        'ended' => 'Ende',
        'peak' => 'Höchste Minute',
        'discarded' => 'Verworfen',
        'released_by' => 'aufgehoben von :name',
        'ended_on_its_own' => 'von selbst beendet',
    ],

    'settings' => [
        'title' => 'Einstellungen',
        'description' => 'Ob der Schutz greift und wann eine Minute als Spitze gilt.',
        'read_only_description' => 'Ändern darf die Verwaltung der Organisation.',
        'enabled' => 'Ausschlag-Schutz einschalten',
        'enabled_hint' => 'Ist er aus, wird weder gedrosselt noch ein Verlauf mitgeschrieben.',
        'factor' => 'Faktor',
        'factor_hint' => 'Ab dem Wievielfachen des Vergleichswerts eine Minute als Spitze gilt.',
        'minimum' => 'Untergrenze',
        'minimum_hint' => 'Unterhalb dieser Menge je Minute wird nie gedrosselt — sonst genügt bei einem ruhigen Projekt ein kurzer Ausschlag.',
        'release_minutes' => 'Ruhefrist',
        'release_minutes_hint' => 'Wie lange nach einem Aufheben von Hand nicht erneut gedrosselt wird. 0 bedeutet: keine Ruhefrist.',
        'submit' => 'Speichern',
        'on' => 'ein',
        'off' => 'aus',
        'factor_value' => ':value-fach',
        'minutes_value' => ':value Minuten',
        'events_value' => ':value Ereignisse/Minute',
    ],

    'notification' => [
        'triggered_title' => 'Aufnahme gedrosselt: :project',
        'triggered_body' => 'Im Projekt „:project" sind in einer Minute :observed Ereignisse eingegangen — die Schwelle liegt bei :threshold, der übliche Verlauf bei :baseline je Minute. Die Aufnahme ist gedrosselt; weitere Ereignisse werden gezählt und verworfen, bis sich die Menge beruhigt hat.',
        'recovered_title' => 'Drosselung beendet: :project',
        'recovered_body' => 'Die Menge im Projekt „:project" hat sich wieder beruhigt, die Aufnahme läuft vollständig. In den :minutes Minuten der Drosselung wurden :discarded Ereignisse verworfen.',
        'released_title' => 'Drosselung aufgehoben: :project',
        'released_body' => ':user hat die Drosselung im Projekt „:project" aufgehoben; die Aufnahme läuft wieder vollständig. Bis zur letzten vollen Minute wurden :discarded Ereignisse verworfen.',
        'unknown_user' => 'Jemand',
        'minutes' => ':minutes Minuten',
        'context_project' => 'Projekt',
        'context_observed' => 'Beobachtet',
        'context_threshold' => 'Schwelle',
        'context_baseline' => 'Üblicher Verlauf',
        'context_discarded' => 'Verworfen',
        'context_duration' => 'Dauer',
    ],

    'flash' => [
        'saved' => 'Der Ausschlag-Schutz wurde gespeichert.',
        'released' => 'Die Drosselung wurde aufgehoben.',
        'nothing_to_release' => 'Es wird gerade nicht gedrosselt.',
    ],
];
