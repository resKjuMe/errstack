<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Übersetzt sind die Regeln, die in den Formularen der Anwendung vorkommen.
    | Alles Übrige fällt Schlüssel für Schlüssel auf `fallback_locale` (en)
    | zurück, sodass diese Datei nicht vollständig sein muss.
    |
    */

    'confirmed' => ':attribute stimmt nicht mit der Wiederholung überein.',
    'current_password' => 'Das Passwort ist falsch.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'enum' => ':attribute ist keine gültige Auswahl.',
    'exists' => ':attribute ist unbekannt.',
    'integer' => ':attribute muss eine Zahl sein.',
    'lowercase' => ':attribute darf nur Kleinbuchstaben enthalten.',
    'max' => [
        'array' => ':attribute darf höchstens :max Einträge haben.',
        'file' => ':attribute darf höchstens :max Kilobyte groß sein.',
        'numeric' => ':attribute darf höchstens :max sein.',
        'string' => ':attribute darf höchstens :max Zeichen lang sein.',
    ],
    'min' => [
        'array' => ':attribute muss mindestens :min Einträge haben.',
        'file' => ':attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss Groß- und Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Zahl enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => ':attribute kommt in einem Datenleck vor. Bitte ein anderes wählen.',
    ],
    'required' => ':attribute ist erforderlich.',
    'string' => ':attribute muss Text sein.',
    'unique' => ':attribute wird bereits verwendet.',

    /*
    |--------------------------------------------------------------------------
    | Eigene Meldungen der Formulare
    |--------------------------------------------------------------------------
    |
    | Was die Formulare dieser Anwendung über die Regeln des Frameworks hinaus
    | zu sagen haben (App\Http\Requests).
    |
    */

    'messages' => [
        'range_reversed' => 'Das Ende des Zeitraums liegt vor seinem Anfang.',
        'range_from_missing' => 'Für einen eigenen Zeitraum fehlt der Anfang.',
        'range_to_missing' => 'Für einen eigenen Zeitraum fehlt das Ende.',
        'channel_name_taken' => 'Einen Kanal dieses Namens gibt es hier bereits.',
        'environment_pattern' => 'Erlaubt sind Kleinbuchstaben, Ziffern, Punkt, Bindestrich und Unterstrich.',
        'invitation_already_member' => 'Diese Adresse gehört bereits zur Organisation.',
        'invitation_already_open' => 'Für diese Adresse ist bereits eine Einladung offen.',
        'invitation_expired' => 'Diese Einladung ist abgelaufen. Bitte um eine neue bitten.',
        'organization_required' => 'Für API-Tokens braucht es zuerst eine Organisation.',
        'token_kind_forbidden' => 'Organisationsweite Tokens darf nur die Verwaltung anlegen.',
        'token_scope_forbidden' => 'Die eigene Rolle erlaubt es nicht, „:scope" zu vergeben.',
        'scope_role_too_low' => 'Die eigene Rolle in der Organisation reicht für „:scope" nicht aus.',
        'membership_gone' => 'Das Konto gehört dieser Organisation nicht mehr an.',
        'client_key_unknown' => 'Der Client-Schlüssel ist unbekannt oder gehört nicht zu diesem Projekt.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Ersetzt :attribute durch einen lesbaren Namen — ohne das stünde in der
    | Meldung der Feldname aus dem Formular („password_confirmation").
    |
    */

    'attributes' => [
        'current_password' => 'Das aktuelle Passwort',
        'email' => 'Die E-Mail-Adresse',
        'name' => 'Der Name',
        'password' => 'Das Passwort',
        'password_confirmation' => 'Die Passwort-Wiederholung',
        'platform' => 'Plattform',
        'default_environment' => 'Standard-Umgebung',
        'resolution_behavior' => 'Auflösungs-Verhalten',
        'retention_days' => 'Datenaufbewahrung',
        'type' => 'Kanal',
        'role' => 'Die Rolle',
        'user_id' => 'Das Mitglied',
    ],

];
