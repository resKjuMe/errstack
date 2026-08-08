<?php

// Die Fehlerliste (app/Http/Controllers/IssueController,
// resources/js/shell/pages/issues).
return [

    'title' => 'Fehler',
    'help' => 'Alle Fehler der gewählten Projekte, mit Häufigkeit, betroffenen '
        .'Nutzern und Verlauf. Ein Eintrag steht für einen Fehler, nicht für eine '
        .'Meldung: gezählt wird, wie oft er aufgetreten ist. Der Zeitraum der '
        .'Filterleiste bestimmt, welche Fehler erscheinen — jeder, der darin '
        .'aufgetreten ist.',

    'list' => [
        'untitled' => 'Ohne Titel',
        'empty' => 'Keine Fehler im gewählten Zeitraum.',
        'empty_hint' => 'Sobald eine Meldung eingeht, steht sie hier.',
        'count' => ':count Fehler',
        'first_seen' => 'Zuerst: :value',
    ],

    'columns' => [
        'issue' => 'Fehler',
        'trend' => 'Verlauf',
        'events' => 'Häufigkeit',
        'users' => 'Nutzer',
        'last_seen' => 'Zuletzt',
    ],

    // Die Werte von App\Enums\IssueSort.
    'sort' => [
        'last_seen' => 'Zuletzt aufgetreten',
        'first_seen' => 'Zuerst aufgetreten',
        'times_seen' => 'Häufigkeit',
        'priority' => 'Dringlichkeit',
    ],

    'filter' => [
        'sort' => 'Sortierung',
        'status' => 'Zustand',
        'any_status' => 'Alle',
        'search' => 'Suche',
        'search_placeholder' => 'z. B. is:unresolved browser:Chrome timesSeen:>100',
        'search_hint' => 'Feld:Wert, mehrere Begriffe sind ein Und. `!` verneint, '
            .'`or` und Klammern verknüpfen, `*` steht für beliebige Zeichen.',
        'search_error' => 'Der Suchausdruck wurde nicht verstanden: :message '
            .'Gezeigt wird deshalb die ungefilterte Liste.',
        'search_error_at' => 'an dieser Stelle: :excerpt',
        'search_unavailable' => 'Noch nicht auswertbar: :terms. Diese Begriffe gehören zur '
            .'Suchsprache, aber die Daten dazu kommen erst mit einer späteren Aufgabe — '
            .'sie schränken die Liste nicht ein.',
        'search_suggestions' => 'Vorschläge',
    ],

    // Die Standard-Ansichten (S5): App\Support\Issues\IssueViews.
    'views' => [
        'unresolved' => 'Offen',
        'for_review' => 'Zur Prüfung',
        'regressed' => 'Wieder aufgetreten',
        'assigned' => 'Mir zugewiesen',
        'new_24h' => 'Neu (24 Stunden)',
        'ignored' => 'Stummgeschaltet',
    ],

    // Die gespeicherten Suchen (S5): App\Http\Controllers\SavedSearchController,
    // resources/js/shell/pages/issues/SavedSearches.jsx.
    'saved' => [

        'title' => 'Ansichten',
        'views' => 'Standard-Ansichten',
        'own' => 'Gespeicherte Suchen',
        'empty' => 'Noch keine eigene Suche gespeichert.',
        'unavailable' => 'Diese Ansicht ist noch nicht vollständig auswertbar.',
        'shared_by' => 'freigegeben von :name',
        'default_badge' => 'Standard',
        'manage' => 'Verwalten',
        'close' => 'Schließen',

        'save' => 'Suche speichern',
        'save_hint' => 'Gespeichert werden Suchtext und Sortierung — nicht Zeitraum, '
            .'Projektauswahl und Umgebung. Die bleiben so, wie die Filterleiste sie zeigt.',
        'name' => 'Name',
        'name_placeholder' => 'z. B. Kritische offene Fehler',
        'query' => 'Suchtext',
        'sort' => 'Sortierung',
        'shared' => 'Für die Organisation freigeben',
        'shared_hint' => 'Freigegebene Suchen sehen alle in dieser Organisation. '
            .'Ändern und löschen kannst nur du sie.',
        'submit' => 'Speichern',
        'cancel' => 'Abbrechen',

        'rename' => 'Umbenennen',
        'delete' => 'Löschen',
        'confirm_delete' => 'Diese gespeicherte Suche löschen?',
        'set_default' => 'Standard für :project',
        'clear_default' => 'Nicht mehr Standard für :project',
        'default_hint' => 'Die Fehlerliste geht mit dieser Suche auf, wenn nur :project '
            .'gewählt ist. Nur für dich.',

        'flash' => [
            'created' => 'Die Suche wurde gespeichert.',
            'updated' => 'Die Suche wurde geändert.',
            'deleted' => 'Die Suche wurde gelöscht.',
            'default_set' => 'Diese Suche ist jetzt dein Standard für :project.',
            'default_cleared' => 'Der Standard wurde aufgehoben.',
        ],

        'errors' => [
            'too_many' => 'Mehr als :limit gespeicherte Suchen je Organisation sind nicht '
                .'vorgesehen. Lösche eine, die du nicht mehr brauchst.',
        ],
    ],

    // Die betroffenen Versionen an einer Zeile der Liste.
    'release' => [
        'first' => 'Zuerst in',
        'last' => 'Zuletzt in',
        'only' => 'In',
    ],

    'trend' => [
        'label' => 'Verlauf, :period',
    ],

    'selection' => [
        'row' => 'Fehler auswählen',
        'page' => 'Alle auf dieser Seite auswählen',
        'selected' => ':count ausgewählt',
        'select_all' => 'Alle :count Fehler auswählen',
        'all_selected' => 'Alle :count Fehler der Auswahl sind ausgewählt.',
        'clear' => 'Auswahl aufheben',
        'no_actions' => 'Keine Aktion möglich.',
    ],

    // Die Zustandsaktionen (S6): App\Http\Controllers\IssueActionController,
    // resources/js/shell/pages/issues/IssueActions.jsx.
    'actions' => [

        'title' => 'Aktionen',
        'menu' => 'Weitere Aktionen',
        'selected' => 'Aktion auf :count ausgewählte Fehler',

        'resolve' => 'Erledigen',
        'unresolve' => 'Wieder öffnen',
        'ignore' => 'Stummschalten',
        'bookmark' => 'Merken',
        'unbookmark' => 'Vormerkung aufheben',
        'subscribe' => 'Abonnieren',
        'unsubscribe' => 'Abbestellen',
        'delete' => 'Löschen',
        'discard' => 'Löschen und künftig verwerfen',

        'apply' => 'Ausführen',
        'cancel' => 'Abbrechen',
        'threshold' => 'Anzahl',

        'window' => [
            'label' => 'Zeitfenster',
            'none' => 'Ohne Zeitfenster',
            'hour' => 'in einer Stunde',
            'day' => 'an einem Tag',
            'week' => 'in einer Woche',
        ],

        // Die Bedingung einer Stummschaltung in Worten — sie steht in der
        // Meldung, im Kopf der Detailseite und im Verlauf.
        'condition' => [
            'count' => 'bis :count weitere Ereignisse',
            'count_window' => 'bis :count weitere Ereignisse in :minutes Minuten',
            'users' => 'bis :count weitere Betroffene',
        ],

        'ignored_state' => 'Stummgeschaltet :condition — :done von :total.',
        'resolved_in' => 'Erledigt in Version :release.',
        'resolved_next' => 'Erledigt mit der nächsten Auslieferung.',

        'confirm' => [
            'delete' => 'Diese Fehler löschen? Die Zähler sind danach weg. Tritt der '
                .'Fehler erneut auf, entsteht ein neuer Eintrag.',
            'discard' => 'Diese Fehler löschen und künftige Meldungen verwerfen? '
                .'Gleichartige Meldungen werden ab sofort nicht mehr angenommen, '
                .'bis das Verwerfen aufgehoben wird.',
        ],

        'undo' => [
            'default' => 'Rückgängig',
            // Beim Löschen nimmt der Rückweg nur die Verwerfung zurück — der
            // Eintrag selbst ist weg. Die Beschriftung sagt das, statt
            // "Rückgängig" zu versprechen und die Hälfte zu tun.
            'discard' => 'Verwerfen aufheben',
        ],

        'flash' => [
            'resolved' => ':count Fehler erledigt (:condition).',
            'unresolve' => ':count Fehler wieder geöffnet.',
            'ignored' => ':count Fehler stummgeschaltet (:condition).',
            'bookmark' => ':count Fehler gemerkt.',
            'unbookmark' => 'Vormerkung für :count Fehler aufgehoben.',
            'subscribe' => ':count Fehler abonniert.',
            'unsubscribe' => ':count Fehler abbestellt.',
            'delete' => ':count Fehler gelöscht.',
            'discard' => ':count Fehler gelöscht; gleichartige Meldungen werden '
                .'künftig verworfen.',
            'none' => 'Kein Fehler betroffen — die Auswahl ist inzwischen nicht mehr da.',
            'undone' => 'Die Aktion wurde zurückgenommen.',
            'undo_expired' => 'Das lässt sich nicht mehr zurücknehmen.',
        ],

        'validation' => [
            'no_target' => 'Kein Fehler ausgewählt.',
            'mode' => 'Bitte eine Bedingung wählen.',
            'count' => 'Bitte eine Anzahl angeben.',
            'window' => 'Ein Zeitfenster gibt es nur bei einer Ereignis-Schwelle.',
        ],

    ],

    // Der Aktivitätsverlauf eines Fehlers
    // (App\Support\Issues\IssueActivityFeed).
    'activity' => [
        'title' => 'Verlauf',
        'empty' => 'Noch nichts geschehen.',
        'by' => 'von :actor',
        'system' => 'automatisch',

        'resolved' => 'Erledigt (:condition)',
        'unresolved' => 'Wieder geöffnet',
        'ignored' => 'Stummgeschaltet (:condition)',
        'ignore_expired' => 'Stummschaltung beendet — die Bedingung ist eingetreten',
        'bookmarked' => 'Gemerkt',
        'unbookmarked' => 'Vormerkung aufgehoben',
        'subscribed' => 'Abonniert',
        'unsubscribed' => 'Abbestellt',
        'deleted' => 'Gelöscht',
        'discarded' => 'Gelöscht; gleichartige Meldungen werden künftig verworfen',
    ],

    // Die Kommentare an einem Fehler (App\Support\Issues\IssueComments,
    // resources/js/shell/pages/issues/detail/Comments.jsx).
    'comments' => [
        'title' => 'Kommentar schreiben',
        'placeholder' => 'Was ist zu diesem Fehler zu sagen? Mit @ eine Person oder ein Team nennen.',
        'hint' => 'Mit @ eine Person oder ein Team nennen — die Genannten werden benachrichtigt.',
        'submit' => 'Kommentieren',
        'edited' => 'bearbeitet',
        'edited_at' => 'bearbeitet am :at',
        'edit' => 'Bearbeiten',
        'save' => 'Speichern',
        'cancel' => 'Abbrechen',
        'delete' => 'Löschen',
        'delete_confirm' => 'Diesen Kommentar löschen? Das lässt sich nicht rückgängig machen.',
        'no_suggestions' => 'Niemand gefunden.',

        // Woran man in der Vorschlagsliste erkennt, was man nennt.
        'kind' => [
            'user' => 'Person',
            'team' => 'Team',
        ],

        'flash' => [
            'created' => 'Kommentar geschrieben.',
            'updated' => 'Kommentar geändert.',
            'deleted' => 'Kommentar gelöscht.',
        ],

        'notification' => [
            'title' => ':actor hat dich in :project genannt',
            'context_project' => 'Projekt',
            'context_issue' => 'Fehler',
        ],
    ],

    // Fehler von Hand zusammenführen und wieder auftrennen (S9,
    // app/Support/Issues/IssueMerging).
    'merge' => [
        'action' => ':count Fehler zusammenführen',
        'hint' => 'Die gewählten Fehler werden zu einem Eintrag. Der mit der größten '
            .'Häufigkeit wird der Kopf, die übrigen werden zu Untergruppen und lassen '
            .'sich einzeln wieder herauslösen. Es geht dabei keine Meldung verloren.',
        'merged_into' => 'Dieser Fehler ist eine Untergruppe von',

        'badge' => [
            'label' => ':count zusammengeführt',
            'hint' => 'Dieser Fehler ist von Hand aus mehreren zusammengeführt. Die Zahlen '
                .'gelten für alle Untergruppen zusammen.',
        ],

        'sources' => [
            'title' => 'Untergruppen (:count)',
            'description' => 'Von Hand zusammengeführte Fehler. Ihre Zahlen sind die des '
                .'Zusammenführens — was danach aufgetreten ist, zählt am Fehler darüber.',
            'figures' => ':count Mal · :first bis :last',
        ],

        'split' => [
            'action' => 'Herauslösen',
            'hint' => 'Diese Untergruppe steht danach wieder als eigener Fehler in der '
                .'Liste, mit den Zahlen, die sie beim Zusammenführen hatte.',
        ],

        'error' => [
            'mixed_projects' => 'Zusammenführen geht nur innerhalb eines Projekts.',
            'only_errors' => 'Zusammenführen geht nur bei Fehlern, nicht bei Leistungsproblemen.',
            'already_merged' => 'Mindestens einer der gewählten Fehler ist bereits eine '
                .'Untergruppe. Er muss zuerst herausgelöst werden.',
        ],

        'flash' => [
            'merged' => ':count Fehler zu einem zusammengeführt.',
            'unmerged' => 'Untergruppe wieder herausgelöst.',
        ],
    ],

    'live' => [
        'new_one' => 'Ein neuer Fehler',
        'new_many' => ':count neue Fehler',
        'show' => 'Anzeigen',
    ],

    'environment_ignored' => 'Die gewählte Umgebung schränkt diese Liste nicht ein: '
        .'ein Fehler wird über alle Umgebungen hinweg gezählt. Wer nur die Fehler '
        .'einer Umgebung sehen will, sucht nach environment:production.',

    // Die Detailseite (app/Http/Controllers/IssueDetailController,
    // resources/js/shell/pages/issues/Show.jsx und issues/detail/*).
    'detail' => [

        'help' => 'Diese Seite zeigt eine einzelne Meldung dieses Fehlers mit allem, '
            .'was zur Diagnose gehört: Stacktrace, die letzten Schritte davor und '
            .'den technischen Kontext. Häufigkeit, betroffene Nutzer und die beiden '
            .'Zeitpunkte gelten dagegen für den Fehler insgesamt. Über die '
            .'Schaltflächen oben wechselt man zwischen den Meldungen.',

        'no_event' => 'Zu diesem Fehler liegt keine Meldung mehr vor.',
        'no_event_hint' => 'Die Zähler bleiben stehen; die Einzelmeldungen können '
            .'aufgeräumt worden sein.',

        'nav' => [
            'label' => 'Meldung',
            'newest' => 'Neueste',
            'newer' => 'Neuere',
            'older' => 'Ältere',
            'oldest' => 'Älteste',
        ],

        'header' => [
            'times_seen' => 'Häufigkeit',
            'users_seen' => 'Betroffene',
            'first_seen' => 'Zuerst',
            'last_seen' => 'Zuletzt',
            'status' => 'Zustand',
            'priority' => 'Dringlichkeit',
        ],

        'meta' => [
            'title' => 'Meldung',
            'event_id' => 'Kennung',
            'occurred_at' => 'Aufgetreten',
            'received_at' => 'Eingegangen',
            'level' => 'Schweregrad',
            'platform' => 'Plattform',
            'environment' => 'Umgebung',
            'release' => 'Version',
            'dist' => 'Auslieferung',
            'server_name' => 'Server',
            'transaction' => 'Vorgang',
            'logger' => 'Protokollierer',
            'sdk' => 'SDK',
        ],

        'message' => [
            'title' => 'Meldungstext',
        ],

        'exception' => [
            'title' => 'Stacktrace',
            'caused_by' => 'Verursacht durch',
            'handled' => 'Aufgefangen',
            'unhandled' => 'Nicht aufgefangen',
            'mechanism' => 'Herkunft: :type',
        ],

        'frames' => [
            'empty' => 'Kein Stacktrace übermittelt.',
            'line' => 'Zeile :line',
            'column' => 'Spalte :column',
            'in_app' => 'Eigener Code',
            'unknown_file' => 'Unbekannte Stelle',
            'hidden_one' => 'Ein fremder Rahmen',
            'hidden_many' => ':count fremde Rahmen',
            'show' => 'Einblenden',
            'hide' => 'Ausblenden',
            'vars' => 'Variablen',
            'toggle' => 'Rahmen auf- und zuklappen',
        ],

        'symbolication' => [
            'pending' => 'Stacktrace wird über die Quellkarten zurückübersetzt …',
            'counted' => ':mapped von :total Rahmen zurückübersetzt',
            'show_minified' => 'Minimierte Fassung zeigen',
            'show_source' => 'Originalquelle zeigen',
            'frame_count' => '(:count×)',
            'from' => 'Gemeldet als',
        ],

        'breadcrumbs' => [
            'title' => 'Letzte Schritte',
            'description' => 'Was vor dem Fehler passiert ist — ältester Schritt zuerst.',
            'data' => 'Daten',
        ],

        'sections' => [
            'request' => 'Anfrage',
            'user' => 'Nutzer',
            'contexts' => 'Umgebung',
            'tags' => 'Merkmale',
            'extra' => 'Zusatzdaten',
            'modules' => 'Pakete',
        ],

        'notes' => [
            'title' => 'Diese Meldung wurde gekürzt.',
            'truncated' => 'Gekürzt: :paths',
            'invalid' => 'Verworfen: :paths',
        ],

        'raw' => [
            'title' => 'Rohdaten',
            'description' => 'Die ausgewertete Meldung und daneben das, was das SDK '
                .'geschickt hat.',
            'show' => 'Anzeigen',
            'hide' => 'Verbergen',
            'open' => 'In neuem Tab öffnen',
            'loading' => 'Wird geladen …',
            'failed' => 'Die Rohdaten konnten nicht geladen werden.',
        ],

    ],

];
