<?php

namespace App\Support\Crons;

use App\Enums\CronCheckInStatus;
use App\Models\CronCheckIn;
use App\Models\CronMonitor;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Nimmt einen Check-in an: sucht den Monitor, schreibt die Ausführung in den
 * Verlauf, zieht den Zustand nach und meldet, wenn nötig.
 *
 * Beide Wege — das `check_in`-Element eines Envelope und der einfache
 * HTTP-Aufruf — landen hier. Das ist Absicht: es gibt genau eine Stelle, an
 * der entschieden wird, was ein Check-in bedeutet, und sie ist nicht davon
 * abhängig, wie er hereinkam.
 *
 * Ein Check-in ist nie ein Grund, eine Anfrage abzuweisen. Er kommt aus einem
 * Job, der gerade läuft; ihm einen Fehler zurückzugeben, hilft niemandem und
 * bringt im schlimmsten Fall den Job zum Absturz, den wir überwachen sollen.
 */
final class CheckInIntake
{
    public function __construct(private readonly CronAlerts $alerts) {}

    /**
     * Verarbeitet einen Check-in und gibt die aufgenommene Ausführung zurück —
     * `null`, wenn sich ihr kein Monitor zuordnen ließ.
     */
    public function accept(Project $project, CheckInPayload $payload): ?CronCheckIn
    {
        if (! $payload->isValid()) {
            Log::warning('Check-in ohne Kennung oder Zustand verworfen.', [
                'projekt' => $project->id,
            ]);

            return null;
        }

        $monitor = $this->monitorFor($project, $payload);

        if ($monitor === null) {
            // Der häufigste Fall dahinter ist ein Tippfehler in der Kennung.
            // Deshalb protokolliert, aber nicht abgewiesen: der Job soll nicht
            // an seiner eigenen Überwachung scheitern.
            Log::warning('Check-in für unbekannten Cronjob verworfen.', [
                'projekt' => $project->id,
                'monitor' => $payload->monitorSlug,
            ]);

            return null;
        }

        if (! $monitor->is_active) {
            // Abgeschaltet heißt abgeschaltet: kein Eintrag im Verlauf, keine
            // Zustandsänderung, kein Alarm. Der Monitor bleibt stehen, damit
            // sich die Überwachung wieder einschalten lässt, ohne sie neu
            // einzurichten.
            return null;
        }

        [$checkIn, $status] = DB::transaction(
            fn (): array => $this->store($monitor, $payload),
        );

        $this->notify($monitor, $checkIn, $status);

        return $checkIn;
    }

    /**
     * Schreibt Ausführung und Monitorzustand in einem Zug.
     *
     * In einer Transaktion, weil beides zusammengehört: ein Verlaufseintrag
     * ohne nachgezogenen Zustand hieße, dass die Übersicht den Ausfall nicht
     * kennt, den der Verlauf zeigt.
     *
     * @return array{CronCheckIn, CronCheckInStatus}
     */
    private function store(CronMonitor $monitor, CheckInPayload $payload): array
    {
        $status = $payload->status ?? CronCheckInStatus::Ok;
        $now = now();

        $checkIn = $status === CronCheckInStatus::InProgress
            ? CronCheckIn::begin($monitor, $payload->checkInId, $payload->environment, $now)
            : $this->finish($monitor, $payload, $status, $now);

        $monitor->applyCheckIn($status, $now);

        // Der nächste Termin wird erst mit dem Abschluss fortgeschrieben. Täte
        // man es schon beim Beginn, wäre die laufende Ausführung ihre eigene
        // Vorgeschichte — und ein Job, der hängen bleibt, sähe aus, als hätte
        // er seinen Termin eingehalten.
        if ($status->isFinished()) {
            $monitor->scheduleNextDue($now);
        }

        $monitor->save();

        return [$checkIn, $status];
    }

