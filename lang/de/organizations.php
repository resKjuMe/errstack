<?php

// Organisationen (resources/js/shell/pages/organizations) samt der Meldungen
// aus App\Http\Controllers\OrganizationController, MembershipController und
// OrganizationInvitationController.
return [

    'index' => [
        'title' => 'Organisationen',
        'help' => 'Eine Organisation ist die Klammer um alles Weitere: Projekte, Fehlermeldungen und Alarme gehören immer genau einer. Wer eingeladen wird, sieht nur deren Daten.',
        'empty_title' => 'Noch keine Organisation',
        'empty_description' => 'Lege eine an, um loszulegen — oder warte auf eine Einladung per E-Mail.',
        'own_role' => 'Eigene Rolle: :role',
        'current' => 'Aktiv',
        'switch' => 'Aktiv setzen',
    ],

    'create' => [
        'title' => 'Neue Organisation',
        'description' => 'Wer sie anlegt, wird ihr Besitzer.',
        'name' => 'Name',
        'submit' => 'Anlegen',
    ],

    'show' => [
        'help' => 'Rollen bestimmen, wer was darf: Besitzer alles, Verwaltung die Organisation samt Mitgliedern und Teams, Mitglied die tägliche Arbeit, Lesend nur schauen.',
        'own_role' => 'Eigene Rolle:',
        'audit_log' => 'Änderungsprotokoll',
        'all_organizations' => 'Alle Organisationen',
    ],

    'settings' => [
        'title' => 'Stammdaten',
        'description' => 'Der Name der Organisation.',
        'name' => 'Name',
        'submit' => 'Speichern',
    ],

    'members' => [
        'title' => 'Mitglieder',
        'description_manage' => 'Rolle ändern oder Mitglied entfernen.',
        'description_read' => 'Wer zu dieser Organisation gehört.',
        'self' => '(das bist du)',
        'hint_self' => 'Die eigene Rolle ändert man nicht selbst.',
        'hint_owner' => 'Einen Besitzer ändert nur ein Besitzer.',
        'role_of' => 'Rolle von :name',
        'leave' => 'Verlassen',
        'remove' => 'Entfernen',
    ],

    'invitations' => [
        'title' => 'Einladungen',
        'description' => 'Der Link in der E-Mail führt direkt zum Beitritt — ein Konto kann dabei noch angelegt werden.',
        'email' => 'E-Mail-Adresse',
        'role' => 'Rolle',
        'submit' => 'Einladen',
        'expired' => 'abgelaufen',
        'valid_until' => 'gültig bis :date',
        'role_of' => 'Rolle der Einladung an :email',
        'withdraw' => 'Zurückziehen',
    ],

    'notifications' => [
        'title' => 'Benachrichtigungen',
        'description' => 'Wohin Errstack meldet: E-Mail, Slack, Discord, Teams oder ein eigener Webhook.',
        'link' => 'Kanäle und Zustellprotokoll',
    ],

    'teams' => [
        'title' => 'Teams',
        'description' => 'Teams bündeln Mitglieder innerhalb der Organisation.',
        'empty' => 'Noch keine Teams.',
        'new' => 'Neues Team',
        'submit' => 'Anlegen',
    ],

    'delete' => [
        'title' => 'Organisation löschen',
        'description' => 'Mit der Organisation verschwinden Mitgliedschaften, Teams und alle daran hängenden Daten — unwiderruflich.',
        'submit' => 'Organisation löschen',
    ],

    'flash' => [
        'created' => 'Organisation „:name" angelegt.',
        'updated' => 'Organisation gespeichert.',
        'deleted' => 'Organisation „:name" gelöscht.',
        'switched' => 'Aktive Organisation: :name.',
        'role_changed' => 'Rolle von :name auf :role gesetzt.',
        'left' => 'Organisation „:name" verlassen.',
        'member_removed' => ':name wurde entfernt.',
        'invitation_sent' => 'Einladung an :email verschickt.',
        'invitation_role_changed' => 'Einladung an :email: Rolle auf :role gesetzt.',
        'invitation_withdrawn' => 'Einladung an :email zurückgezogen.',
    ],

];
