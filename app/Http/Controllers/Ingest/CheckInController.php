<?php

namespace App\Http\Controllers\Ingest;

use App\Http\Controllers\Controller;
use App\Support\Crons\CheckInIntake;
use App\Support\Crons\CheckInPayload;
use App\Support\Ingest\IngestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Der einfache Weg, ein Lebenszeichen zu senden:
 *
 *     curl https://errstack.example/api/1/cron/nightly-import/<public_key>/
 *     curl ".../cron/nightly-import/<key>/?status=in_progress&check_in_id=…"
 *     curl ".../cron/nightly-import/<key>/?status=error"
 *
 * Der Umweg über ein SDK ist bei Cronjobs oft keiner, den man gehen kann: der
 * überwachte Job ist ein Shell-Skript, ein `pg_dump`, eine Zeile in einer
 * Crontab. Deshalb genügt hier ein Aufruf ohne Kopfzeilen, ohne Rumpf und ohne
 * Abhängigkeit — alles Nötige steht in der Adresse.
 *
 * `GET` ist mit Absicht erlaubt, obwohl der Aufruf etwas verändert: die
 * Gegenstelle ist oft ein Werkzeug, das nur Adressen kennt (ein Monitoring-Ping,
 * ein `wget` in der Crontab). Der Missbrauchsfall dahinter — jemand ruft die
 * Adresse auf und meldet einen Lauf, der nicht stattgefunden hat — setzt den
 * Schlüssel voraus und ist derselbe wie bei jeder anderen Aufnahme-Adresse.
 *
 * Die Antwort ist immer 202 mit der Kennung des Laufs. Auch dann, wenn sich
 * kein Monitor zuordnen ließ: die Gegenstelle ist ein Job, der gerade arbeitet,
 * und ein Fehlercode aus seiner Überwachung darf ihn nicht aus dem Tritt
 * bringen — im schlimmsten Fall bricht ein `set -e`-Skript daran ab.
 */
class CheckInController extends Controller
{
    public function store(Request $request, CheckInIntake $intake, string $project, string $monitor): JsonResponse
    {
        $payload = CheckInPayload::fromRequest($monitor, $request->query());

        $checkIn = $intake->accept(
            IngestContext::key($request)->project,
            $payload,
        );

        // `??` fängt hier auch den Fall ab, dass gar kein Check-in entstanden
        // ist: es unterdrückt den Zugriff auf `null` mit — ein `?->` davor wäre
        // doppelt gemoppelt. Dann geht das zurück, was gemeldet wurde.
        $status = $checkIn->status ?? $payload->status;

        return new JsonResponse([
            'id' => $checkIn->check_in_id ?? $payload->checkInId,
            'status' => $status?->value,
            // Ob der Check-in tatsächlich einem Monitor zugeordnet werden
            // konnte. Ohne diese Auskunft sähe ein Tippfehler in der Kennung
            // von außen genauso aus wie ein angekommener Lebenszeichen-Aufruf.
            'accepted' => $checkIn !== null,
        ], 202);
    }
}
