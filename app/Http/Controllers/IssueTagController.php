<?php

namespace App\Http\Controllers;

use App\Http\Requests\GlobalFilterRequest;
use App\Models\Issue;
use App\Support\Formats;
use App\Support\Tags\TagAggregates;
use App\Support\Tags\TagFacets;
use App\Support\Tags\TagLinks;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Merkmale **eines** Fehlers: welche Browser, Fassungen und Server ihn
 * betreffen.
 *
 * Die Frage, die diese Seite beantwortet, ist nicht „was ist passiert", sondern
 * „wen trifft es" — und sie entscheidet, ob ein Fehler ein Randfall einer alten
 * Browserfassung ist oder ein Ausfall für alle. Deshalb steht sie neben dem
 * Fehler und nicht in ihm: sie liest ausschließlich die vorberechneten Zähler
 * (S3) und kein einziges Ereignis, während die Detailseite (S2) genau umgekehrt
 * arbeitet.
 *
 * Zwei Ansichten, eine Klasse: die Übersicht zeigt je Merkmal die häufigsten
 * Werte, die Detailansicht **alle** Werte eines Merkmals. Sie unterscheiden sich
 * nur darin, wie tief geschaut wird — und teilen sich Zugriffsprüfung, Filter
 * und Darstellung.
 */
class IssueTagController extends Controller
{
    /**
     * Alle Merkmale des Fehlers, je Merkmal die häufigsten Werte.
     */
    public function index(GlobalFilterRequest $request, Issue $issue): InertiaResponse
    {
        $this->authorizeIssue($issue);

        $filter = $request->filter();

        return Inertia::render('issues/Tags', [
            'issue' => self::issue($issue),
            'facets' => TagLinks::decorate(
                TagFacets::forIssue($issue),
                $filter,
                fn (string $key): string => route('issues.tags.show', [$issue, $key] + $filter->formValues()),
            ),
            'detail' => null,
            'issuesHref' => route('issues.index', $filter->formValues()),
            'valueLimit' => TagAggregates::MAX_VALUES_PER_KEY,
        ]);
    }

    /**
     * Alle Werte **eines** Merkmals dieses Fehlers.
     */
    public function show(GlobalFilterRequest $request, Issue $issue, string $key): InertiaResponse|Response
    {
        $this->authorizeIssue($issue);

        $filter = $request->filter();
        $detail = TagFacets::forIssueKey($issue, $key);

        if ($detail === null) {
            // Ein Merkmal, das dieser Fehler nicht trägt — meist ein Link, der
            // älter ist als die Daten. Nicht „leere Seite", sondern „gibt es
            // nicht": das ist der Unterschied zwischen „keine Werte" und „falsche
            // Adresse".
            abort(404);
        }

        return Inertia::render('issues/Tags', [
            'issue' => self::issue($issue),
            'facets' => [],
            'detail' => TagLinks::decorateOne($detail, $filter),
            'issuesHref' => route('issues.index', $filter->formValues()),
            'valueLimit' => TagAggregates::MAX_VALUES_PER_KEY,
        ]);
    }

    /**
     * Wer den Fehler sehen darf, darf auch seine Merkmale sehen.
     *
     * Die Prüfung hängt am Projekt und nicht am Eintrag: ein Fehler gehört
     * immer genau einem Projekt, und wer dessen Organisation nicht angehört, hat
     * mit dem Eintrag nichts zu tun.
     */
    private function authorizeIssue(Issue $issue): void
    {
        Gate::authorize('view', $issue->project);
    }

    /**
     * Der Fehler, soweit diese Seite ihn braucht — Kopfzeile, keine Zähler
     * jenseits der Häufigkeit.
     *
     * @return array<string, mixed>
     */
    private static function issue(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title ?? $issue->culprit ?? __('issues.list.untitled'),
            'culprit' => $issue->culprit,
            'timesSeen' => $issue->times_seen,
            'timesSeenLabel' => Formats::number($issue->times_seen),
            'href' => route('issues.tags.index', $issue),
        ];
    }
}
