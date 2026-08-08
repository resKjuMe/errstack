<?php

namespace App\Http\Controllers;

use App\Enums\IssueCategory;
use App\Models\Event;
use App\Models\Issue;
use App\Support\Issues\EventDetail;
use App\Support\Issues\EventNavigation;
use App\Support\Issues\IssueActionData;
use App\Support\Issues\IssueActivityFeed;
use App\Support\Issues\IssueHeader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Detailseite eines Fehlers — die Antwort auf „warum".
 *
 * Die Liste (S1) sagt, dass es einen Fehler gibt und wie oft; hier steht, was
 * passiert ist. Gezeigt wird dabei **genau eine** Meldung: ein Stacktrace ist
 * der einer Meldung, Breadcrumbs sind die einer Meldung, und eine Ansicht, die
 * über mehrere mittelt, wäre keine Diagnose. Alles, was über den Fehler als
 * Ganzes gilt — Häufigkeit, Betroffene, erstes und letztes Auftreten —, kommt
 * aus dem Eintrag ({@see IssueHeader}); zwischen den Meldungen wird geblättert
 * ({@see EventNavigation}).
 */
class IssueDetailController extends Controller
{
    public function show(Request $request, Issue $issue, ?Event $event = null): InertiaResponse
    {
        Gate::authorize('view', $issue);

        $this->errorsOnly($issue);

        // Der Kopf verlinkt das Projekt, und die Adresse dorthin führt über die
        // Organisation. Ohne das Nachladen wären das zwei Abfragen mitten in der
        // Darstellung. Wer erledigt bzw. stummgeschaltet hat und in welcher
        // Version, steht im Kopf daneben — dieselbe Rechnung.
        $issue->loadMissing('project.organization', 'resolvedBy', 'resolvedInRelease', 'ignoredBy');

        $event = $this->resolve($issue, $event);

        return Inertia::render('issues/Show', [
            'issue' => IssueHeader::present($issue, $request->user()),
            'event' => $event === null ? null : EventDetail::present($event),
            'navigation' => $event === null ? null : EventNavigation::links($issue, $event),
            'rawHref' => $event === null ? null : route('issues.events.raw', [$issue, $event]),
            // Was mit diesem Fehler geschehen ist (S6). Der Verlauf steht auf
            // der Detailseite und nicht im Änderungsprotokoll der Organisation:
            // die Frage „warum ist der wieder offen?" stellt sich hier und
            // nirgends sonst.
            'activity' => IssueActivityFeed::forIssue($issue),
            'actions' => IssueActionData::forViewer($issue),
        ]);
    }

    /**
     * Die Meldung, wie sie ankam und wie sie ausgewertet wurde.
     *
     * Zwei Fassungen nebeneinander, weil sie verschiedene Fragen beantworten:
     * `event` zeigt, was diese Anwendung aus der Meldung gemacht hat, `original`
     * das, was das SDK geschickt hat. Steht in der Anzeige etwas Merkwürdiges,
     * ist genau der Unterschied zwischen beiden die Antwort — und ohne die
     * zweite Fassung bliebe die Frage offen, ob das SDK oder die Auswertung sie
     * verursacht hat.
     *
     * Die Rohdaten sind bereinigt: geschwärzt wird vor dem Speichern, und zwar
     * in beiden Fassungen (siehe App\Support\Ingest\Processing\Steps\ScrubEvent).
     */
    public function raw(Issue $issue, Event $event): JsonResponse
    {
        Gate::authorize('view', $issue);

        $this->errorsOnly($issue);

        if (! EventNavigation::belongsTo($issue, $event)) {
            throw new NotFoundHttpException;
        }

        return response()->json([
            'event' => Arr::except($event->toArray(), ['ingest_payload_id']),
            // `null`, sobald die Eingangsablage aufgeräumt ist — die
            // ausgewertete Meldung überlebt ihre Rohdaten.
            'original' => $event->payload?->decoded(),
        ], options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Welche Meldung die Seite zeigt.
     *
     * Ohne Angabe die neueste — das ist die, wegen der jemand die Seite
     * aufschlägt. Mit Angabe wird geprüft, ob sie zu diesem Eintrag gehört:
     * beide Kennungen stehen in der Adresszeile, und eine vertauschte Zeile darf
     * keine fremde Meldung unter fremdem Kopf zeigen.
     *
     * `null` kommt heraus, wenn der Eintrag (noch) keine Meldung hat. Das ist
     * kein erdachter Fall: die Gruppierung kann abgebrochen sein, und nach einer
     * Aufräumfrist stehen die Zähler länger als die Meldungen.
     */
    private function resolve(Issue $issue, ?Event $event): ?Event
    {
        if ($event === null) {
            return EventNavigation::newest($issue);
        }

        if (! EventNavigation::belongsTo($issue, $event)) {
            throw new NotFoundHttpException;
        }

        return $event;
    }

    /**
     * Diese Seite zeigt Fehler und nur Fehler.
     *
     * Seit PF6 stehen zwei Arten von Einträgen in derselben Tabelle, und die
     * Kennung in der Adresszeile unterscheidet sie nicht. Ein Leistungsproblem
     * hier zu öffnen, ergäbe eine Seite ohne Stacktrace und ohne Meldung —
     * dieselbe Überlegung, aus der die Gegenrichtung ({@see
     * PerformanceIssueController}) Fehler abweist.
     */
    private function errorsOnly(Issue $issue): void
    {
        if ($issue->category !== IssueCategory::Error) {
            throw new NotFoundHttpException;
        }
    }
}
