<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;

/**
 * Wer einen Fehler-Eintrag ansehen darf.
 *
 * Die Liste (S1) beantwortet die Frage über die Auswahl: was jemand nicht sehen
 * darf, steht dort gar nicht erst in der Filterleiste. Die Detailseite hat diese
 * Vorauswahl nicht — sie wird über eine Kennung in der Adresszeile aufgerufen,
 * und eine geratene Kennung ist ein Aufruf wie jeder andere. Deshalb steht die
 * Prüfung hier ausdrücklich.
 */
class IssuePolicy
{
    public function view(User $user, Issue $issue): bool
    {
        $project = $issue->project;

        return $project !== null && $project->organization->hasMember($user);
    }
}
