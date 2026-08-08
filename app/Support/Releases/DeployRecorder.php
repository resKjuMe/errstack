<?php

namespace App\Support\Releases;

use App\Enums\IssueActivityType;
use App\Enums\IssueStatus;
use App\Models\Deploy;
use App\Models\Environment;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\Project;
use App\Models\Release;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Eine Auslieferung erfassen — und das tun, was aus ihr folgt.
 *
 * Drei Dinge, in dieser Reihenfolge:
 *
 *   1. **Die Zeile** ({@see Deploy::record()}) — Version, Umgebung, Zeitpunkt.
 *   2. **Die wartenden Einträge auflösen**: was auf „erledigt im nächsten
 *      Release" stand, ist mit dieser Auslieferung erledigt **in** ihr.
 *   3. **Die Beteiligten benachrichtigen** ({@see DeployNotifier}).
 *
 * **Schritt 2 hängt an der Umgebung, Schritt 3 nicht.** Das ist die eine
 * Unterscheidung, die diese Klasse trifft, und sie ist der Grund, warum ein
 * Deploy überhaupt eine Umgebung trägt: Ausliefern nach `staging` heißt nicht,
 * dass ein Fix bei den Nutzern angekommen ist — die Einträge dort aufzulösen
 * hieße, sie aus der Liste zu nehmen, während der Fehler draußen weiterläuft.
 * Aufgelöst wird deshalb nur beim Deploy in die **Standard-Umgebung** des
 * Projekts (`projects.default_environment`, Vorgabe `production`).
 *
 * Benachrichtigt wird dagegen bei jeder Auslieferung, samt Umgebung im Text:
 * „meine Änderung ist auf staging" ist genau die Nachricht, auf die jemand
 * wartet, der gleich nachsehen will.
 */
final class DeployRecorder
{
    /**
     * Wie viele Einträge auf einmal aufgelöst werden — dieselbe Blockgröße wie
     * bei den Zustandsaktionen (S6) und aus demselben Grund: je Block eine
     * `update`-Anweisung und **eine** Einfügung der Vermerke.
     */
    private const CHUNK = 500;

    public function __construct(private readonly DeployNotifier $notifier) {}

    /**
     * Erfasst eine Auslieferung dieser Version.
     *
     * Der Umgebungs-Name ist freiwillig; ohne ihn gilt die Standard-Umgebung
     * des Projekts. Das ist die richtige Vorgabe für den Aufrufer, den es
     * gibt — eine Auslieferungs-Pipeline, die nur eine Umgebung kennt.
     */
    public function record(
        Release $release,
        ?string $environment = null,
        ?string $name = null,
        ?string $url = null,
        ?CarbonImmutable $startedAt = null,
        ?CarbonImmutable $finishedAt = null,
    ): Deploy {
        $project = $release->project;

        if (! $project instanceof Project) {
            // Ein Release ohne Projekt gibt es nicht; die Prüfung steht hier,
            // weil die Beziehung nachgeladen wird und alles Folgende sie
            // braucht.
            $project = Project::query()->findOrFail($release->project_id);
        }

        $environment = Environment::forName($project, $environment);

        $deploy = Deploy::record($release, $environment, $name, $url, $startedAt, $finishedAt);

        // Damit die Folgeschritte nicht dieselben Zeilen noch einmal holen.
        $deploy->setRelation('release', $release);
        $deploy->setRelation('project', $project);
        $deploy->setRelation('environment', $environment);

        if (self::isProduction($project, $environment)) {
            $this->resolveAwaiting($deploy, $release, $project);
        }

        $this->notifier->send($deploy);

        return $deploy;
    }

    /**
     * Ist das die Umgebung, in der eine Auslieferung „draußen" bedeutet?
     *
     * Die Standard-Umgebung des Projekts, und nicht der feste Name
     * `production`: welche Umgebung die echte ist, weiß das Projekt, und
     * anderswo heißt sie `prod`, `live` oder `kunde-a`. Sie steht ohnehin schon
     * dort — als Ersatz für Meldungen ohne Umgebungs-Angabe.
     */
    private static function isProduction(Project $project, Environment $environment): bool
    {
        $default = Environment::normalizeName($project->default_environment) ?? 'production';

        return $environment->name === $default;
    }

    /**
     * Löst die Einträge auf, die auf diese Auslieferung gewartet haben.
     *
     * „Erledigt im nächsten Release" ist bis hierher ein Vermerk ohne Bezug:
     * der Fix war geschrieben, die Version, in der er steckt, gab es noch
     * nicht. Jetzt gibt es sie — der Vermerk wird zum Verweis, und ab hier
     * gilt dasselbe wie bei „erledigt in dieser Version": tritt der Fehler
     * danach aus einer **neueren** Version wieder auf, ist das eine Rückkehr
     * (S8); aus derselben oder einer älteren ist es eine Meldung von einem
     * Stand ohne den Fix.
     *
     * **Nur erledigte Einträge.** Die Bedingung auf den Status ist kein
     * Feinschliff: zwischen dem Erledigen und der Auslieferung liegen Stunden,
     * und in ihnen kann jemand denselben Eintrag wieder geöffnet haben. Beide
     * Stellen, die das tun, räumen zwar auch das Merkmal weg — aber die Regel
     * gehört an die Abfrage, die auflöst, nicht in das Vertrauen darauf.
     */
    private function resolveAwaiting(Deploy $deploy, Release $release, Project $project): void
    {
        $now = CarbonImmutable::now();

        Issue::query()
            ->where('project_id', $project->id)
            ->where('resolved_in_next_release', true)
            ->where('status', IssueStatus::Resolved)
            ->select(['issues.id', 'issues.project_id'])
            ->chunkById(self::CHUNK, function (Collection $issues) use ($deploy, $release, $now): void {
                $ids = $issues->modelKeys();

                $updated = Issue::query()
                    ->whereIn('id', $ids)
                    ->where('resolved_in_next_release', true)
                    ->update([
                        'resolved_in_release_id' => $release->id,
                        'resolved_in_next_release' => false,
                        'updated_at' => $now,
                    ]);

                if ($updated === 0) {
                    return;
                }

                // Ohne handelndes Konto, wie beim Ablauf einer Stummschaltung:
                // ausgeliefert hat eine Pipeline, und die hat keinen Namen, den
                // in einem Fehler-Verlauf jemand lesen wollte. Was zählt, steht
                // in `data` — und zwar als Werte und nicht als Verweise: eine
                // später gelöschte Version darf den Vermerk nicht leerräumen.
                IssueActivity::query()->insert($issues->map(fn (Issue $issue): array => [
                    'issue_id' => $issue->id,
                    'project_id' => $issue->project_id,
                    'user_id' => null,
                    'actor_name' => null,
                    'type' => IssueActivityType::Deployed->value,
                    'data' => json_encode([
                        'release' => $release->version,
                        'environment' => $deploy->environment?->name,
                        'deploy' => $deploy->id,
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                ])->all());
            }, 'issues.id', 'id');
    }
}
