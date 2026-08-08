<?php

namespace App\Support\Performance\Trends;

use App\Models\Project;
use App\Models\TransactionTrendDetection;
use App\Support\Releases\DeployMarkers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ein Durchlauf über alle Projekte — der Teil, den der Zeitplan aufruft.
 *
 * Er ist der Grund, warum ein Trendbruch überhaupt auffällt. Das ist derselbe
 * Gedanke wie bei der Cronjob-Überwachung (M1) und den Schwellwert-Alarmen (A3),
 * nur eine Stufe leiser: ein Ausfall meldet sich, ein Fehler meldet sich — eine
 * Seite, die von 200 ms auf 900 ms gerutscht ist, meldet sich nie. Sie
 * funktioniert ja.
 *
 * **Ein Projekt, das stolpert, hält die anderen nicht auf.** Ein Verlauf mit
 * unerwarteten Daten oder eine Datenbank, die gerade hustet, darf nicht dazu
 * führen, dass zwanzig weitere Projekte in diesem Durchlauf ungeprüft bleiben.
 * Der Fehler wird protokolliert und der Durchlauf geht weiter.
 *
 * **Nur die Standard-Umgebung.** Über alle Umgebungen zu rechnen wäre das
 * Vielfache an Arbeit für Zahlen, die niemand als Meldung haben will: dass die
 * Testumgebung langsamer geworden ist, während dort jemand ein Profiling laufen
 * lässt, ist keine Nachricht. Gemeint ist die Umgebung, in der eine
 * Auslieferung „draußen" bedeutet — dieselbe Wahl wie bei den
 * Deploy-Markierungen ({@see DeployMarkers}).
 */
final class TrendScan
{
    /**
     * Ab welcher Verschiebung des Bruchpunkts ein zweiter Umschlag gemeint ist.
     *
     * Sechs Stunden: das ist die Mindestbreite einer Seite
     * ({@see BreakpointScan::MINIMUM_SIDE_WINDOWS}) und damit der Abstand, ab dem
     * zwischen den beiden Stellen genug Messungen liegen, um sie überhaupt
     * getrennt zu belegen.
     */
    public const NEW_BREAK_HOURS = 6;

    public function __construct(private readonly TrendNotifier $notifier) {}

    /**
     * Ein Durchlauf. Gibt zurück, was dabei herauskam — die Konsole schreibt es
     * hin, die Tests prüfen es.
     *
     * @return array{projects: int, transactions: int, found: int, notified: int, failed: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $projects = 0;
        $transactions = 0;
        $found = 0;
        $notified = 0;
        $failed = 0;

        // Die Organisation gleich mit: die Meldung braucht sie, und ohne wäre es
        // je Feststellung eine zusätzliche Abfrage.
        Project::query()
            ->with('organization')
            ->each(function (Project $project) use ($now, &$projects, &$transactions, &$found, &$notified, &$failed): void {
                try {
                    $projects++;

                    $result = $this->scanProject($project, $now);

                    $transactions += $result['transactions'];
                    $found += $result['found'];
                    $notified += $result['notified'];
                } catch (Throwable $e) {
                    $failed++;

                    Log::error('Trendbrüche eines Projekts konnten nicht bestimmt werden.', [
                        'project_id' => $project->id,
                        'exception' => $e,
                    ]);
                }
            });

        return [
            'projects' => $projects,
            'transactions' => $transactions,
            'found' => $found,
            'notified' => $notified,
            'failed' => $failed,
        ];
    }

    /**
     * Ein Projekt, eine Umgebung.
     *
     * @return array{transactions: int, found: int, notified: int}
     */
    public function scanProject(Project $project, CarbonImmutable $now): array
    {
        $environment = $project->default_environment;
        $series = TrendSeries::forProject($project, $environment, $now);

        $found = 0;
        $notified = 0;

        foreach ($series as $transaction) {
            $breakpoint = BreakpointScan::find($transaction['windows']);

            if ($breakpoint === null) {
                continue;
            }

            $found++;

            if ($this->record($project, $environment, $transaction['name'], $transaction['op'], $breakpoint, $now)) {
                $notified++;
            }
        }

        return ['transactions' => count($series), 'found' => $found, 'notified' => $notified];
    }

    /**
     * Schreibt die Feststellung fort und meldet sie, wenn sie neu ist.
     *
     * **Fortgeschrieben und nicht angehängt.** Derselbe Bruch wird in jedem
     * Durchlauf erneut gefunden, solange er im Rückblick liegt; jedes Mal eine
     * Zeile anzulegen hieße, dieselbe Verschlechterung stündlich in die Liste zu
     * schreiben und stündlich zu melden.
     *
     * **Wann es trotzdem eine neue Meldung gibt:** wenn der Bruchpunkt an eine
     * andere Stelle wandert. Dann ist es nicht mehr derselbe Umschlag, sondern
     * ein zweiter — und ein zweiter Umschlag ist eine Nachricht, auch wenn die
     * erste schon als gesehen abgehakt war. Genau deshalb fallen dabei auch
     * `seen_at` und `notified_at` zurück.
     *
     * Der Bruchpunkt darf dafür nicht auf die Stunde genau verglichen werden:
     * mit jeder Stunde neuer Messungen kann die beste Trennstelle um ein Fenster
     * wandern, ohne dass etwas anderes gemeint wäre. Erst ab
     * {@see NEW_BREAK_HOURS} gilt es als neuer Umschlag.
     *
     * @return bool ob gemeldet wurde
     */
    private function record(
        Project $project,
        string $environment,
        string $name,
        string $op,
        Breakpoint $breakpoint,
        CarbonImmutable $now,
    ): bool {
        $detection = TransactionTrendDetection::query()->firstOrNew([
            'project_id' => $project->id,
            'environment' => $environment,
            'name' => $name,
            'op' => $op,
            'direction' => $breakpoint->direction,
        ]);

        $isNew = ! $detection->exists;
        $movedAway = ! $isNew
            && $detection->breakpoint_at->diffInHours($breakpoint->at, true) >= self::NEW_BREAK_HOURS;

        $detection->fill([
            'breakpoint_at' => $breakpoint->at,
            'before_p95_us' => $breakpoint->beforeP95Us,
            'after_p95_us' => $breakpoint->afterP95Us,
            'before_count' => $breakpoint->beforeCount,
            'after_count' => $breakpoint->afterCount,
            'change_ratio' => $breakpoint->changeRatio,
            'z_score' => $breakpoint->zScore,
            'deploy_id' => TrendCause::forBreakpoint($project, $environment, $breakpoint->at)?->id,
        ]);

        if ($isNew || $movedAway) {
            $detection->detected_at = $now;
            $detection->notified_at = null;
            $detection->seen_at = null;
            $detection->seen_by_id = null;
        }

        $detection->save();

        // Erst hier und nicht oben: die Meldung braucht die Kennung der Zeile,
        // und die gibt es vor dem Speichern nicht. Gemeldet wird alles, was noch
        // nicht hinausgegangen ist — auch eine ältere Feststellung, deren
        // Versand beim letzten Mal fehlgeschlagen ist.
        if (! $breakpoint->isRegression() || $detection->notified_at !== null) {
            return false;
        }

        $detection->setRelation('project', $project);

        $this->notifier->send($detection);

        $detection->notified_at = $now;
        $detection->save();

        return true;
    }
}
