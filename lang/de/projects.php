<?php

// Projektseiten (resources/js/shell/pages/projects) und die Meldungen der
// zugehörigen Controller.
return [

    'index' => [
        'title' => 'Projekte',
        'help' => 'Ein Projekt steht für genau eine überwachte Anwendung. Fehlermeldungen kommen später über den Sicherheits-Token des Projekts herein; die Plattform bestimmt, welches SDK dafür eingerichtet wird.',
        'no_organization_title' => 'Noch keine Organisation',
        'no_organization_description' => 'Projekte gehören immer zu einer Organisation. Lege zuerst eine an.',
        'to_organizations' => 'Zu den Organisationen',
        'empty_title' => 'Noch keine Projekte',
        'empty_can_create' => 'Lege eines an, um Fehlermeldungen einsortieren zu können.',
        'empty_read_only' => 'Die Verwaltung dieser Organisation legt Projekte an.',
    ],

    'list' => [
        'overview' => 'Übersicht',
    ],

    'create' => [
        'title' => 'Neues Projekt',
        'description' => 'Wird in „:organization" angelegt. Die Einstellungen lassen sich danach ändern.',
        'name' => 'Name',
        'platform' => 'Plattform',
        'submit' => 'Anlegen',
    ],

    'show' => [
        'help' => 'Die Einstellungen wirken auf alles, was für dieses Projekt aufgenommen wird: die Umgebung ist der Standard für Meldungen ohne eigene Angabe, das Auflösungs-Verhalten schließt ruhige Issues von selbst, und die Aufbewahrung bestimmt, wie lange Ereignisse erhalten bleiben.',
        'all_projects' => 'Alle Projekte',
    ],

    'settings' => [
        'title' => 'Einstellungen',
        'description' => 'Der Slug in der Adresszeile bleibt beim Umbenennen unverändert, damit verteilte Links gültig bleiben.',
        'read_only_description' => 'Ändern darf sie die Verwaltung der Organisation.',
        'name' => 'Name',
        'platform' => 'Plattform',
        'default_environment' => 'Standard-Umgebung',
        'default_environment_hint' => 'Gilt für Meldungen, die keine eigene Umgebung mitschicken.',
        'retention' => 'Datenaufbewahrung (Tage)',
        'attachment_retention' => 'Aufbewahrung der Anhänge (Tage)',
        'attachment_retention_hint' => 'Gilt für Screenshots, Logdateien und Speicherabbilder. '
            .'Sie sind ein Vielfaches schwerer als die Meldung, an der sie hängen, und werden '
            .'in den Tagen gebraucht, in denen jemand den Fehler untersucht.',
        'retention_label' => 'Datenaufbewahrung',
        'attachment_retention_label' => 'Aufbewahrung der Anhänge',
        'retention_value' => ':days Tage',

        // Der Schalter zu den verdächtigen Commits (R4).
        'auto_assign' => 'Verdächtige Commits automatisch zuweisen',
        'auto_assign_hint' => 'Ein neuer Fehler geht von selbst an den Autor des '
            .'verdächtigsten Commits — sofern er hier ein Konto hat und der Fehler '
            .'noch niemandem gehört. Angezeigt werden die Verdächtigen ohnehin; '
            .'dieser Schalter entscheidet nur, ob daraus eine Zuständigkeit wird.',
        'auto_assign_on' => 'An',
        'auto_assign_off' => 'Aus',
        'resolution' => 'Auflösungs-Verhalten',
        'submit' => 'Speichern',
    ],

    'teams' => [
        'title' => 'Zuständige Teams',
        'description' => 'Ohne Zuordnung ist das Projekt Sache der ganzen Organisation.',
        'empty' => 'Diese Organisation hat noch keine Teams.',
        'submit' => 'Speichern',
    ],

    'environments' => [
        'title' => 'Umgebungen',
        'description' => 'Werden beim ersten Eintreffen einer Meldung erfasst. Ausgeblendete Umgebungen erscheinen nicht mehr in der Filterleiste.',
        'empty' => 'Für dieses Projekt ist noch keine Meldung eingegangen.',
        'hidden' => 'ausgeblendet',
        'last_seen' => 'Zuletzt gemeldet: :time',
        'show' => 'Wieder anbieten',
        'hide' => 'Ausblenden',
    ],

    'keys' => [
        'title' => 'Client-Schlüssel',
        'description' => 'Die DSN ist die Adresse, an die das SDK seine Meldungen schickt. Sie steht mit allen Schlüsseln dieses Projekts auf einer eigenen Seite.',
        'manage' => 'Client-Schlüssel verwalten',
    ],

    'crons' => [
        'title' => 'Cronjobs',
        'description' => 'Überwachte Cronjobs melden sich bei jedem Lauf. Bleibt die Meldung aus, kommt eine Nachricht — statt dass es erst auffällt, wenn Daten fehlen.',
        'manage' => 'Cronjobs ansehen',
    ],

    'uptime' => [
        'title' => 'Erreichbarkeit',
        'description' => 'Regelmäßige Prüfungen von außen erkennen einen Totalausfall — den einzigen Fall, den keine Fehlermeldung melden kann, weil dann nichts mehr läuft.',
        'manage' => 'Erreichbarkeit ansehen',
    ],

    'alerts' => [
        'title' => 'Alarme',
        'description' => 'Schwellwert-Alarme auf Kennzahlen: Fehleranzahl, Fehlerquote, Durchsatz und Antwortzeiten. Sie melden sich, wenn eine Kennzahl aus dem Rahmen fällt — und wieder, wenn sie sich normalisiert hat.',
        'manage' => 'Alarme ansehen',
    ],

    'issue_alerts' => [
        'title' => 'Alarm-Regeln',
        'description' => 'Wann soll wer benachrichtigt werden: neue Fehler, Rückfälle, Eskalationen und Häufigkeiten — eingeschränkt auf Grad, Umgebung, Fassung oder Merkmal, mit Begrenzung gegen Benachrichtigungs-Fluten.',
        'manage' => 'Alarm-Regeln ansehen',
    ],

    'alert_overview' => [
        'title' => 'Alarm-Übersicht',
        'description' => 'Was hat wann gefeuert: alle Regeln beider Arten mit Zustand, letzter Auslösung und Verlauf — dazu die Möglichkeit, eine Regel befristet stummzuschalten, ohne die Auswertung anzuhalten.',
        'manage' => 'Verlauf ansehen',
    ],

    'ownership' => [
        'title' => 'Zuständigkeit',
        'description' => 'Wer sich um einen Fehler kümmert, hängt davon ab, wo er passiert ist. Regeln auf Pfad, Adresse, Modul oder Merkmal schlagen die Zuständigen vor — auf Wunsch weisen sie auch zu.',
        'manage' => 'Regeln ansehen',
    ],

    'sampling' => [
        'title' => 'Stichproben',
        'description' => 'Von den Antwortzeiten wird nur ein einstellbarer Anteil gespeichert und in den Auswertungen hochgerechnet. Fehlermeldungen bleiben davon unberührt.',
        'manage' => 'Regeln ansehen',
    ],

    'performance' => [
        'title' => 'Leistungserkennung',
        'description' => 'Ab wann N+1-Abfragen, langsame Aufrufe oder blockierende Ressourcen als Leistungsproblem gelten. Die Erkennung läuft im Hintergrund über gespeicherte Abläufe.',
        'manage' => 'Schwellen ansehen',
    ],

    'grouping' => [
        'title' => 'Gruppierung',
        'description' => 'Gleichartige Meldungen werden zu einem Eintrag zusammengefasst. Greift das im Einzelfall zu grob oder zu fein, korrigieren projektweite Regeln es.',
        'manage' => 'Regeln ansehen',
    ],

    'quotas' => [
        'title' => 'Kontingente',
        'description' => 'Wie viel je Datenart hereinkommen darf: Monatskontingent und Rate je Minute, dazu der Verbrauch dieses Monats und die Zählung dessen, was abgewiesen wurde.',
        'manage' => 'Kontingente ansehen',
    ],

    'filters' => [
        'title' => 'Eingangsfilter',
        'description' => 'Bekanntes Rauschen — Browser-Erweiterungen, Crawler, lokale Entwicklung — wird beim Eingang verworfen und nur noch gezählt.',
        'manage' => 'Filter einstellen',
    ],

    'spikes' => [
        'title' => 'Ausschlag-Schutz',
        'description' => 'Ungewöhnliche Fehlerfluten werden am Verlauf dieses Projekts erkannt, die Aufnahme wird gedrosselt und das Team benachrichtigt. Verworfenes wird gezählt und ausgewiesen.',
        'manage' => 'Schutz ansehen',
    ],

    'digest' => [
        'title' => 'Meldungen bündeln',
        'description' => 'Bei einer Fehlerwelle kommen sonst dutzende Einzel-Mails. Ein Zeitfenster fasst sie zu einer Sammelnachricht zusammen; dringende Meldungen bleiben davon unberührt.',
        'manage' => 'Bündelung einstellen',
    ],

    'privacy' => [
        'title' => 'Datenschutz',
        'description' => 'Passwörter, Nachweise und Kartennummern werden bei jeder Meldung entfernt, bevor etwas gespeichert wird. Was darüber hinaus verschwinden soll, steht auf einer eigenen Seite.',
        'manage' => 'Datenschutz einstellen',
    ],

    'delete' => [
        'title' => 'Projekt löschen',
        'description' => 'Mit dem Projekt verschwinden seine Einstellungen, die Team-Zuordnung und alle daran hängenden Daten — unwiderruflich.',
        'submit' => 'Projekt löschen',
    ],

    'flash' => [
        'created' => 'Projekt „:name" angelegt.',
        'updated' => 'Projekt gespeichert.',
        'deleted' => 'Projekt „:name" gelöscht.',
        'teams_updated' => 'Zuständige Teams gespeichert.',
        'environment_shown' => 'Umgebung „:name" wird wieder angeboten.',
        'environment_hidden' => 'Umgebung „:name" ausgeblendet.',
    ],

];
