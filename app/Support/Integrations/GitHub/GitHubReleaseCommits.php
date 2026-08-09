<?php

namespace App\Support\Integrations\GitHub;

use App\Models\Integration;
use App\Models\Release;
use App\Support\Releases\CommitImport;

/**
 * Holt die Commits einer Auslieferung — der Teil der Anbindung, der die
 * eigentliche Frage beantwortet: **was steckt da drin?**
 *
 * Bis hierher musste eine Bauumgebung sie mitschicken (R2). Das setzt voraus,
 * dass jemand die Pipeline dafür erweitert — und genau daran scheitert es in
 * der Praxis. Mit einer Anbindung genügt der Stand, den die Auslieferung
 * ohnehin nennt: `ref` sagt, was ausgeliefert wurde, der Vorgänger sagt, was
 * vorher draußen war, und die Differenz ist die Antwort.
 *
 * **Was mitgeschickt wurde, bleibt unangetastet.** Läuft eine Pipeline, die
 * ihre Commits selbst übergibt, holt hier niemand nach: die Liste des Absenders
 * ist die genauere Angabe — sie kennt den tatsächlich gebauten Stand, während
 * dieser Weg ihn aus zwei Versionsangaben erschließt.
 */
final class GitHubReleaseCommits
{
    /**
     * So viele Commits, wenn es keinen Vorgänger zum Vergleichen gibt.
     *
     * Die erste Auslieferung, die über die Anbindung hereinkommt, hat keinen
     * Bezugspunkt. „Alles seit Beginn" wäre die technisch naheliegende und die
     * unbrauchbare Antwort — niemand liest 4.000 Commits als Inhalt einer
     * Version. Fünfzig sind eine Liste, die jemand überfliegt.
     */
    private const INITIAL_LIMIT = 50;

    /**
     * So viele Vorgänger werden angesehen, um den letzten mit einem Stand zu
     * finden. Zwischenversionen ohne `ref` gibt es reichlich — sie entstehen
     * von selbst aus Meldungen (R1) und nennen keinen Commit.
     */
    private const LOOKBACK = 25;

    /**
     * @return int Anzahl der übernommenen Commits
     *
     * @throws GitHubException wenn der Aufruf bei GitHub scheitert
     */
    public static function fetch(Release $release): int
    {
        $release->loadMissing('project.organization');

        $organization = $release->project?->organization;
        $ref = trim((string) $release->ref);

        if ($organization === null || $ref === '') {
            // Ohne Stand gibt es nichts zu vergleichen. Das ist der Regelfall
            // bei einer Version, die aus Meldungen entstanden ist — sie kennt
            // ihre Nummer, nicht den Commit dahinter.
            return 0;
        }

        $integration = Integration::forOrganization($organization);

        if ($integration === null || ! $integration->isUsable()) {
            return 0;
        }

        if ($release->commits()->exists()) {
            // Schon übergeben (oder schon einmal geholt). Beides heißt: die
            // Frage ist beantwortet.
            return 0;
        }

        $client = new GitHubClient($integration);
        $imported = 0;

        // Einmal ermittelt und nicht je Repository: der Bezugspunkt ist eine
        // Eigenschaft der Auslieferung und hängt nicht daran, wo gesucht wird.
        $previous = self::previousRef($release);

        foreach ($integration->repositories()->orderBy('name')->get() as $repository) {
            $commits = self::commitsOf($client, $repository->name, $ref, $previous);

            if ($commits === []) {
                continue;
            }

            $imported += count(CommitImport::into($release, $commits, $repository->name));

            // Ein Stand gehört zu **einem** Repository. Dass hier über alle
            // verbundenen gelaufen wird, ist die Suche danach, in welchem — und
            // sie endet, sobald er gefunden ist. Weiterzusuchen hieße, in einem
            // zweiten Repository nach einem Hash zu fragen, der dort höchstens
            // zufällig existiert.
            break;
        }

        if ($imported > 0) {
            $integration->markSynced();
        }

        return $imported;
    }

    /**
     * Die Commits dieses Repositories für diese Auslieferung — oder nichts,
     * wenn der Stand dort nicht existiert.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GitHubException
     */
    private static function commitsOf(GitHubClient $client, string $repository, string $ref, ?string $previous): array
    {
        try {
            return $previous === null
                ? $client->commits($repository, $ref, self::INITIAL_LIMIT)
                : $client->compare($repository, $previous, $ref);
        } catch (GitHubException $e) {
            if ($e->accessRejected) {
                // Der Zugang ist weg — das ist nichts, worüber man zum nächsten
                // Repository weitergehen sollte. Es ist bereits festgehalten
                // (siehe GitHubClient); hier wird es weitergereicht, damit der
                // Auftrag es meldet.
                throw $e;
            }

            // Ein `404` heißt hier meistens nur: dieser Stand liegt in einem
            // anderen der verbundenen Repositories. Das ist kein Fehler,
            // sondern das Ergebnis der Suche — und deshalb geht es leise zum
            // nächsten weiter.
            return [];
        }
    }

    /**
     * Der Stand der vorigen Auslieferung — der Bezugspunkt des Vergleichs.
     */
    private static function previousRef(Release $release): ?string
    {
        $candidates = Release::query()
            ->where('project_id', $release->project_id)
            ->whereKeyNot($release->getKey())
            ->whereNotNull('ref')
            ->newestFirst()
            ->limit(self::LOOKBACK)
            ->get();

        foreach ($candidates as $candidate) {
            // Die Ordnung der Versionsliste und keine zweite: „vorher" heißt
            // hier dasselbe wie dort, sonst stünde in der Liste die eine und im
            // Inhalt der Auslieferung die andere Reihenfolge.
            if ($release->isNewerThan($candidate)) {
                $ref = trim((string) $candidate->ref);

                return $ref === '' ? null : $ref;
            }
        }

        return null;
    }
}
