<?php

// Beschriftungen der Eingangsfilter (app/Support/InboundFilterData,
// resources/js/shell/pages/projects/Filters.jsx). Nicht zu verwechseln mit
// `filters.php` — das ist die globale Filterleiste der Auswertungsseiten.
return [

    'title' => 'Eingangsfilter · :project',
    'help' => 'Was hier gefiltert wird, kommt gar nicht erst in die Fehlerliste. Gezählt wird es trotzdem.',

    'options' => [
        'title' => 'Filterarten',
        'description' => 'Was beim Eingang verworfen wird, statt gespeichert zu werden.',
        'read_only' => 'Ändern darf das die Verwaltung.',
        'counted' => 'In den letzten :days Tagen gefiltert: :count Ereignisse.',
        'filtered' => ':count gefiltert',
        'submit' => 'Filter speichern',
    ],

    'kinds' => [
        'browser_extension_hint' => 'Fehler aus einer Browser-Erweiterung des Besuchers — erkennbar an der Herkunft der Datei oder am Fehlertext.',
        'legacy_browser_hint' => 'Fehler aus Browsern, die zu alt sind, um sie noch zu bedienen. Die Grenzen stehen unten.',
        'localhost_hint' => 'Meldungen aus der lokalen Entwicklung (localhost, .test, .localhost).',
        'crawler_hint' => 'Meldungen, die ein Suchmaschinen-Roboter oder ein Prüfwerkzeug ausgelöst hat.',
        'message_pattern_hint' => 'Fehlermeldungen, deren Text auf eines der Muster unten passt.',
        'ip_address_hint' => 'Meldungen von den unten gesperrten Adressen oder Netzen. Greift nur, wenn die Meldung eine Adresse mitbringt — Browser-SDKs überlassen sie dem Server und schicken keine.',
        'release_hint' => 'Meldungen aus den unten gesperrten Releases.',
    ],

    'rules' => [
        'title' => 'Liste: :kind',
        'kind' => 'Filterart',
        'expression' => 'Eintrag',
        'empty' => 'Noch kein Eintrag — der Filter greift damit nicht.',
        'disabled_hint' => 'Diese Filterart ist abgeschaltet. Die Einträge bleiben erhalten, wirken aber nicht.',
        'inactive_badge' => 'stillgelegt',
        'browser_defaults' => 'Ohne eigenen Eintrag gelten diese Grenzen:',
        'create' => 'Hinzufügen',
        'save' => 'Speichern',
        'enable' => 'Wieder einschalten',
        'disable' => 'Stilllegen',
        'delete' => 'Löschen',

        'legacy_browser_description' => 'Je Zeile ein Browser: „safari:6" filtert alles unter Fassung 6, „ie" jede Fassung. Der Name muss dem entsprechen, den das SDK meldet.',
        'message_pattern_description' => 'Verglichen wird mit dem ganzen Fehlertext, „*" steht für beliebig viele Zeichen. Für einen Teiltreffer also „*Text*".',
        'ip_address_description' => 'Eine Adresse oder ein Netz in CIDR-Schreibweise. Verglichen wird die Adresse des Betroffenen und die des Webservers — weitergereichte Kopfzeilen zählen nicht, weil sie frei wählbar sind.',
        'release_description' => 'Der Name des Releases, „*" steht für beliebig viele Zeichen.',

        'legacy_browser_placeholder' => 'safari:6',
        'message_pattern_placeholder' => '*ResizeObserver loop*',
        'ip_address_placeholder' => '203.0.113.0/24',
        'release_placeholder' => '1.4.*',
    ],

    'known' => [
        'title' => 'Als lokal erkannt',
        'description' => 'Diese Rechnernamen gelten als lokale Entwicklung — eingebaut und nicht einstellbar.',
    ],

    'validation' => [
        'address' => 'Das ist keine gültige Adresse und kein gültiges Netz.',
        'browser' => 'Erwartet wird „browser" oder „browser:fassung", etwa „safari:6".',
        'too_broad' => 'Ein Eintrag aus lauter Platzhaltern trifft jede Meldung.',
        'too_many' => 'Mehr als :max Einträge je Filterart sind nicht vorgesehen.',
    ],

    'flash' => [
        'options_updated' => 'Die Filter wurden gespeichert.',
        'rule_created' => 'Eintrag „:expression" hinzugefügt.',
        'rule_updated' => 'Eintrag „:expression" geändert.',
        'rule_enabled' => 'Eintrag „:expression" wieder eingeschaltet.',
        'rule_disabled' => 'Eintrag „:expression" stillgelegt.',
        'rule_deleted' => 'Eintrag „:expression" gelöscht.',
    ],

];
