<?php

/*
|--------------------------------------------------------------------------
| Broadcast-Kanäle
|--------------------------------------------------------------------------
|
| Wer welchen privaten Kanal mithören darf. Die Prüfung läuft über
| `/broadcasting/auth` mit der Sitzung des Betrachters — dieselbe Anmeldung wie
| für die Seiten und nicht ein zweiter Weg daneben.
|
| Ein Kanal ohne Eintrag hier ist ein Kanal, den niemand abonnieren kann. Das ist
| die richtige Voreinstellung: eine vergessene Regel führt zu einer Ansicht ohne
| Live-Aktualisierung und nicht zu Daten in fremden Händen.
|
*/

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Neue Fehler einer Organisation (S1). Mitlesen darf, wer sie sehen darf —
// dieselbe Regel wie für ihre Seiten, über die Policy nachgeschlagen statt hier
// noch einmal formuliert.
//
// Der Kanal umfasst die ganze Organisation und nicht ein Projekt: die
// Fehlerliste zeigt in der Regel alle, und ein Abo je Projekt wären bei fünfzig
// Projekten fünfzig Berechtigungsanfragen für einen Seitenaufruf. Welche
// Projekte gerade gemeint sind, entscheidet die Ansicht.
Broadcast::channel('organizations.{organization}.issues', function (User $user, int $organization): bool {
    $model = Organization::query()->find($organization);

    return $model !== null && $user->can('view', $model);
});
