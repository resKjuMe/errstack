<?php

namespace App\Policies;

use App\Models\Profile;
use App\Models\User;

/**
 * Wer ein Profil ansehen darf.
 *
 * Dieselbe Begründung wie bei {@see IssuePolicy}: die Übersicht beantwortet die
 * Frage über die Auswahl — was jemand nicht sehen darf, steht gar nicht erst in
 * der Filterleiste. Die Detailseite hat diese Vorauswahl nicht, sie wird über
 * eine Kennung in der Adresszeile aufgerufen, und eine geratene Kennung ist ein
 * Aufruf wie jeder andere.
 */
class ProfilePolicy
{
    public function view(User $user, Profile $profile): bool
    {
        $project = $profile->project;

        return $project !== null && $project->organization->hasMember($user);
    }
}
