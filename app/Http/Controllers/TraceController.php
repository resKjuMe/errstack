<?php

namespace App\Http\Controllers;

use App\Http\Requests\TraceRequest;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Support\Performance\Trace\TraceDetail;
use App\Support\Performance\Trace\TraceError;
use App\Support\Performance\Trace\TraceView;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Trace-Ansicht: der gesamte Ablauf eines Aufrufs über alle Dienste hinweg.
 *
 * Sie beantwortet die Frage, die eine Fehlerliste nicht beantworten kann — „was
 * ist im selben Aufruf sonst noch passiert". Deshalb hat sie auch **keine**
 * globale Filterleiste: ein Zeitraum ist hier bedeutungslos, denn die Spur ist
 * der Zeitraum. Was sie stattdessen braucht, ist die Menge der Projekte, die der
 * Betrachter sehen darf — und die ist nicht die eines Projekts, sondern die
 * aller seiner Organisationen. Eine Spur führt über Dienstgrenzen, und ein
 * geteilter Link soll auch dann aufgehen, wenn der Empfänger gerade eine andere
 * Organisation ausgewählt hat.
 */
class TraceController extends Controller
{
    /**
     * Der Wasserfall einer Spur.
     */
    public function show(TraceRequest $request, string $trace): InertiaResponse
    {
        // Kennungen sind Hex-Zeichen, die Schreibweise ist je SDK verschieden.
        // Abgelegt werden sie klein ({@see \App\Support\Ingest\Normalization\Sections\Contexts}),
        // gesucht wird deshalb ebenso — sonst fände ein Link aus einem Protokoll
        // mit Großbuchstaben nichts.
        $trace = strtolower($trace);

        $projectIds = self::visibleProjectIds($request->user());
        $selected = $request->span();

        return Inertia::render('traces/Show', [
            'trace' => $trace,
            // Als Funktion und nicht als fertiger Wert: wird nur der geöffnete
            // Schritt nachgeladen, soll die ganze Spur nicht ein zweites Mal
            // gelesen und zusammengesetzt werden. Inertia wertet eine Funktion
            // bei einer Teil-Aktualisierung nur aus, wenn sie angefordert ist.
            'waterfall' => function () use ($trace, $projectIds): array {
                $view = TraceView::load($trace, $projectIds);

                return [
                    'rows' => $view->rows(),
                    'startedAt' => $view->startedAt?->toIso8601String(),
                    'durationUs' => $view->durationUs,
                    'transactions' => $view->transactionCount,
                    'spans' => $view->spanCount,
                    'errors' => $view->errorCount,
                    'services' => $view->services,
                    'truncated' => $view->truncated,
                    // Fehler, die zur Spur gehören, aber zu keinem ihrer
                    // Schritte. Sie stehen über dem Wasserfall — verschwiegen
                    // würden sie sonst genau dann, wenn die Spur unvollständig
                    // ist, also wenn sie am meisten sagen.
                    'looseErrors' => array_map(
                        fn (TraceError $error): array => $error->toArray(),
                        $view->unassignedErrors,
                    ),
                    'limits' => [
                        'transactions' => TraceView::TRANSACTION_LIMIT,
                        'spans' => TraceView::SPAN_LIMIT,
                        'errors' => TraceView::ERROR_LIMIT,
                    ],
                ];
            },
            'selected' => $selected,
            'span' => fn (): ?array => $selected === null
                ? null
                : TraceDetail::find($trace, $selected, $projectIds),
        ]);
    }

    /**
     * Von einem Fehler in seine Spur — „was passierte im selben Aufruf?".
     *
     * Eine Weiterleitung und keine eigene Ansicht: der Fehler kennt seine Spur,
     * die Ansicht kennt nur Spuren. Damit trägt jede Fehlerseite einen Link, der
     * keine Spur-Kennung kennen muss, und die Adresse, auf der man landet, ist
     * dieselbe wie bei jedem anderen Weg dorthin — teilbar und wiederauffindbar.
     */
    public function fromEvent(TraceRequest $request, Event $event): RedirectResponse
    {
        $projectIds = self::visibleProjectIds($request->user());

        // Kein „gibt es nicht" mit anderer Begründung: wer den Fehler nicht
        // sehen darf, soll auch nicht erfahren, ob es ihn gibt.
        abort_unless(in_array($event->project_id, $projectIds, true), 404);

        // Eine Meldung ohne Spur ist kein Fehler in den Daten: ein SDK ohne
        // Performance-Aufzeichnung schickt keine. Es gibt dann aber auch nichts
        // anzuzeigen — und eine leere Wasserfall-Seite wäre die unklarere
        // Antwort als „diese Adresse gibt es nicht".
        abort_if($event->trace_id === null, 404);

        return redirect()->route('traces.show', [
            'trace' => $event->trace_id,
            'schritt' => $event->trace_span_id,
        ]);
    }

    /**
     * Die Projekte, die der Betrachter sehen darf.
     *
     * Alle Projekte seiner Organisationen, nicht nur die der gerade
     * ausgewählten: siehe Klassenkommentar. Eine Abfrage, unabhängig davon, wie
     * vielen Organisationen jemand angehört.
     *
     * @return list<int>
     */
    private static function visibleProjectIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return Project::query()
            ->whereIn('organization_id', $user->organizations()->select('organizations.id'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
