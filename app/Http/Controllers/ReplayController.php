<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplayIndexRequest;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Replay;
use App\Models\ReplayError;
use App\Support\Replays\ReplayData;
use App\Support\Replays\ReplayOverview;
use App\Support\Replays\ReplayStore;
use App\Support\Replays\ReplayTimeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sitzungs-Aufzeichnungen: nachsehen, was der Nutzer vor dem Fehler getan hat.
 *
 * Der Stacktrace sagt, **wo** es geknallt hat, und die Breadcrumbs sagen, was
 * die Anwendung zuletzt gemeldet hat. Was beide offenlassen — wie der Nutzer
 * überhaupt dorthin kam —, steht hier, und nur hier.
 *
 * Drei Wege hinein, und der wichtigste ist nicht die Liste:
 *
 *   Von einem Fehler — der Regelweg. Die Fehlerseite zeigt die Aufzeichnungen,
 *                      in denen genau diese Meldung passierte, und springt an
 *                      die Stelle.
 *   Über die Liste   — für den seltenen Fall ohne konkreten Anlass.
 *   Über die Adresse — ein geteilter Link. Er trägt den Sprungpunkt mit.
 *
 * **Die Bilddaten kommen getrennt** ({@see data()}). Eine Aufzeichnung wiegt
 * Megabyte; sie in die Seitenantwort zu legen hieße, dass die Seite erst
 * erscheint, wenn alles da ist — und niemand wüsste, ob sie lädt oder hängt.
 */
class ReplayController extends Controller
{
    /**
     * Wie viele Aufzeichnungen die Liste zeigt.
     *
     * Kein Blättern, aus demselben Grund wie bei den Profilen: die Liste ist der
     * Einstieg und nicht der Datenbestand. Wer die dreihundertste Sitzung von
     * gestern sucht, sucht in Wahrheit einen Fehler — und der Weg dorthin führt
     * über die Fehlerseite.
     */
    public const LIST_LIMIT = 50;

    public function __construct(
        private readonly ReplayStore $store,
    ) {}

    public function index(ReplayIndexRequest $request): InertiaResponse
    {
        $overview = new ReplayOverview($request->filter(), $request->onlyWithErrors());

        return Inertia::render('replays/Index', [
            'replays' => $overview->replays(self::LIST_LIMIT)
                ->map(static fn (Replay $replay): array => ReplayData::row($replay))
                ->all(),
            'total' => $overview->total(),
            'listLimit' => self::LIST_LIMIT,
            'onlyWithErrors' => $request->onlyWithErrors(),
        ]);
    }

    /**
     * Die Abspielseite.
     *
     * Sie kommt ohne Bilddaten und ist trotzdem vollständig: Kopfdaten,
     * Sprungmarken und der Verweis, unter dem der Abspieler den Film holt. Alles
     * daran ist klein und sofort da — der Rahmen steht, bevor das erste Bild
     * geladen ist.
     */
    public function show(Replay $replay): InertiaResponse
    {
        Gate::authorize('view', $replay);

        $replay->loadMissing('project.organization');

        return Inertia::render('replays/Show', [
            'replay' => ReplayData::detail($replay),
            'errors' => ReplayData::errors($replay, $replay->errors()->get()),
            'dataHref' => route('replays.data', $replay),
            'listHref' => route('replays.index'),
        ]);
    }

    /**
     * Die Bilddaten samt der daraus gelesenen Spuren.
     *
     * **Als Datenstrom und nicht als aufgebaute Antwort.** Die Abschnitte einer
     * Sitzung sind zusammen zweistellige Megabyte; sie erst vollständig im
     * Speicher zusammenzusetzen, um sie dann zu versenden, wäre bei ein paar
     * gleichzeitigen Zuschauern der Arbeitsspeicher des Servers.
     *
     * Die Ereignisse gehen dabei **unverändert** hinaus: auf der Platte liegt
     * bereits JSON, und es zu zerlegen, nur um es sofort wieder zusammenzusetzen,
     * wäre die teuerste Zeile dieser Anwendung. Zerlegt wird nur nebenher, für
     * die Spuren — und deren Ergebnis steht deshalb am **Ende** der Antwort: vor
     * dem letzten Abschnitt ist es nicht fertig.
     */
    public function data(Replay $replay): StreamedResponse
    {
        Gate::authorize('view', $replay);

        $segments = $replay->segments()->get();
        $timeline = new ReplayTimeline($replay->started_at->getTimestampMs());
        $store = $this->store;

        return response()->stream(function () use ($segments, $timeline, $store): void {
            echo '{"events":[';

            $first = true;

            foreach ($segments as $segment) {
                $json = $store->segmentJson($segment);

                if ($json === null) {
                    // Eine Lücke im Film — die Datei ist weg, die Zeile steht
                    // noch. Kein Grund abzubrechen: der Rest der Sitzung ist
                    // vollständig und beantwortet die Frage womöglich schon.
                    continue;
                }

                $decoded = json_decode($json, true);

                if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
                    continue;
                }

                /** @var list<array<string, mixed>> $events */
                $events = array_values(array_filter(
                    $decoded,
                    static fn (mixed $event): bool => is_array($event) && ! array_is_list($event),
                ));

                $timeline->consume($events, $segment);

                // Die äußeren Klammern des Abschnitts fallen weg: die einzelnen
                // Listen werden zu einer zusammengeschoben, ohne dass irgendwo
                // eine Zeichenkette in Abschnittsgröße entsteht.
                $inner = substr(trim($json), 1, -1);

                if ($inner === '') {
                    continue;
                }

                echo $first ? $inner : ','.$inner;
                $first = false;
            }

            echo '],"timeline":'.json_encode($timeline->result(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'}';
        }, headers: [
            'Content-Type' => 'application/json',
            // Nicht zwischenspeichern: eine laufende Sitzung wächst, und ein
            // Abspieler, der die Fassung von vor fünf Minuten aus dem Speicher
            // holt, zeigt genau den Teil nicht, wegen dem jemand nachsieht.
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Der Weg von einer Fehlermeldung zu ihrer Aufzeichnung.
     *
     * Die Fehlerdetailseite verlinkt hierher und muss dafür nichts über
     * Aufzeichnungen wissen — sie hat die Meldung, und das genügt. Dieselbe
     * Aufteilung wie bei den Profilen, und aus demselben Grund: die Seiten
     * sollen verlinken können, ohne vorher abzufragen, ob es etwas zu verlinken
     * gibt.
     *
     * Gibt es keine abspielbare Aufzeichnung, führt der Weg in die Liste statt
     * in eine Fehlerseite. „Für diese Meldung haben wir keine, hier sind die
     * anderen" ist die nützlichere Antwort.
     */
    public function event(Issue $issue, Event $event): RedirectResponse
    {
        Gate::authorize('view', $issue);

        if ($event->project_id !== $issue->project_id) {
            throw new NotFoundHttpException;
        }

        $replay = Replay::query()
            ->whereIn('id', ReplayError::query()
                ->where('project_id', $event->project_id)
                ->where('event_id', $event->event_id)
                ->select('replay_id'))
            ->playable()
            ->newestFirst()
            ->first();

        if ($replay === null) {
            return redirect()->route('replays.index');
        }

        return redirect()->route('replays.show', $replay);
    }
}
