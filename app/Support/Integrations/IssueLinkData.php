<?php

namespace App\Support\Integrations;

use App\Models\Integration;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Repository;
use Illuminate\Support\Facades\Gate;

/**
 * Was die Fehlerseite über verknüpfte Tickets wissen muss (X1).
 *
 * **Ohne Anbindung kommt `null` heraus** und nicht ein leerer Bereich mit einem
 * Formular, das nirgends hinführt. Das ist dieselbe Regel wie bei den Anhängen
 * und den verdächtigen Commits: die Seite zeigt einen Bereich erst, wenn es
 * etwas darin gibt — sonst wächst sie mit jedem Fachgebiet um einen leeren
 * Kasten, den niemand je füllen wird.
 *
 * Die eine Ausnahme: **verknüpfte Tickets stehen auch dann da, wenn die
 * Anbindung gelöst wurde.** Die Verknüpfung trägt Adresse und Nummer bei sich
 * und bleibt lesbar — sie wird nur nicht mehr abgeglichen. Sie verschwinden zu
 * lassen hieße, eine Aussage über den Fehler wegen einer Einstellung an anderer
 * Stelle zu verbergen.
 */
final class IssueLinkData
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forIssue(Issue $issue): ?array
    {
        $organization = $issue->project?->organization;

        if ($organization === null) {
            return null;
        }

        $links = $issue->links()->orderBy('id')->get();
        $integration = Integration::forOrganization($organization);
        $usable = $integration !== null && $integration->isUsable();

        if (! $usable && $links->isEmpty()) {
            return null;
        }

        return [
            // Ob sich etwas anlegen oder verknüpfen lässt. Getrennt von der
            // Liste, weil beides unabhängig voneinander leer bzw. falsch sein
            // kann: verknüpfte Tickets ohne Anbindung, Anbindung ohne Tickets.
            'canLink' => $usable && Gate::allows('update', $issue),
            'storeHref' => route('issues.links.store', $issue),
            'repositories' => ! $usable ? [] : $organization->repositories()
                ->whereNotNull('integration_id')
                ->orderBy('name')
                ->get()
                ->map(fn (Repository $repository): string => $repository->name)
                ->all(),
            'links' => $links->map(fn (IssueLink $link): array => [
                'id' => $link->id,
                'reference' => $link->reference(),
                'title' => $link->title,
                'url' => $link->url,
                'state' => $link->state->value,
                'stateLabel' => $link->state->label(),
                'deleteHref' => route('issues.links.destroy', [$link->issue_id, $link->id]),
            ])->all(),
        ];
    }
}
