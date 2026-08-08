<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserReport;

/**
 * Wer eine Rückmeldung sehen und bearbeiten darf.
 *
 * Wie beim Fehler-Eintrag ({@see IssuePolicy}) beantwortet die Liste die Frage
 * über die Auswahl — was jemand nicht sehen darf, steht dort gar nicht erst in
 * der Filterleiste. Die Handlungen daran werden über eine Kennung in der
 * Adresszeile aufgerufen, und eine geratene Kennung ist ein Aufruf wie jeder
 * andere; deshalb steht die Prüfung hier ausdrücklich.
 *
 * Ansehen und bearbeiten fallen zusammen: eine Rückmeldung zuzuweisen oder als
 * erledigt zu kennzeichnen ist Alltagsarbeit und keine Verwaltungshandlung. Wer
 * die Zuschrift lesen darf, darf auch sagen, dass er sich darum kümmert.
 */
class UserReportPolicy
{
    public function view(User $user, UserReport $report): bool
    {
        $project = $report->project;

        return $project !== null && $project->organization->hasMember($user);
    }

    public function update(User $user, UserReport $report): bool
    {
        return $this->view($user, $report);
    }
}
