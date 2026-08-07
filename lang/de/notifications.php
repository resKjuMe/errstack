<?php

// Benachrichtigungen: Kanäle einer Organisation, persönliche Einstellungen und
// das Abbestellen aus einer Mail heraus
// (resources/js/shell/pages/notifications, App\Http\Controllers\Notification*).
return [

    'channels' => [
        'title' => 'Benachrichtigungen',
        'help' => 'Jeder Kanal geht an ein eigenes Ziel. „Testnachricht senden" schickt eine echte Meldung auf demselben Weg — das Ergebnis steht im Protokoll. Der Aufbau der Webhook-Unterschrift steht in :docs.',

        'new_title' => 'Neuer Kanal',
        'new_description' => 'Wohin Errstack melden soll. Zugangsdaten liegen verschlüsselt und werden nie wieder angezeigt.',
        'channel' => 'Kanal',
        'name' => 'Name',
        'name_placeholder' => 'Bereitschaft',
        'create' => 'Kanal einrichten',

        'list_title' => 'Kanäle',
        'list_description' => 'Eingerichtete Wege dieser Organisation.',
        'empty' => 'Noch kein Kanal eingerichtet — Meldungen bleiben in Errstack.',
        'inactive' => '(abgeschaltet)',
        'test' => 'Testnachricht senden',
        'edit' => 'Bearbeiten',
        'close' => 'Schließen',
        'delete' => 'Löschen',
        'secrets_hint' => 'Zugangsdaten bleiben unverändert, solange das Feld leer bleibt.',
        'active' => 'Kanal ist aktiv',
        'unknown' => 'Unbekannter Kanal',
        'save' => 'Speichern',
    ],

    'deliveries' => [
        'title' => 'Zustellprotokoll',
        'description' => 'Jeder Versuch mit Ergebnis. Fehlgeschlagene wiederholt die Warteschlange automatisch; danach hilft „Erneut versuchen".',
        'empty' => 'Noch nichts zugestellt.',
        'test' => '(Test)',
        'attempt' => '1 Versuch',
        'attempts' => ':count Versuche',
        'retry' => 'Erneut versuchen',
        'channel_off' => 'Der Kanal ist abgeschaltet.',
    ],

    'preferences' => [
        'title' => 'Benachrichtigungen',
        'help' => 'Lege je Anlass fest, auf welchem Weg du informiert wirst. Eine Einstellung für ein Projekt schlägt die der Organisation, und die schlägt „Überall". Kritische Alarme erreichen dich auch in der Ruhezeit und nach einer pauschalen Abmeldung — abschalten lassen sie sich nur hier, ausdrücklich.',

        'scope_title' => 'Bereich',
        'scope_description' => 'Je feiner der Bereich, desto stärker schlägt er durch.',
        'scope_project' => 'Projekt: :name',
        'scope_organization' => 'Organisation: :name',
        'scope_global' => 'Überall',
        'scope_global_hint' => 'Gilt, solange eine Organisation oder ein Projekt nichts Eigenes sagt.',
        'scope_organization_hint' => 'Weicht von „Überall" ab, wo hier etwas eingestellt ist.',
        'scope_project_hint' => 'Die feinste Ebene — sie schlägt Organisation und „Überall".',

        'matrix_description' => 'Ein Anlass je Zeile, ein Weg je Spalte.',
        'event_column' => 'Anlass',
        'critical' => 'kritisch',
        'choice_inherit' => 'Erbt',
        'choice_on' => 'An',
        'choice_off' => 'Aus',
        'effective_on' => 'wirksam: an',
        'effective_off' => 'wirksam: aus',
        'cell_label' => ':event — :transport',
        'save' => 'Speichern',
        'saved' => 'Gespeichert.',

        'critical_warning' => 'Kritische Alarme erreichen dich nicht überall.',
        'critical_entry' => ':event — :scope: kein einziger Weg aktiv.',

        'quiet_title' => 'Ruhezeiten',
        'quiet_description' => 'In dieser Spanne bleibt es still. Kritische Alarme kommen trotzdem an.',
        'quiet_active' => 'Gerade ist Ruhezeit — wieder ab :time Uhr.',
        'quiet_enabled' => 'Ruhezeiten einhalten',
        'quiet_from' => 'Von',
        'quiet_until' => 'Bis',
        'timezone' => 'Zeitzone',

        'unsubscribed_title' => 'Pauschal abbestellt',
        'unsubscribed_description' => 'Seit :date. Kritische Alarme erreichen dich weiterhin.',
        'resubscribe' => 'Wieder alles erhalten',
        'unsubscribe_title' => 'Alles abbestellen',
        'unsubscribe_description' => 'Schaltet alle nicht-kritischen Benachrichtigungen ab — auf einen Schlag und ohne die einzelnen Einstellungen zu verlieren.',
        'unsubscribe' => 'Alles abbestellen',
    ],

    'unsubscribe' => [
        'title' => 'Abbestellen',
        'heading' => 'Benachrichtigungen abbestellen',
        'recipient' => 'Für :email.',
        'event_off' => 'Keine E-Mails mehr zu „:event"',
        'event_off_done' => 'Ist bereits abgeschaltet.',
        'event_off_hint' => 'Wirkt sofort — auch für Mails, die schon in der Warteschlange stehen.',
        'all_off' => 'Alles abbestellen',
        'all_off_done' => 'Ist bereits pauschal abbestellt.',
        'all_off_hint' => 'Schaltet alle nicht-kritischen Benachrichtigungen ab. Kritische Alarme kommen weiterhin an.',
        'settings_link' => 'Alle Einstellungen öffnen',
        'critical_title' => '„:event" ist ein kritischer Alarm.',
        'critical_body_before' => 'Er erreicht dich auch in der Ruhezeit und nach einer pauschalen Abmeldung. Abschalten lässt er sich nur ausdrücklich in den',
        'critical_link' => 'Benachrichtigungs-Einstellungen',
        'unknown_event' => 'Unbekannter Anlass: :event',
    ],

    'flash' => [
        'channel_created' => 'Kanal „:name" eingerichtet.',
        'channel_updated' => 'Kanal „:name" gespeichert.',
        'channel_deleted' => 'Kanal „:name" gelöscht.',
        'channel_tested' => 'Testnachricht an „:name" eingereiht. Das Ergebnis steht gleich im Protokoll.',
        'delivery_not_failed' => 'Diese Zustellung ist nicht fehlgeschlagen — es gibt nichts zu wiederholen.',
        'delivery_retried' => 'Zustellung erneut eingereiht.',
        'preferences_saved' => 'Benachrichtigungen gespeichert.',
        'quiet_hours_saved' => 'Ruhezeiten gespeichert.',
        'unsubscribed' => 'Alles abbestellt. Kritische Alarme kommen weiterhin an.',
        'resubscribed' => 'Benachrichtigungen wieder eingeschaltet.',
        'unsubscribed_all' => 'Abgemeldet. Kritische Alarme kommen weiterhin an — alles andere nicht mehr.',
        'unsubscribed_event' => 'Keine E-Mails mehr zu „:event".',
    ],

];
