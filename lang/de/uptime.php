<?php

// Erreichbarkeits-Überwachung (resources/js/shell/pages/projects/Uptime.jsx,
// App\Http\Controllers\UptimeMonitorController und die Meldungstexte in
// App\Support\Uptime\UptimeAlerts).
return [

    'title' => 'Erreichbarkeit · :project',
    'help' => 'Ein überwachtes Ziel wird in festem Takt von außen aufgerufen. Antwortet es nicht wie erwartet, wird die Anfrage zur Bestätigung wiederholt — erst dann gilt es als Ausfall, es geht eine Meldung raus, und der Vorfall erscheint zusätzlich als Fehler-Eintrag. Geprüft wird ausschließlich im Hintergrund; die Seite selbst ruft nichts auf.',

    'name' => 'Name',
    'name_hint' => 'Nur zur Anzeige — er steht in Meldungen und in der Überschrift des Fehler-Eintrags.',

    'url' => 'Adresse',
    'url_hint' => 'Vollständig mit http:// oder https://. Am besten eine Adresse, die die Anwendung wirklich durchläuft, nicht nur den Webserver.',

    'method' => 'Verfahren',
    'method_hint' => 'GET deckt fast alles ab. HEAD überträgt keinen Rumpf und schließt damit die Inhaltsprüfung aus.',

    'headers' => 'Kopfzeilen',
    'headers_hint' => 'Für Ziele, die etwas erwarten — ein Token, eine Sprache, einen eigenen Kopf. Leere Zeilen werden verworfen.',
    'header_name' => 'Name',
    'header_value' => 'Wert',
    'header_add' => 'Kopfzeile hinzufügen',
    'header_remove' => 'Entfernen',

    'body' => 'Nutzlast',
    'body_hint' => 'Nur bei POST, PUT und PATCH. Ohne eigene Content-Type-Kopfzeile wird sie als JSON geschickt.',

    'expected_status_codes' => 'Erwartete Statuscodes',
    'expected_status_codes_hint' => 'Bereiche und Einzelwerte, mit Komma getrennt: „200-299" oder „200-299,301". Alles andere gilt als Ausfall.',

    'expected_content' => 'Erwarteter Text',
    'expected_content_hint' => 'Kommt dieser Text nicht im Rumpf vor, gilt das Ziel als ausgefallen. Der Unterschied zwischen „der Webserver antwortet" und „die Anwendung läuft" — eine Fehlerseite mit Status 200 ist der Ausfall, den eine reine Statusprüfung übersieht.',

    'interval' => 'Takt (Sekunden)',
    'interval_hint' => 'Mindestens 60 Sekunden — feiner kann der Zeitplan der Anwendung nicht auslösen.',
    'timeout' => 'Zeitgrenze (Sekunden)',
    'timeout_hint' => 'So lange wird auf eine Antwort gewartet. Danach gilt die Anfrage als Zeitüberschreitung.',

    'confirmation_retries' => 'Bestätigungsversuche',
    'confirmation_retries_hint' => 'So oft wird eine gescheiterte Anfrage sofort wiederholt, bevor sie zählt. 0 heißt: keine Bestätigung — dann meldet jeder Aussetzer einen Ausfall.',
    'confirmation_delay' => 'Wartezeit vor der Bestätigung (Sekunden)',
    'confirmation_delay_hint' => 'Sofort noch einmal zu fragen trifft dieselbe halb offene Verbindung. Ein paar Sekunden Abstand sind der halbe Zweck.',

    'failure_threshold' => 'Ausfall nach … Fehlschlägen',
    'failure_threshold_hint' => 'Erst nach so vielen gescheiterten Prüfungen in Folge beginnt ein Ausfall. 1 heißt: sofort nach der bestätigten Prüfung.',
    'recovery_threshold' => 'Entwarnung nach … Erfolgen',
    'recovery_threshold_hint' => 'So viele erfolgreiche Prüfungen in Folge, bevor der Vorfall geschlossen wird. Höher stellen bei Zielen, die pendeln.',

    'follow_redirects' => 'Weiterleitungen folgen',
    'verify_tls' => 'Zertifikat prüfen',
    'verify_tls_hint' => 'Ein abgelaufenes Zertifikat ist ein Ausfall. Nur für interne Ziele mit eigener Zertifizierungsstelle abschalten.',

    'active' => 'Überwachung aktiv',
    'save' => 'Speichern',
    'disable' => 'Überwachung abschalten',
    'enable' => 'Überwachung einschalten',
    'delete' => 'Löschen',
    'disable_hint' => 'Abgeschaltet bleibt alles stehen, es wird nur nicht mehr geprüft — der Weg für eine geplante Wartung.',

    'settings' => 'Einstellungen',

    'facts' => [
        'url' => 'Ziel',
        'interval' => 'Takt',
        'interval_value' => 'alle :seconds s',
        'last_checked' => 'Zuletzt geprüft',
        'next_check' => 'Nächste Prüfung',
        'never' => 'noch nie',
        'average' => 'Antwortzeit (24 h)',
        'failures' => 'Fehlschläge in Folge',
        'failures_value' => ':count von :threshold',
    ],

    'availability' => [
        'title' => 'Verfügbarkeit',
        'day' => '24 Stunden',
        'week' => '7 Tage',
        'month' => '30 Tage',
        'none' => 'keine Messung',
        'checks' => ':count Prüfungen, :failures davon gescheitert',
    ],

    'response_times' => [
        'title' => 'Antwortzeiten',
        'empty' => 'Noch keine Messung.',
        'summary' => ':count Messungen, :from bis :to',
    ],

    'outages' => [
        'title' => 'Ausfälle (:count)',
        'empty' => 'Kein Ausfall aufgezeichnet.',
        'reason' => 'Grund',
        'started' => 'Beginn',
        'ended' => 'Ende',
        'duration' => 'Dauer',
        'running' => 'läuft noch',
        'issue' => 'Fehler-Eintrag',
        'open_issue' => 'öffnen',
        'checks' => ':count gescheiterte Prüfungen',
    ],

    'empty' => [
        'title' => 'Noch kein überwachtes Ziel',
        'description' => 'Trage eine Adresse ein, die von außen erreichbar sein soll. Ein Totalausfall erzeugt keine Fehlermeldung — es läuft dann nichts mehr, was eine schicken könnte.',
    ],

    'create' => [
        'title' => 'Ziel überwachen',
        'description' => 'Takt, Erwartung und Bestätigung bestimmen, wann ein Ausfall auffällt und ab wann er gemeldet wird.',
        'submit' => 'Überwachung anlegen',
    ],

    'validation' => [
        'status_codes' => 'Das sind keine gültigen Statuscodes. Erlaubt sind dreistellige Zahlen und Bereiche, mit Komma getrennt — etwa „200-299,301".',
        'timeout_fits_interval' => 'Zeitgrenze und Bestätigung ergeben zusammen bis zu :seconds Sekunden und passen damit nicht in den Takt. Kürzer stellen oder den Takt vergrößern.',
        'content_needs_body' => 'Ein HEAD überträgt keinen Rumpf — eine Inhaltsprüfung würde bei jedem Lauf scheitern.',
    ],

    'flash' => [
        'created' => 'Ziel „:name" wird überwacht.',
        'updated' => 'Ziel „:name" gespeichert.',
        'enabled' => 'Überwachung von „:name" ist wieder aktiv.',
        'disabled' => 'Überwachung von „:name" ist abgeschaltet.',
        'deleted' => 'Überwachung von „:name" gelöscht.',
    ],

    // Fehlertexte der Prüfung selbst (App\Support\Uptime\UptimeProbe). Sie
    // stehen im Verlauf, am Ausfall und in der Meldung.
    'probe' => [
        'status_mismatch' => 'Das Ziel hat mit HTTP :status geantwortet; erwartet war :expected.',
        'content_mismatch' => 'Der erwartete Text „:text" kam in der Antwort nicht vor.',
    ],

    // Überschrift des Fehler-Eintrags, den ein Ausfall erzeugt
    // (App\Support\Uptime\UptimeIssues).
    'issue' => [
        'title' => 'Nicht erreichbar: :monitor',
    ],

    // Alarm- und Entwarnungstexte (App\Support\Uptime\UptimeAlerts). Sie gehen
    // über die Kanäle der Organisation raus und werden auch außerhalb der
    // Oberfläche gelesen — deshalb nennen sie Projekt und Ziel beim Namen.
    'alert' => [
        'title' => 'Nicht erreichbar: :monitor',
        'body' => 'Das Ziel „:monitor" im Projekt „:project" ist nicht erreichbar (:reason). Geprüft wurde :url.',
        'context_project' => 'Projekt',
        'context_url' => 'Adresse',
        'context_reason' => 'Grund',
        'context_started' => 'Beginn',
        'context_status' => 'Statuscode',
        'context_error' => 'Meldung',
        'context_duration' => 'Dauer',
    ],

    'recovery' => [
        'title' => 'Wieder erreichbar: :monitor',
        'body' => 'Das Ziel „:monitor" im Projekt „:project" antwortet wieder. Der Ausfall dauerte :duration.',
    ],

];
