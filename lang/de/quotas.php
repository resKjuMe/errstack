<?php

// Kontingente und Ratenbegrenzung (O1): die Seite je Projekt und je
// Organisation, die Warnung, wenn ein Monatskontingent zur Neige geht, und die
// Zählung dessen, was abgewiesen wurde.
return [

    'title' => 'Kontingente — :subject',
    'help' => 'Wie viel je Datenart hereinkommen darf, was diesen Monat davon verbraucht ist und was abgewiesen wurde.',

    'intro' => [
        'title' => 'Wozu Kontingente',
        'description' => 'Ein einzelnes fehlerhaftes Projekt kann eine ganze Installation mit Daten überfluten. Kontingente begrenzen, was je Datenart hereinkommt — überschrittenes wird gebremst, gezählt und gemeldet.',
        'unlimited_hint' => 'Ein leeres Feld heißt „unbegrenzt". Das ist die Vorgabe: ein Kontingent ist eine Entscheidung und keine Voreinstellung, die irgendwann still zuschlägt.',
        'separate_hint' => 'Die Datenarten gelten getrennt. Ein aufgebrauchtes Transaktions-Kontingent hält Fehlermeldungen nicht auf.',
        'warning_hint' => 'Bei 80 % und bei 100 % eines Monatskontingents geht eine Nachricht an die Verwaltung der Organisation — je Schwelle einmal im Monat.',
        'period' => 'Abrechnungszeitraum: :period',
    ],

    'settings' => [
        'title' => 'Grenzen je Datenart',
        'description' => 'Das Monatskontingent begrenzt die Menge, die Rate begrenzt die Geschwindigkeit. Beide sind unabhängig voneinander und beide dürfen leer bleiben.',
        'read_only_description' => 'Ändern darf die Verwaltung der Organisation. Ansehen darf die Seite jedes Mitglied — sie ist die Antwort auf „warum kommt seit gestern nichts mehr an?".',
        'category' => 'Datenart',
        'per_month' => 'Kontingent je Monat',
        'per_minute' => 'Rate je Minute',
        'per_month_hint' => 'Leer = unbegrenzt.',
        'per_minute_hint' => 'Leer = unbegrenzt.',
        'usage' => 'Verbraucht',
        'unlimited' => 'unbegrenzt',
        'submit' => 'Kontingente speichern',
        'usage_of' => ':usage von :limit',
        'percent' => ':percent %',
    ],

    'categories' => [
        'errors_hint' => 'Fehlermeldungen aus `/store/` und Envelope-Elemente vom Typ `event`.',
        'transactions_hint' => 'Antwortzeiten samt Einzelschritten — und die Profile, die daran hängen.',
        'replays_hint' => 'Sitzungs-Aufzeichnungen: Kopfdaten und Bilddaten zusammen.',
        'attachments_hint' => 'Dateien zu einer Meldung: Screenshot, Logdatei, Speicherabbild.',
        'monitors_hint' => 'Lebenszeichen überwachter Cronjobs.',
    ],

    'inherited' => [
        'title' => 'Grenzen der Organisation',
        'description' => 'Sie stehen über den Grenzen dieses Projekts. Ist die Organisation am Ende, wird auch ein Projekt mit großzügigem eigenem Kontingent abgewiesen.',
        'link' => 'Kontingente der Organisation',
        'empty' => 'Die Organisation hat keine Kontingente gesetzt.',
    ],

    'keys' => [
        'title' => 'Raten der Client-Schlüssel',
        'description' => 'Die Rate eines Schlüssels gilt für alles, was über ihn hereinkommt, und kennt keine Datenarten — sie ist die Notbremse für eine einzelne Anwendung. Geändert wird sie auf der Schlüssel-Seite.',
        'name' => 'Schlüssel',
        'per_minute' => 'Rate je Minute',
        'usage' => 'in dieser Minute',
        'unlimited' => 'unbegrenzt',
        'inactive' => 'abgeschaltet',
        'empty' => 'Das Projekt hat keine Schlüssel.',
    ],

    'discards' => [
        'title' => 'Was verworfen wurde',
        'description' => 'Die letzten :days Tage, nach Grund. „Rate-Limit" und „Kontingent aufgebraucht" stammen von dieser Seite; die übrigen Gründe erklären, was auf dem Weg hierher sonst verloren ging.',
        'reason' => 'Grund',
        'origin' => 'Herkunft',
        'quantity' => 'Anzahl',
        'empty' => 'In diesem Zeitraum wurde nichts verworfen.',
    ],

    'notification' => [
        'title' => ':percent % des Kontingents für :category verbraucht (:subject)',
        'body' => 'Von :limit :category dieses Monats sind :usage verbraucht. Ist das Kontingent aufgebraucht, werden weitere Meldungen dieser Datenart abgewiesen — sie kommen nicht nachträglich herein.',
        'context_scope' => 'Ebene',
        'context_subject' => 'Gilt für',
    ],

    'flash' => [
        'updated' => 'Die Kontingente sind gespeichert.',
    ],

];