    /**
     * Schließt die begonnene Ausführung ab — oder legt eine abgeschlossene an,
     * wenn kein Beginn gemeldet wurde.
     */
    private function finish(CronMonitor $monitor, CheckInPayload $payload, CronCheckInStatus $status, Carbon $now): CronCheckIn
    {
        $open = CronCheckIn::openFor($monitor, $payload->checkInId);

        if ($open === null) {
            return CronCheckIn::record(
                monitor: $monitor,
                status: $status,
                checkInId: $payload->checkInId,
                environment: $payload->environment,
                reportedSeconds: $payload->durationSeconds,
                at: $now,
            );
        }

        if ($payload->environment !== null) {
            $open->environment = $payload->environment;
        }

        return $open->finish($status, $payload->durationSeconds, $now);
    }

    /**
     * Alarm oder Entwarnung — je nachdem, was die Zähler des Monitors sagen.
     *
     * Außerhalb der Transaktion: der Versand reiht Jobs in die Warteschlange
     * ein, und die dürfen nicht anlaufen, bevor die Änderung festgeschrieben
     * ist.
     */
    private function notify(CronMonitor $monitor, CronCheckIn $checkIn, CronCheckInStatus $status): void
    {
        if ($status->isFailure() && $monitor->needsAlert()) {
            $this->alerts->fired($monitor, $checkIn);

            $monitor->alerted_at = now();
            $monitor->save();

            return;
        }

        if ($status === CronCheckInStatus::Ok && $monitor->needsRecovery()) {
            $this->alerts->recovered($monitor, $checkIn);

            $monitor->alerted_at = null;
            $monitor->save();
        }
    }

    /**
     * Der Monitor zum Check-in — vorhanden oder aus der mitgeschickten
     * Selbstbeschreibung angelegt.
     *
     * Der zweite Weg ist der bequemere und in der Praxis der häufigere: der
     * Zeitplan steht ohnehin im Code des Jobs, und ihn ein zweites Mal in eine
     * Oberfläche zu tippen heißt, dass beide Stellen auseinanderlaufen. Ohne
     * Zeitplan wird allerdings nichts angelegt — ein Monitor ohne Termin könnte
     * nie feststellen, dass eine Ausführung ausgeblieben ist, und wäre damit
     * genau das, was hier fehlt.
     */
    private function monitorFor(Project $project, CheckInPayload $payload): ?CronMonitor
    {
        $slug = (string) $payload->monitorSlug;
        $monitor = CronMonitor::findBySlug($project, $slug);

        if ($monitor === null) {
            return $payload->config?->hasSchedule() === true
                ? $this->create($project, $slug, $payload->config)
                : null;
        }

        return $this->applyConfig($monitor, $payload->config);
    }

    private function create(Project $project, string $slug, MonitorConfig $config): CronMonitor
    {
        return CronMonitor::createFor(
            project: $project,
            // Bis jemand einen sprechenden Namen vergibt, ist die Kennung der
            // beste Name, den wir haben — sie steht so im Job.
            name: $slug,
            attributes: $config->attributes(),
            slug: $slug,
        );
    }

    /**
     * Zieht eine mitgeschickte Selbstbeschreibung nach.
     *
     * Nur die Felder, die sie tatsächlich benennt: ein in der Oberfläche
     * eingestelltes Toleranzfenster soll nicht beim nächsten Lauf
     * zurückgesetzt werden, bloß weil das SDK dazu nichts sagt. Ändert sich der
     * Zeitplan, wird auch der nächste Termin neu gerechnet — sonst wartete die
     * Prüfung weiter auf den alten.
     */
    private function applyConfig(CronMonitor $monitor, ?MonitorConfig $config): CronMonitor
    {
        $attributes = $config?->attributes() ?? [];

        if ($attributes === []) {
            return $monitor;
        }

        $monitor->fill($attributes);

        if ($monitor->isDirty(['schedule_type', 'schedule_expression', 'interval_value', 'interval_unit', 'timezone'])) {
            $monitor->scheduleNextDue();
        }

        $monitor->save();

        return $monitor;
    }
}
