<?php

namespace App\Policies;

use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wer einen Fehler-Eintrag ansehen und wer ihn verändern darf.
 *
 * Die Liste (S1) beantwortet die Frage über die Auswahl: was jemand nicht sehen
 * darf, steht dort gar nicht erst in der Filterleiste. Die Detailseite hat diese
 * Vorauswahl nicht — sie wird über eine Kennung in der Adresszeile aufgerufen,
 * und eine geratene Kennung ist ein Aufruf wie jeder andere. Deshalb steht die
 * Prüfung hier ausdrücklich.
 *
 * **Ansehen und Verändern fallen zusammen, Löschen nicht.** Wer die Fehler eines
 * Projekts sieht, arbeitet mit ihnen: erledigen, stummschalten und merken sind
 * die tägliche Arbeit an der Liste und keine Verwaltungsaufgabe. Ein eigenes
 * Recht dafür hieße, dass die Hälfte des Teams eine Arbeitsliste ansehen kann,
 * die sie nicht aufräumen darf. Löschen ist etwas anderes — es nimmt allen
 * anderen etwas weg (siehe {@see delete()}).
 */
class IssuePolicy
{
    public function view(User $user, Issue $issue): bool
    {
        $project = $issue->project;

        return $project !== null && $project->organization->hasMember($user);
    }

    /**
     * Zustandsaktionen: erledigen, wieder öffnen, stummschalten, merken,
     * abonnieren.
     *
     * Alle umkehrbar — das ist die Begründung dafür, sie an dasselbe Recht wie
     * das Ansehen zu hängen.
     */
    public function update(User $user, Issue $issue): bool
    {
        return $this->view($user, $issue);
    }

    /**
     * Löschen und „löschen und künftig verwerfen".
     *
     * Dasselbe Recht wie die übrigen Aktionen, und das ist eine Abwägung und
     * kein Versehen: Löschen ist die einzige Aktion ohne Rückweg. Dagegen steht,
     * dass die Alternative — nur die Verwaltung darf löschen — in der Praxis
     * dazu führt, dass niemand aufräumt und die Liste unbrauchbar wird. Die
     * Aktion wird stattdessen an der Oberfläche ausdrücklich bestätigt und
     * bleibt im Aktivitätsverlauf des Projekts stehen, auch wenn der Eintrag
     * weg ist.
     */
    public function delete(User $user, Issue $issue): bool
    {
        return $this->view($user, $issue);
    }

    /**
     * Fehler von Hand zusammenführen und wieder auftrennen (S9).
     *
     * Dasselbe Recht wie das Ansehen und ausdrücklich **kein**
     * Verwaltungsrecht: die automatische Gruppierung zurechtzurücken ist die
     * tägliche Arbeit an der Fehlerliste und keine Einstellung am Projekt.
     * Tragen kann diese Entscheidung, dass nichts dabei verloren geht — das
     * Zusammenführen bewegt keine Meldung und lässt sich Untergruppe für
     * Untergruppe wieder auflösen.
     */
    public function merge(User $user, Issue $issue): bool
    {
        return $this->view($user, $issue);
    }

    /**
     * Die Einträge, an denen dieser Betrachter arbeiten darf — als Abfrage.
     *
     * Der Weg für Sammelaktionen: eine Rechteprüfung je Kennung wären bei 200
     * ausgewählten Zeilen 200 Abfragen, und bei „alle 12.480" ginge es gar
     * nicht. Die Bedingung ist dieselbe wie in {@see view()} — sie steht nur
     * einmal als Frage an ein Objekt und einmal als Frage an eine Menge.
     *
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    public static function scopeFor(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'project.organization.memberships',
            fn (Builder $memberships): Builder => $memberships->where('user_id', $user->id),
        );
    }
}
