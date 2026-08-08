<?php

namespace App\Support\Profiling;

use App\Models\IngestPayload;
use App\Models\Profile;
use App\Models\Transaction;

/**
 * Legt ein gemessenes Profil an der Transaktion ab, zu der es gehört.
 *
 * Anders als bei den Antwortzeiten wird hier **eine** Zeile geschrieben und
 * keine Vorberechnung fortgeschrieben. Der Grund ist die Menge: Profile kommen
 * nur für einen kleinen Teil der ohnehin gesiebten Transaktionen, und die
 * Fragen an sie sind andere. Eine Zeitreihe „Selbstzeit dieser Funktion je
 * Minute" wäre eine Vorberechnung je **Funktion** statt je Transaktion — bei
 * einigen tausend Funktionen je Anwendung ein Vielfaches der Rohdaten, aus dem
 * sich der Flamegraph trotzdem nicht wieder zusammensetzen ließe.
 *
 * Ein zweiter Durchlauf derselben Meldung ändert die vorhandene Zeile. Der Fall
 * ist nicht theoretisch: ein gescheiterter Job wird wiederholt, und nach einer
 * Änderung an der Auswertung sollen sich die Rohdaten erneut durchlaufen lassen.
 */
final class ProfileStore
{
    public function store(ProfileEvent $event, Transaction $transaction, ?IngestPayload $payload = null): Profile
    {
        $profile = Profile::query()
            ->where('project_id', $transaction->project_id)
            ->where('profile_id', $event->profileId)
            ->first() ?? new Profile;

        $profile->project_id = $transaction->project_id;
        $profile->transaction_id = $transaction->id;
        $profile->ingest_payload_id = $payload?->id;
        $profile->profile_id = $event->profileId;
        // Der Trace der Transaktion und nicht der gemeldete: eine abweichende
        // Angabe im Profil wäre eine Fehlmeldung des SDK und würde das Profil
        // aus dem Ablauf herausfallen lassen, in dem es gemessen wurde.
        $profile->trace_id = $transaction->trace_id;
        // Ebenso der Name: er ist der Schlüssel, unter dem zusammengefasst wird,
        // und muss deshalb derselbe sein wie in der Übersicht. Das SDK schickt
        // ihn im Profil ein zweites Mal, und zwar in einem Zustand vor der
        // Kürzung auf die Spaltenbreite.
        $profile->transaction_name = $transaction->name;
        $profile->platform = $event->platform ?? $transaction->platform;
        // Umgebung und Version kommen von der Transaktion, wenn das Profil keine
        // mitbringt. Die Alternative wäre eine leere Umgebung — und damit ein
        // Profil, das in keiner gefilterten Ansicht mehr auftaucht.
        $profile->environment = $event->environment ?? $transaction->environment;
        $profile->release = $event->release ?? $transaction->release;
        $profile->thread_id = $event->threadId;
        $profile->started_at = $event->startedAt;
        $profile->duration_us = $event->durationUs;
        $profile->sample_count = $event->tree->sampleCount;
        $profile->frames = $event->tree->framesToStorage();
        $profile->tree = $event->tree->treeToStorage();
        $profile->save();

        return $profile;
    }
}
