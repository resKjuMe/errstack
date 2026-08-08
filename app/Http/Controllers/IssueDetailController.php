<?php

namespace App\Http\Controllers;

use App\Enums\IssueCategory;
use App\Jobs\SymbolicateEvent;
use App\Models\Event;
use App\Models\EventSymbolication;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Support\Issues\EventDetail;
use App\Support\Issues\EventNavigation;
use App\Support\Issues\IssueActionData;
use App\Support\Issues\IssueActivityFeed;
use App\Support\Issues\IssueHeader;
use App\Support\Releases\SuspectCommitData;
use App\Support\Releases\SuspectCommits;
use App\Support\SourceMaps\Symbolicator;
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
        // Dazu, woraus der Eintrag besteht: die von Hand beigetretenen
        // Untergruppen samt ihren Fingerabdrücken und — bei einem beigetretenen
        // Eintrag — der Kopf, unter dem er jetzt gezählt wird (S9). Mitgeladen,
        // weil es sonst je Untergruppe eine Abfrage wäre.
        $issue->loadMissing([
            'project.organization',
            'resolvedBy',
            'resolvedInRelease',
            'regressedInRelease',
            'ignoredBy',
            // Wer zuständig ist und wer ihn dazu gemacht hat (S7) — aus
            // demselben Grund mitgeladen wie alles andere hier.
            'assignedUser',
            'assignedTeam',
            'assignedBy',
            'mergedSources.groups',
            'mergedInto',
        ]);

        $event = $this->resolve($issue, $event);

        if ($event !== null) {
            $this->ensureSymbolication($event);
        }

        return Inertia::render('issues/Show', [
            'issue' => IssueHeader::present($issue, $request->user()),
            'event' => $event === null ? null : EventDetail::present($event),
            'navigation' => $event === null ? null : EventNavigation::links($issue, $event),
            'rawHref' => $event === null ? null : route('issues.events.raw', [$issue, $event]),
            // Welche Änderung den Fehler verursacht haben könnte (R4). Berechnet
            // und nicht gespeichert: der Abgleich hängt an der angezeigten
            // Meldung, und die wechselt beim Blättern. Ohne verbundenes
            // Repository kommt eine leere Liste heraus — dann fehlt der Bereich,
            // statt leer dazustehen.
            'suspects' => SuspectCommitData::present(SuspectCommits::forEvent($issue, $event)),
            // Was mit diesem Fehler geschehen ist (S6) und was dazu gesagt
            // wurde (S10). Der Verlauf steht auf der Detailseite und nicht im
            // Änderungsprotokoll der Organisation: die Frage „warum ist der
            // wieder offen?" stellt sich hier und nirgends sonst.
            'activity' => IssueActivityFeed::forIssue($issue, $request->user()),
            // Was die Oberfläche zum Schreiben braucht. Die Rechtefrage wird
            // hier beantwortet und nicht dort: eine Schaltfläche, die beim
            // Klick abgewiesen wird, ist schlimmer als keine.
            'comments' => [
                'canWrite' => Gate::allows('create', [IssueComment::class, $issue]),
                'storeHref' => route('issues.comments.store', $issue),
                'suggestHref' => route('issues.comments.suggest', $issue),
                'limit' => IssueComment::BODY_LIMIT,
            ],
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
     * Sorgt dafür, dass es zu dieser Meldung eine Rückübersetzung gibt (R5).
     *
     * **Diese Stelle ist der eigentliche Auslöser, nicht die Aufnahme.**
     * Quellkarten werden in der Praxis hochgeladen, nachdem die ersten Fehler
     * eingetroffen sind — erst knallt es, dann fällt auf, dass niemand die Karten
     * ausliefert. Ein Auftrag nur bei der Aufnahme hätte zur Folge, dass genau die
     * Meldungen, um die es geht, für immer unlesbar bleiben.
     *
     * Beauftragt wird nur, wenn es etwas zu übersetzen gibt und noch keine Zeile
     * vorliegt. Die Vormerkung entscheidet danach, wer rechnet
     * ({@see EventSymbolication::reserve()}) — zwei gleichzeitig
     * aufgeschlagene Seiten lösen keine zwei Durchläufe aus.
     *
     * Die Beziehung wird hier geladen und nicht in der Darstellung: sonst wäre es
     * eine Abfrage mitten im Zusammenbauen der Seite.
     */
    private function ensureSymbolication(Event $event): void
    {
        $event->loadMissing('symbolication');

        if ($event->symbolication !== null || ! Symbolicator::isApplicable($event)) {
            return;
        }

        [$record, $reserved] = EventSymbolication::reserve($event);

        if ($reserved) {
            SymbolicateEvent::dispatch($event);
        }

        // Die frisch vorgemerkte Zeile gehört an die Meldung, damit die Anzeige
        // „wird übersetzt" sagen kann. Ohne das Setzen wäre der Zustand erst beim
        // nächsten Aufschlagen zu sehen — und die Seite, die den Auftrag ausgelöst
        // hat, wüsste als einzige nichts davon.
        $event->setRelation('symbolication', $record);
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
