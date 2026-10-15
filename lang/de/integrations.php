<?php

// Anbindungen an Anbieter (X1, X4) — App\Http\Controllers\IntegrationController,
// App\Http\Controllers\GitHubIntegrationController,
// App\Http\Controllers\TicketIntegrationController,
// resources/js/shell/pages/integrations und
// resources/js/shell/pages/issues/detail/ExternalIssues.jsx.
return [

    'title' => 'Anbindungen',
    'help' => 'Wo der Code dieser Organisation liegt und wo ihre Tickets geführt '
        .'werden. Aus dem Code kommen die Commits einer Auslieferung von selbst '
        .'herein, ohne dass eine Pipeline sie übergeben muss; aus einem Fehler '
        .'lässt sich ein Ticket anlegen, und sein Zustand wird in beiden Richtungen '
        .'abgeglichen — je Richtung schaltbar.',

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
        'provider' => 'Anbieter',

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

    // Die Ticket-Systeme (X4).
    'ticket' => [
        'empty' => 'Noch nicht verbunden. Zum Verbinden wird ein API-Token '
            .'gebraucht — es gehört dieser Organisation und wird verschlüsselt '
            .'abgelegt.',
        'docs' => 'Wo bekomme ich ein Token?',

        'fields' => [
            'token' => 'API-Token',
            'base_url' => 'Adresse der Instanz',
            'base_url_placeholder' => 'https://acme.atlassian.net',
            'email' => 'E-Mail-Adresse zum Token',
            'target' => 'Projekt',
            'default_project' => 'Projekt (Vorbelegung)',
            'default_type' => 'Vorgangstyp',
            'default_priority' => 'Priorität',
            'default_assignee' => 'Zuständig (Kennung beim Anbieter)',
        ],

        'hints' => [
            'base_url' => 'Die Adresse Ihrer Jira-Instanz, ohne Pfad.',
            'email' => 'Die Adresse des Kontos, mit dem das Token erzeugt wurde. '
                .'Jira Cloud verlangt beides zusammen.',
            'token' => 'Wird geprüft, bevor es gespeichert wird — und danach nie '
                .'wieder angezeigt.',
            'default_type' => 'Der Vorgangstyp neuer Tickets. Leer bedeutet '
                .'„Task"; nur bei Jira von Belang.',
            'default_priority' => 'Bei Jira der Name („High"), bei Linear die Zahl '
                .'(0–4). Leer heißt: nicht mitschicken.',
            'default_assignee' => 'Die Kennung des Kontos beim Anbieter. Eine '
                .'Zuordnung zwischen den Nutzerverwaltungen gibt es nicht — hier '
                .'steht eine feste Vorbelegung, keine Übersetzung.',
        ],

        'sync' => [
            'title' => 'Statusabgleich',
            'hint' => 'Je Richtung getrennt schaltbar. Die ausgehende Richtung '
                .'schreibt in einem fremden System — deshalb sitzt sie nicht mit '
                .'der anderen an einem Schalter.',
            'inbound' => 'Ticket erledigt → Fehler erledigt',
            'inbound_hint' => 'Ein wieder geöffnetes Ticket öffnet den Fehler nicht '
                .'von selbst: „erledigt" kann hier auf einem zweiten Ticket oder '
                .'einer Auslieferung beruhen.',
            'outbound' => 'Fehler erledigt → Ticket erledigt',
            'outbound_hint' => 'Wird der Fehler hier wieder geöffnet, geht das '
                .'Ticket denselben Weg zurück.',
        ],

        'defaults' => [
            'title' => 'Vorbelegung neuer Tickets',
            'hint' => 'Womit ein neues Ticket anfängt. Nichts davon wird gegen den '
                .'Anbieter geprüft — ein Projekt, das es nicht gibt, meldet sich '
                .'beim ersten Anlegen mit seiner eigenen Meldung.',
        ],

        'webhook' => [
            'title' => 'Rückadresse',
            'hint' => 'Diese Adresse beim Anbieter als Webhook eintragen, damit ein '
                .'geschlossenes Ticket hier ankommt. Sie enthält ein Geheimnis: '
                .'behandeln Sie sie wie ein Passwort.',
            'why' => 'Warum ein Geheimnis in der Adresse und keine Unterschrift? '
                .'Jira Cloud unterschreibt eine so eingetragene Rückadresse nicht, '
                .'und Linears Unterschrift hängt an einem Geheimnis, das erst beim '
                .'Einrichten drüben entsteht.',
            'rotate' => 'Adresse erneuern',
            'rotate_confirm' => 'Die Adresse erneuern? Die alte antwortet danach '
                .'nicht mehr — sie muss beim Anbieter ersetzt werden.',
        ],

        'actions' => [
            'connect' => 'Verbinden',
            'reconnect' => 'Token ersetzen',
            'save' => 'Speichern',
        ],

        'targets' => [
            'load' => 'Auswahl laden',
            'loading' => 'Wird geladen …',
            'choose' => 'Projekt wählen …',
            'load_failed' => 'Die Auswahl konnte nicht geladen werden.',
        ],
    ],

    'flash' => [
        'connected' => 'Mit GitHub verbunden.',
        'ticket_connected' => 'Mit :provider verbunden (:account).',
        'settings_saved' => 'Die Einstellungen sind gespeichert.',
        'webhook_rotated' => 'Die Rückadresse ist erneuert. Sie muss beim Anbieter '
            .'ersetzt werden.',
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
        'ticket_not_connected' => 'Es ist keine funktionierende :provider-Anbindung vorhanden.',
        'no_token' => 'Für diese Anbindung ist kein Zugriffstoken hinterlegt.',
        'invalid_repository' => 'Der Name des Repositories ist unbrauchbar.',
        'unexpected_response' => ':provider hat unerwartet geantwortet.',
        'http_status' => ':provider hat mit Status :status geantwortet.',
        'token_exchange' => 'GitHub hat kein Zugriffstoken herausgegeben.',
        'unsupported_provider' => 'Dieser Anbieter wird nicht unterstützt.',

        // Fachfehler der Ticket-Systeme (X4). Sie stehen einzeln da und nicht als
        // freundlichere Sammelmeldung, weil jeder von ihnen eine andere Handlung
        // nach sich zieht — und weil „das hat nicht funktioniert" niemandem sagt,
        // was zu tun ist.
        'invalid_number' => 'Die Nummer passt nicht zum ausgewählten Projekt.',
        'ticket_not_found' => 'Es gibt kein Ticket :reference.',
        'not_created' => ':provider hat das Ticket nicht angelegt.',
        'not_updated' => 'Das Ticket :reference ließ sich nicht ändern.',
        'no_external_id' => 'Für :reference ist keine Kennung des Anbieters '
            .'hinterlegt — die Verknüpfung lässt sich nicht abgleichen.',
        'no_transition' => 'Der Arbeitsablauf von :reference lässt diesen Wechsel '
            .'nicht zu.',
        'no_state' => 'Das Team :team hat keinen passenden Zustand.',
        'unknown_target' => 'Das Projekt :target gibt es dort nicht.',
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
            'disconnected' => 'Die Anbindung ist gelöst — nichts zu tun.',
            'inbound_off' => 'Der Abgleich von dort nach hier ist abgeschaltet.',
        ],
    ],

];
