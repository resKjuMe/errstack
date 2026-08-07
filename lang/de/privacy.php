<?php

// Datenschutz-Seiten (resources/js/shell/pages/privacy) und die Meldungen der
// zugehörigen Controller.
return [

    'title' => 'Datenschutz — :name',
    'help' => 'Was hier eingestellt ist, greift beim Eingang einer Meldung — bevor irgendetwas gespeichert wird. Entfernte Werte werden durch einen Hinweis ersetzt, damit später erkennbar bleibt, dass an dieser Stelle etwas stand.',

    'scope' => [
        'organization' => 'Diese Regeln gelten für alle Projekte der Organisation.',
        'project' => 'Diese Regeln gelten nur für dieses Projekt. Die Regeln der Organisation gelten zusätzlich.',
    ],

    'options' => [
        'title' => 'Was gar nicht gespeichert wird',
        'description' => 'Ganze Arten von Angaben abschalten. Wirkt ab der nächsten eingehenden Meldung; bereits gespeicherte Ereignisse bleiben unberührt.',
        'read_only' => 'Ändern darf sie die Verwaltung der Organisation.',
        'ip' => 'IP-Adressen nicht speichern',
        'ip_hint' => 'Entfernt die Adresse überall, wo sie steht: am Betroffenen, in der Server-Umgebung und in den Kopfzeilen eines Proxys. Damit lässt sich nicht mehr zählen, wie viele verschiedene Personen ein Fehler getroffen hat.',
        'user' => 'Nutzerdaten nicht speichern',
        'user_hint' => 'Entfernt den ganzen Abschnitt zur betroffenen Person — auch Angaben, die das SDK zusätzlich mitgibt.',
        'attachments' => 'Anhänge nicht speichern',
        'attachments_hint' => 'Verwirft Screenshots, Logdateien und Aufzeichnungen bei der Aufnahme. An einer Datei lässt sich nichts schwärzen: sie ist entweder unbedenklich oder gar nicht.',
        'submit' => 'Speichern',
    ],

    'rules' => [
        'title' => 'Eigene Regeln',
        'description' => 'Für alles, was nur die eigene Anwendung weiß — eine Kundennummer, eine interne Kennung, ein Feld mit einer Personalnummer.',
        'empty' => 'Noch keine eigenen Regeln. Die Standardfelder greifen trotzdem.',
        'type' => 'Art',
        'expression' => 'Feldname oder Muster',
        'expression_hint_field' => 'Groß- und Kleinschreibung ist gleichgültig. „*" steht für beliebig viele Zeichen: „kunden_*" trifft „kunden_id" und „kunden_name".',
        'expression_hint_pattern' => 'Ein regulärer Ausdruck, der im Wert gesucht wird. Ersetzt wird der Treffer, nicht das ganze Feld.',
        'path' => 'Nur in diesem Abschnitt',
        'path_hint' => 'Ein Weg in der Meldung, z. B. „request.data" oder „extra". Leer heißt: in der ganzen Meldung.',
        'path_placeholder' => 'ganze Meldung',
        'active' => 'Aktiv',
        'inactive' => 'ausgeschaltet',
        'add' => 'Regel hinzufügen',
        'save' => 'Speichern',
        'delete' => 'Löschen',
    ],

    'inherited' => [
        'title' => 'Regeln der Organisation',
        'description' => 'Sie gelten hier mit und werden bei der Organisation gepflegt.',
        'manage' => 'Bei der Organisation ändern',
    ],

    'defaults' => [
        'title' => 'Immer entfernt',
        'description' => 'Diese Felder und Muster verschwinden ohne jede Einstellung — auch bei einem frisch angelegten Projekt.',
        'marker' => 'An ihrer Stelle steht danach „:marker".',
        'fields' => 'Feldnamen',
        'patterns' => 'Muster im Wert',
        'show' => 'Standardregeln ansehen (:count)',
    ],

    'preview' => [
        'title' => 'Vorschau',
        'description' => 'Zeigt an einem Beispielereignis, was die geltenden Regeln entfernen würden. Das Beispiel lässt sich ändern — eine echte Meldung wird dafür nicht gelesen.',
        'sample' => 'Beispielereignis (JSON)',
        'submit' => 'Vorschau berechnen',
        'result' => 'Ergebnis',
        'removed' => 'Entfernt (:count)',
        'removed_none' => 'Keine Regel hat gegriffen.',
        'truncated' => 'Weitere Treffer werden nicht einzeln aufgeführt.',
    ],

    'validation' => [
        'expression' => 'Der Ausdruck lässt sich nicht auswerten. Bei einem Muster muss er ein gültiger regulärer Ausdruck sein.',
        'path' => 'Der Abschnitt darf nur Buchstaben, Zahlen, Punkte, Binde- und Unterstriche enthalten.',
        'sample' => 'Das Beispiel muss ein JSON-Objekt sein.',
    ],

    'flash' => [
        'options_updated' => 'Datenschutz-Einstellungen gespeichert.',
        'rule_created' => 'Regel angelegt.',
        'rule_updated' => 'Regel gespeichert.',
        'rule_deleted' => 'Regel gelöscht.',
    ],

];
