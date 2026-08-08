<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Rechte an Projekten. Ein Projekt gehört immer einer Organisation — die Rechte
 * ergeben sich aus der Rolle dort, nicht aus der Team-Zuordnung des Projekts.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->organization->hasMember($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Zuständige Teams zuordnen und wieder herausnehmen.
     */
    public function manageTeams(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Client-Schlüssel anlegen, abschalten, neu ziehen und löschen. Wer das
     * darf, bekommt auch die DSN im Klartext zu sehen — sie ist der Zugang zur
     * Datenaufnahme, deshalb dasselbe Recht wie für die übrigen Einstellungen.
     */
    public function manageKeys(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Überwachte Cronjobs anlegen, ändern und löschen.
     *
     * Getrennt vom Ansehen: den Zustand der Jobs soll jedes Mitglied sehen —
     * er ist der Grund, warum jemand nachschaut. Ein Toleranzfenster zu
     * verstellen heißt dagegen, die Überwachung leiser zu drehen, und das ist
     * eine Verwaltungsentscheidung.
     */
    public function manageCrons(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Fingerprint-Regeln anlegen, ändern und löschen.
     *
     * Dasselbe Recht wie für die übrigen Einstellungen, und das ist hier keine
     * Bequemlichkeit: eine Regel ändert, wie **alle** künftigen Meldungen des
     * Projekts zusammengefasst werden. Eine unbedacht gesetzte Regel kann die
     * ganze Fehlerliste in einen Eintrag ziehen — sichtbar bleibt dann alles,
     * unterscheidbar nichts mehr.
     */
    public function manageGrouping(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Eingangsfilter schalten und ihre Listen pflegen.
     *
     * Wieder dasselbe Recht, und hier steht am meisten auf dem Spiel: ein
     * Eingangsfilter verhindert nicht, dass etwas zusammengefasst wird, sondern
     * dass es überhaupt entsteht. Eine zu weit gefasste Sperre lässt Meldungen
     * verschwinden, ohne dass in der Liste eine Lücke zu sehen wäre — nur die
     * Zählung der gefilterten Ereignisse verrät sie noch.
     */
    public function manageFilters(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Schwellwert-Alarme anlegen, ändern, abschalten und löschen.
     *
     * Getrennt vom Ansehen: welche Alarme scharf sind, soll jedes Mitglied
     * sehen — das ist die erste Frage, wenn etwas **nicht** gemeldet wurde. Eine
     * Schwelle zu verstellen heißt dagegen, die Überwachung leiser zu drehen,
     * und das ist eine Verwaltungsentscheidung.
     */
    public function manageAlerts(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Stichproben-Regeln anlegen, ändern und löschen.
     *
     * Dasselbe Recht wie für die übrigen Einstellungen, und hier wöge eine
     * Lockerung schwerer als beim Grouping: eine Regel entscheidet, welche
     * Messungen es künftig **nicht** geben wird. Eine zu niedrig gesetzte Quote
     * lässt sich nicht zurücknehmen — die Daten dieses Zeitraums sind dann
     * nirgends mehr, auch nicht in den Rohdaten.
     */
    public function manageSampling(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }

    /**
     * Die Schwellen der Leistungserkennung setzen.
     *
     * Dasselbe Recht wie für die übrigen Einstellungen — und anders als bei den
     * Stichproben ist hier nichts unwiderruflich: eine zu hoch gesetzte Schwelle
     * verhindert nur, dass ein Muster **gemeldet** wird. Die Abläufe selbst
     * bleiben gespeichert, und wer die Schwelle zurücknimmt, findet die
     * folgenden Vorfälle wieder. Ein eigenes, schwächeres Recht wäre trotzdem
     * eine Einladung: wer die Schwellen hochdreht, macht eine Liste leer, die
     * andere für vollständig halten.
     */
    public function managePerformance(User $user, Project $project): bool
    {
        return $user->can('manageProjects', $project->organization);
    }
}
