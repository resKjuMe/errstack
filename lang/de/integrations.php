<?php

// Anbindungen an Anbieter (X1) — App\Http\Controllers\IntegrationController,
// App\Http\Controllers\GitHubIntegrationController,
// resources/js/shell/pages/integrations und
// resources/js/shell/pages/issues/detail/ExternalIssues.jsx.
return [

    'title' => 'Anbindungen',
    'help' => 'Wo der Code dieser Organisation liegt — und was daraus von selbst '
        .'hereinkommt: die Commits einer Auslieferung, ohne dass eine Pipeline sie '
        .'übergeben muss. Aus einem Fehler lässt sich damit außerdem ein Ticket '
        .'anlegen, und wenn es geschlossen wird, gilt der Fehler als erledigt.',

    'empty' => 'Noch nicht verbunden. Nach dem Verbinden wird ausgewählt, welche '
        .'Repositories diese Organisation versorgen.',

    'not_configured' => [
        'title' => 'Für diese Installation nicht eingerichtet',
        'hint' => 'Es fehlen die Zugangsdaten der OAuth-App (GITHUB_CLIENT_ID und '
            .'GITHUB_CLIENT_SECRET). Ohne sie endet das Verbinden bei GitHub in einer '
            .'Fehlerseite — der Rest der Anwendung ist davon unberührt.',
    ],

    'lost' => [
        'title' => 'Verbindung verloren',
        'hint' => 'GitHub weist den Zugang ab. Bis er erneuert ist, kommen keine '
            .'Commits mehr herein und es lassen sich keine Tickets anlegen. Der '
            .'häufigste Grund: das Zugriffsrecht wurde entzogen oder die Anmeldung '
            .'ist abgelaufen.',
    ],

    'fields' => [
        'account' => 'Konto',
        'status' => 'Zustand',
        'connected_at' => 'Verbunden',
        'connected_by' => ':at von :name',
        'last_synced_at' => 'Zuletzt geholt',
        'never' => 'Noch nie',
    ],

    'actions' => [
        'connect' => 'Mit GitHub verbinden',
        'reconnect' => 'Neu verbinden',
        'disconnect' => 'Anbindung lösen',
        'disconnect_confirm' => 'Die Anbindung lösen? Die Repositories und ihre '
            .'Commits bleiben — es holt nur niemand mehr Neues, und Tickets lassen '
            .'sich nicht mehr anlegen.',
    ],

    'repositories' => [
        'title' => 'Versorgte Repositories',
        'hint' => 'Aus diesen Repositories werden Commits geholt und in ihnen lassen '
            .'sich Tickets anlegen.',
        'empty' => 'Noch keines ausgewählt.',
        'manage' => 'Alle Repositories dieser Organisation ansehen',
        'add' => 'Repository verbinden',
        'add_hint' => 'Die Liste wird erst auf Anforderung bei GitHub geholt — die '
            .'Seite soll auch dann laden, wenn GitHub gerade nicht antwortet.',
        'load' => 'Auswahl laden',
        'loading' => 'Wird geladen …',
        'load_failed' => 'Die Auswahl konnte nicht geladen werden.',
        'choose' => 'Repository wählen …',
        'connect' => 'Verbinden',
    ],

    'issue' => [
        'title' => 'Ticket beim Anbieter',
        'description' => 'Woran gearbeitet wird — und was passiert, wenn es dort '
            .'geschlossen wird: der Fehler gilt dann als erledigt.',
        'hint' => 'Ohne Nummer wird ein neues Ticket angelegt. Mit Nummer wird ein '
            .'vorhandenes verknüpft.',
        'no_repositories' => 'Für diese Organisation ist noch kein Repository über '
            .'die Anbindung verbunden.',

        'fields' => [
            'number' => 'Nummer',
            'number_placeholder' => 'Nr.',
        ],

        'actions' => [
            'create' => 'Ticket anlegen',
            'link' => 'Verknüpfen',
            'unlink' => 'Lösen',
        ],

        // Der Rumpf eines neu angelegten Tickets. Bewusst knapp: was zählt, ist
        // der Link zurück — alles andere hier ist eine Kopie, und Kopien altern,
        // während die Seite dahinter aktuell bleibt.
        'body' => [
            'culprit' => 'Fehlerstelle',
            'project' => 'Projekt',
            'times_seen' => 'Aufgetreten',
            'first_seen' => 'Zuerst gesehen',
            'link' => 'Der Fehler in Errstack: :url',
        ],
    ],

    'flash' => [
        'connected' => 'Mit GitHub verbunden.',
        'disconnected' => 'Anbindung gelöst.',
        'aborted' => 'Das Verbinden wurde abgebrochen.',
        'failed' => 'Das Verbinden ist gescheitert: :reason',
        'state_mismatch' => 'Das Verbinden ist abgelaufen. Bitte erneut versuchen.',
        'not_configured' => 'Für diese Installation ist keine GitHub-App eingerichtet.',
        'repository_connected' => 'Repository :name verbunden.',
        'issue_linked' => 'Mit :reference verknüpft.',
        'issue_unlinked' => 'Verknüpfung gelöst.',
    ],

    'errors' => [
        'not_connected' => 'Es ist keine funktionierende GitHub-Anbindung vorhanden.',
        'no_token' => 'Für diese Anbindung ist kein Zugriffstoken hinterlegt.',
        'invalid_repository' => 'Der Name des Repositories ist unbrauchbar.',
        'unexpected_response' => 'GitHub hat unerwartet geantwortet.',
        'http_status' => 'GitHub hat mit Status :status geantwortet.',
        'token_exchange' => 'GitHub hat kein Zugriffstoken herausgegeben.',
    ],

    // Was mit einer eingegangenen Meldung geschehen ist. Steht am Ereignis und
    // ist die Antwort auf „warum hat sich nichts getan?" — der häufigste Fall im
    // Betrieb ist „angekommen, passt zu nichts".
    'webhook' => [
        'results' => [
            'ignored' => 'Nicht ausgewertet (:event).',
            'unmatched' => 'Keine Zuordnung möglich.',
            'unlinked' => 'Kein Fehler mit diesem Ticket verknüpft.',
            'issue' => ':links Verknüpfung(en) aktualisiert, :resolved erledigt.',
            'push' => ':releases Auslieferung(en) holen ihre Commits nach.',
        ],
    ],

];
