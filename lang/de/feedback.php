<?php

// Nutzer-Rückmeldungen (app/Http/Controllers/UserReportController,
// app/Support/Feedback, resources/js/shell/pages/feedback).
return [

    'title' => 'Rückmeldungen',
    'help' => 'Was betroffene Personen selbst beschrieben haben — als Absturzbericht '
        .'zu einem gemeldeten Fehler oder als freie Zuschrift über das '
        .'Feedback-Widget. Eine Rückmeldung ist die einzige Angabe hier, die '
        .'niemand nachträglich messen kann; sie zählt deshalb auch nicht gegen '
        .'das Ereignis-Kontingent.',

    'list' => [
        'empty' => 'Keine Rückmeldungen im gewählten Zeitraum.',
        'empty_hint' => 'Rückmeldungen kommen über das SDK („beschreiben Sie, was '
            .'passiert ist") oder über das mitgelieferte Widget herein.',
        'count' => ':count Rückmeldungen',
        'anonymous' => 'Ohne Namen',
        'no_email' => 'Ohne Adresse',
        'received' => 'Eingegangen: :value',
        'unlinked' => 'Ereignis :reference — noch nicht zugeordnet',
        'unlinked_hint' => 'Die genannte Ereignisnummer ist bekannt, das Ereignis dazu '
            .'aber nicht (mehr): es kann ausgesiebt worden sein, noch in der '
            .'Verarbeitung stecken oder abgelaufen sein.',
        'show_event' => 'Gemeldetes Ereignis',
    ],

    'filter' => [
        'status' => 'Bearbeitungsstand',
        'any_status' => 'Alle Stände',
        'assignee' => 'Zuweisung',
        'any_assignee' => 'Alle',
        'assigned_to_me' => 'Bei mir',
        'unassigned' => 'Noch niemandem',
    ],

    'assign' => [
        'label' => 'Zuweisen an',
        'nobody' => 'Niemandem',
    ],

    'status' => [
        'label' => 'Stand',
    ],

    'flash' => [
        'status_changed' => 'Stand geändert auf „:status".',
        'assigned' => 'Rückmeldung an :name übergeben.',
        'unassigned' => 'Zuweisung aufgehoben.',
    ],

    'errors' => [
        'not_a_member' => 'Diese Person gehört nicht zur Organisation der Rückmeldung.',
    ],

    'notification' => [
        'title' => 'Neue Rückmeldung in :project',
        'assigned_title' => 'Rückmeldung in :project liegt bei dir',
        'context_project' => 'Projekt',
        'context_kind' => 'Art',
        'context_name' => 'Name',
        'context_email' => 'E-Mail',
        'context_url' => 'Seite',
    ],

    'environment_ignored' => 'Die gewählte Umgebung wirkt hier nicht: eine Rückmeldung '
        .'schreibt ein Mensch und keine Umgebung.',

];
