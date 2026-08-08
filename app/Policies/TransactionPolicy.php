<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

/**
 * Wer eine einzelne Messung ansehen darf.
 *
 * Gebraucht wird die Prüfung überall dort, wo eine Transaktion über ihre
 * Kennung aufgerufen wird statt über die Filterleiste — beim Weg von einer
 * Messung zu ihrem Profil (M4) ist das der Fall.
 */
class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        $project = $transaction->project;

        return $project !== null && $project->organization->hasMember($user);
    }
}
