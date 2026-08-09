<?php

namespace App\Policies;

use App\Models\Replay;
use App\Models\User;

/**
 * Wer eine Aufzeichnung ansehen darf.
 *
 * Dieselbe Begründung wie bei {@see ProfilePolicy}: die Übersicht beantwortet
 * die Frage über die Auswahl — was jemand nicht sehen darf, steht gar nicht erst
 * in der Filterleiste. Die Abspielseite hat diese Vorauswahl nicht, sie wird
 * über eine Kennung in der Adresszeile aufgerufen, und eine geratene Kennung ist
 * ein Aufruf wie jeder andere.
 *
 * Hier wiegt das schwerer als anderswo. Ein Stacktrace zeigt Code; eine
 * Aufzeichnung zeigt den Bildschirm eines Menschen. Der Unterschied ändert an
 * der Regel nichts — er ändert daran, wie sorgfältig sie angewendet gehört, und
 * deshalb hängt sie an **jedem** Weg zur Aufzeichnung: an der Seite, an den
 * Bilddaten und am Sprung von einem Fehler her.
 */
class ReplayPolicy
{
    public function view(User $user, Replay $replay): bool
    {
        $project = $replay->project;

        return $project !== null && $project->organization->hasMember($user);
    }
}
