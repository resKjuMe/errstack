<?php

namespace App\Policies;

use App\Models\Release;
use App\Models\User;

/**
 * Wer eine Auslieferung ansehen darf.
 *
 * Dieselbe Überlegung wie beim Fehler-Eintrag: die Liste (R1) beantwortet die
 * Frage über die Auswahl — was jemand nicht sehen darf, steht nicht in der
 * Filterleiste. Die Detailseite hat diese Vorauswahl nicht, sie wird über eine
 * Kennung in der Adresszeile aufgerufen, und eine geratene Kennung ist ein
 * Aufruf wie jeder andere.
 *
 * Der Inhalt ist dabei kein Nebenschauplatz: auf der Detailseite stehen
 * Commit-Nachrichten und Namen von Personen. Eine Auslieferung ist damit die
 * Stelle, an der aus einer Fehlerübersicht ein Blick in die Arbeit eines fremden
 * Teams würde.
 */
class ReleasePolicy
{
    public function view(User $user, Release $release): bool
    {
        $project = $release->project;

        return $project !== null && $project->organization->hasMember($user);
    }
}
