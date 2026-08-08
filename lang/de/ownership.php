<?php

// Zuständigkeits-Regeln (resources/js/shell/pages/projects/Ownership.jsx,
// App\Http\Controllers\OwnershipRuleController). Die Beschriftung der
// Regelarten steht wie alle Aufzählungen in enums.php.
return [

    'title' => 'Zuständigkeit · :project',
    'help' => 'Wer sich um einen Fehler kümmert, hängt in aller Regel davon ab, wo er passiert ist. Diese Liste schreibt das einmal auf: ein Muster auf den Pfad, die Adresse, das Modul oder ein Merkmal, dazu die Zuständigen. Wer eine CODEOWNERS-Datei hat, übernimmt sie unverändert.',

    'rules' => [
        'title' => 'Regeln',
        'description' => 'Von oben nach unten ausgewertet. Bei mehreren Treffern gewinnt die zuletzt passende Regel.',
        'empty' => 'Noch keine Regel — Fehler bleiben ohne Zuständigen, bis jemand sie von Hand zuweist.',
        'last_wins_hint' => 'Die letzte zutreffende Regel gewinnt — wie in einer CODEOWNERS-Datei. Deshalb steht das Allgemeine oben und die Ausnahme unten: eine neue Zeile am Ende ist die Ausnahme von allem darüber.',
        'not_retroactive_hint' => 'Eine Regel gilt ab dem nächsten neuen Fehler und verteilt keine bestehenden. Für die schlägt sie im Zuweisungs-Dialog nur vor; wer sie in großer Zahl verteilen will, nimmt die Sammelaktion der Fehlerliste.',
        'inactive_badge' => 'abgeschaltet',
        'from_codeowners' => 'aus CODEOWNERS',
        'position' => 'Rang :position',
    ],

    // Die Begründung, die ein Vorschlag im Zuweisungs-Dialog mitbringt (R6).
    'suggestion' => 'Regel :pattern',

    'matcher' => [
        'path' => 'Pfad',
        'url' => 'Adresse',
        'module' => 'Modul',
        'tag' => 'Merkmal',
    ],

    'fields' => [
        'matcher' => 'Bezieht sich auf',
        'tag_key' => 'Merkmal',
        'pattern' => 'Muster',
        'position' => 'Rang',
        'owners' => 'Zuständig',
        'owners_placeholder' => "#Kasse\nanna@example.com",
        'owners_hint' => 'Eine Zeile je Zuständigem, höchstens :max. Ein Team mit Rautenzeichen davor (#Kasse), eine Person über ihre E-Mail-Adresse. Zugewiesen wird der erste, der sich auflösen lässt; vorgeschlagen werden alle.',
    ],

    'auto' => [
        'title' => 'Automatisch zuweisen',
        'description' => 'Ob aus einem Vorschlag eine Zuweisung wird.',
        'label' => 'Neue Fehler nach diesen Regeln zuweisen',
        'hint' => 'Betrifft nur Fehler, die neu entstehen, und nur solche ohne Zuständigen — eine von Hand gesetzte Zuständigkeit wird nie überschrieben. Ist der Schalter aus, stehen die Regeln trotzdem als Vorschlag im Zuweisungs-Dialog.',
    ],

    'preview' => [
        'title' => 'Vorschau',
        'description' => 'Wer wäre für so ein Ereignis zuständig?',
        'submit' => 'Prüfen',
        'tag_key' => 'Merkmal',
        'tag_value' => 'Wert des Merkmals',
        'placeholder' => [
            'path' => 'src/billing/Invoice.php',
            'url' => 'https://example.com/checkout/summe',
            'module' => 'com.acme.billing.Invoice',
            'tag_key' => 'server_name',
            'tag_value' => 'web-01',
        ],
        'nothing_given' => 'Nichts angegeben — ohne Pfad, Adresse, Modul oder Merkmal kann keine Regel zutreffen.',
        'nobody' => 'Keine Regel trifft zu, oder ihre Zuständigen gibt es in dieser Organisation nicht (mehr). Der Fehler bliebe ohne Zuständigen.',
        'would_assign' => 'Zugewiesen würde: :assignee',
        'would_suggest' => 'Vorgeschlagen würde: :assignee — zugewiesen wird nichts, solange die automatische Zuweisung aus ist.',
        'winner' => 'gilt',
    ],

    'import' => [
        'title' => 'CODEOWNERS übernehmen',
        'description' => 'Die Datei einfügen; jede brauchbare Zeile wird zu einer Pfad-Regel.',
        'label' => 'Inhalt der Datei',
        'placeholder' => "# Kommentare und Leerzeilen werden übergangen\n/src/billing/  @acme/kasse\n*.tsx          anna@example.com",
        'hint' => 'Die Zeilen kommen ans Ende der Liste und überstimmen damit alles darüber — dieselbe Auflösung wie in der Datei. Übersprungen wird, wessen Zuständige es hier nicht gibt: eine Regel, die niemanden benennt, sähe vollständig aus und wäre es nicht.',
        'submit' => 'Übernehmen',
    ],

    'create' => [
        'title' => 'Regel anlegen',
        'description' => 'Neue Regeln kommen ans Ende und überstimmen damit alles darüber.',
        'submit' => 'Anlegen',
    ],

    'save' => 'Speichern',
    'enable' => 'Einschalten',
    'disable' => 'Abschalten',
    'delete' => 'Löschen',

    'flash' => [
        'created' => 'Regel :pattern angelegt.',
        'updated' => 'Regel :pattern gespeichert.',
        'enabled' => 'Regel :pattern eingeschaltet.',
        'disabled' => 'Regel :pattern abgeschaltet.',
        'deleted' => 'Regel :pattern gelöscht.',
        'imported' => ':count Regeln übernommen, :skipped Zeilen übersprungen.',
        'auto_on' => 'Neue Fehler werden ab jetzt nach diesen Regeln zugewiesen.',
        'auto_off' => 'Automatische Zuweisung abgeschaltet — die Regeln schlagen weiterhin vor.',
    ],

    'validation' => [
        'too_many' => 'Ein Projekt darf höchstens :max Zuständigkeits-Regeln haben.',
        'tag_key_required' => 'Zu einem Merkmal gehört sein Name — sonst könnte die Regel nie zutreffen.',
        'owner_invalid' => 'Das bezeichnet niemand Bestimmtes. Ein Team mit Rautenzeichen davor (#Kasse) oder eine Person über ihre E-Mail-Adresse.',
    ],

];
