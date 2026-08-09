<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Suchsprache
    |--------------------------------------------------------------------------
    |
    | Die Meldungen zeigen, **wo** es klemmt, und sagen nach Möglichkeit, was
    | stattdessen dastehen müsste. Eine Suchsprache, die auf jede Unstimmigkeit
    | „ungültige Eingabe" antwortet, zwingt zum Raten — und geraten wird dann am
    | Ende doch nur, bis das Feld leer ist.
    |
    */

    'errors' => [
        'missing_field' => 'Vor dem Doppelpunkt fehlt ein Feldname.',
        'unclosed_quote' => 'Das Anführungszeichen wird nicht wieder geschlossen.',
        'unopened_paren' => 'Diese Klammer wird nicht geöffnet.',
        'unclosed_paren' => 'Diese Klammer wird nicht geschlossen.',
        'empty_paren' => 'In der Klammer steht nichts.',
        'empty_term' => 'Hier steht ein leerer Suchbegriff.',
        'unexpected' => '„:term" steht hier ohne Zusammenhang.',
        'unexpected_end' => 'Nach „:term" fehlt noch ein Begriff.',
        'too_deep' => 'Zu viele Klammern ineinander.',
        'missing_value' => 'Nach „:field:" fehlt ein Wert.',
        'missing_comparison_value' => 'Nach „:comparator" fehlt eine Angabe für :field.',
        'unknown_value' => ':field kennt „:value" nicht. Möglich sind: :allowed.',
        'not_a_number' => ':field erwartet eine Zahl, nicht „:value".',
        'not_a_date' => ':field erwartet ein Datum wie 2026-03-01, 2026-03-01 14:30 '
            .'oder eine Angabe wie -24h — nicht „:value".',
        'relative_with_comparison' => 'Eine Angabe wie -24h sagt die Richtung schon selbst; '
            .'ein Vergleich davor geht bei :field nicht.',
        'no_comparison' => 'Bei :field lässt sich nicht mit „:comparator" vergleichen.',
        'unknown_assignee' => ':field kennt „:value" nicht — kein Konto und kein Team '
            .'dieser Organisation trägt diesen Namen. Möglich sind me, none, eine '
            .'E-Mail-Adresse oder #Team.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Felder
    |--------------------------------------------------------------------------
    |
    | Der Halbsatz, der im Vorschlagsfeld hinter einem Feldnamen steht. Er
    | beantwortet die Frage „und was steht da rein?", ohne dass man eine
    | Anleitung aufschlagen muss.
    |
    */

    'fields' => [
        'is' => 'Zustand: unresolved, resolved, ignored, assigned, unassigned, for_review',
        'level' => 'Grad: fatal, error, warning, info, debug',
        'priority' => 'Dringlichkeit: high, medium, low',
        'timesseen' => 'Häufigkeit, auch als Vergleich: >100',
        'usersseen' => 'Betroffene, auch als Vergleich: >10',
        'firstseen' => 'Zuerst gesehen: 2026-03-01 oder -24h',
        'lastseen' => 'Zuletzt gesehen: 2026-03-01 oder -24h',
        'release' => 'Version, in der er zuerst oder zuletzt auftrat',
        'firstrelease' => 'Version, in der er zum ersten Mal auftrat',
        'resolvedinrelease' => 'Version, in der er erledigt wurde',
        'regressedinrelease' => 'Version, mit der er zurückkam',
        'assigned' => 'Zuständig: me, none, eine E-Mail-Adresse oder #Team',
        'bookmarks' => 'Gemerkte Fehler (kommt mit einer späteren Aufgabe)',
        'user' => [
            'email' => 'E-Mail eines Betroffenen (durchsucht die Meldungen)',
            'id' => 'Kennung eines Betroffenen (durchsucht die Meldungen)',
            'username' => 'Benutzername eines Betroffenen (durchsucht die Meldungen)',
            'ip' => 'IP-Adresse eines Betroffenen (durchsucht die Meldungen)',
        ],
    ],

];
