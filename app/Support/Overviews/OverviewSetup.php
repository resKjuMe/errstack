<?php

namespace App\Support\Overviews;

use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Welche Projekte noch nie etwas gemeldet haben — und was an ihrer Stelle
 * stehen soll.
 *
 * **Ein leeres Diagramm ist die falsche Antwort auf „noch nicht
 * angeschlossen".** Wer ein frisches Projekt öffnet, sieht sonst eine Nulllinie
 * und weiß nicht, ob nichts passiert ist oder nichts ankommt. Die Übersichten
 * zeigen deshalb den Weg in den Einrichtungs-Assistenten, solange von einem
 * Projekt überhaupt nichts vorliegt.
 *
 * **Gefragt wird nach dem Eingang und nicht nach Fehlern.** Ein Projekt, das
 * sauber läuft und nur Messungen schickt, ist angeschlossen — es hat nur keine
 * Fehler. Maßgeblich ist deshalb die Aufnahme ({@see IngestPayload}); die
 * Fehler-Einträge stehen als zweite Spur daneben, weil die Aufnahme-Zeilen
 * nach ihrer Aufbewahrungsfrist verschwinden und ein lange laufendes Projekt
 * sonst eines Tages wieder als „neu" gälte.
 *
 * **Eine Abfrage für alle Projekte und nicht eine je Projekt.** Auf der
 * Organisations-Übersicht stehen alle nebeneinander; einzeln gefragt wäre das
 * eine Schleife über die Projektliste für eine Ja/Nein-Auskunft.
 */
final class OverviewSetup
{
    /**
     * Die Projekte, von denen noch nichts vorliegt.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<int>
     */
    public static function pendingIds(Collection $projects): array
    {
        $ids = $projects->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        if ($ids === []) {
            return [];
        }

        $seen = array_merge(
            IngestPayload::query()->whereIn('project_id', $ids)->distinct()->pluck('project_id')->all(),
            Issue::query()->whereIn('project_id', $ids)->distinct()->pluck('project_id')->all(),
        );

        $seen = array_map(static fn (mixed $id): int => (int) $id, $seen);

        return array_values(array_diff($ids, $seen));
    }

    /**
     * Der Einrichtungs-Hinweis für diese Projekte — oder `null`, wenn keines
     * davon aussteht.
     *
     * Genannt werden sie mit Namen und Weg: „ein Projekt wartet noch" ohne zu
     * sagen, welches, wäre auf einer Übersicht mit zwölf Projekten keine Hilfe.
     *
     * Ohne Organisation gibt es keinen Hinweis: dann gibt es auch keine
     * Projekte, auf die er zeigen könnte.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{projects: list<array{slug: string, name: string, href: string}>, all: bool}|null
     */
    public static function hint(?Organization $organization, Collection $projects): ?array
    {
        $pending = $organization === null ? [] : self::pendingIds($projects);

        if ($pending === []) {
            return null;
        }

        $waiting = $projects->filter(
            fn (Project $project): bool => in_array((int) $project->id, $pending, true),
        )->values();

        return [
            'projects' => $waiting->map(fn (Project $project): array => [
                'slug' => $project->slug,
                'name' => $project->name,
                'href' => route('projects.setup.index', [$organization, $project]),
            ])->all(),
            // Ob **alles** aussteht oder nur ein Teil, entscheidet über die
            // Darstellung: bei einem einzelnen neuen Projekt neben zehn
            // laufenden bleibt das Diagramm stehen und der Hinweis tritt
            // daneben — ein Diagramm zu verstecken, in dem echte Zahlen stehen,
            // wäre der schlechtere Tausch.
            'all' => count($pending) === $projects->count(),
        ];
    }
}
