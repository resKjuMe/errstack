<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileIndexRequest;
use App\Models\Profile;
use App\Models\Transaction;
use App\Support\FilterData;
use App\Support\Profiling\CallTree;
use App\Support\Profiling\FlamegraphData;
use App\Support\Profiling\ProfileComparison;
use App\Support\Profiling\ProfileOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Profiling: welche Code-Stellen die Rechenzeit verbrauchen.
 *
 * Die Performance-Übersicht (PF2) sagt, welcher Endpunkt langsam ist, und die
 * Einzelschritte einer Transaktion (PF1) sagen, wie viel davon auf Datenbank
 * und fremde Dienste ging. Was in der verbleibenden Zeit im eigenen Code
 * passiert ist, steht hier — und nur hier.
 *
 * Zwei Ansichten, weil es zwei Fragen sind:
 *
 *   Ein Profil    — „was hat **dieser** Aufruf getan?". Die Antwort auf einen
 *                   Ausreißer, den man in der Liste gefunden hat.
 *   Zusammengefasst — „was tut dieser Endpunkt üblicherweise?". Die Antwort auf
 *                   „warum ist das hier grundsätzlich langsam", und die einzige,
 *                   die sich zwischen zwei Versionen vergleichen lässt.
 *
 * Beide Wege hierher — von einer Messung und von einem Ablauf — sind eigene
 * Routen. Sie nehmen die Kennung, die der
 * Aufrufer ohnehin hat, und suchen das Profil selbst; sonst müsste jede Seite,
 * die auf ein Profil verlinken will, dessen Vorhandensein vorher abfragen.
 */
class ProfilingController extends Controller
{
    /**
     * Wie viele Profile die Liste zeigt.
     *
     * Kein Blättern: die Liste ist der Einstieg und nicht der Datenbestand. Wer
     * ein bestimmtes Profil sucht, schränkt Zeitraum und Transaktion ein — und
     * wer den Endpunkt im Ganzen ansehen will, will die Zusammenfassung und
     * nicht die fünfhundertste Zeile.
     */
    public const LIST_LIMIT = 50;

    public function index(ProfileIndexRequest $request): InertiaResponse
    {
        $filter = $request->filter();
        $name = $request->transactionName();
        $release = $request->release();
        $compare = $request->compareRelease();

        $overview = new ProfileOverview($filter, $name, $release);
        $aggregate = $overview->aggregate();

        return Inertia::render('profiling/Index', [
            'filter' => FilterData::bar($filter),
            'profiles' => $overview->profiles(self::LIST_LIMIT)
                ->map(fn (Profile $profile): array => $this->row($profile))
                ->all(),
            'listLimit' => self::LIST_LIMIT,
            'transactionName' => $name,
            'release' => $release,
            'compare' => $compare,
            'releases' => $overview->releases(),
            'aggregate' => $aggregate === null ? null : [
                'frames' => FlamegraphData::frames($aggregate->frames),
                'flamegraph' => FlamegraphData::present($aggregate),
                'profileLimit' => Profile::AGGREGATE_LIMIT,
            ],
            'comparison' => $this->comparison($overview, $aggregate, $name, $compare),
        ]);
    }

    /**
     * Ein einzelnes Profil mit seinem Flamegraph.
     */
    public function show(Profile $profile): InertiaResponse
    {
        Gate::authorize('view', $profile);

        $profile->loadMissing('transaction', 'project.organization');
        $tree = $profile->callTree();

        return Inertia::render('profiling/Show', [
            'profile' => $this->row($profile) + [
                'threadId' => $profile->thread_id,
                'platform' => $profile->platform,
            ],
            'frames' => FlamegraphData::frames($tree->frames),
            'flamegraph' => FlamegraphData::present($tree),
            'transaction' => $profile->transaction === null ? null : [
                'name' => $profile->transaction->name,
                'op' => $profile->transaction->op,
                'durationUs' => $profile->transaction->duration_us,
            ],
            // Der Weg zurück in die Breite: dasselbe, aber über alle Profile
            // dieser Transaktion. Ein einzelnes Profil kann den Ausreißer
            // erwischt haben.
            'aggregateHref' => route('profiling.index', ['transaction' => $profile->transaction_name]),
        ]);
    }

    /**
     * Der Weg von einer Messung zu ihrem Profil.
     *
     * Die Detailseite einer Transaktion (PF3) verlinkt hierher und muss dafür
     * nichts über Profile wissen — sie hat die Kennung der Messung, und das
     * genügt.
     *
     * Gibt es kein Profil, führt der Weg zur Zusammenfassung dieser Transaktion
     * statt in eine Fehlerseite: „für diesen einen Aufruf haben wir keins, für
     * den Endpunkt vielleicht schon" ist die nützlichere Antwort, und in aller
     * Regel stimmt sie — Profiling läuft mit einer eigenen Quote unter der
     * ohnehin gesiebten Transaktionsquote.
     */
    public function transaction(Transaction $transaction): RedirectResponse
    {
        Gate::authorize('view', $transaction);

        $profile = Profile::query()
            ->where('transaction_id', $transaction->id)
            ->newestFirst()
            ->first();

        return $profile === null
            ? redirect()->route('profiling.index', ['transaction' => $transaction->name])
            : redirect()->route('profiling.show', $profile);
    }

    /**
     * Der Weg von einem Ablauf zu einem Profil.
     *
     * Die Trace-Ansicht (PF4) zeigt einen Aufruf über mehrere Dienste hinweg;
     * ein Profil gibt es dabei üblicherweise nur für einen davon — den, der
     * gerechnet hat. Gesucht wird deshalb über den ganzen Ablauf und nicht über
     * ein bestimmtes Projekt.
     *
     * Die Rechteprüfung steckt in der Abfrage: sie sieht nur Profile der
     * Projekte, in denen der Betrachter Mitglied ist. Eine Kennung zu raten
     * führt damit auf dieselbe Antwort wie ein Ablauf ohne Profil.
     */
    public function trace(ProfileIndexRequest $request, string $trace): RedirectResponse
    {
        $projectIds = $request->filter()->availableProjects->pluck('id');

        $profile = Profile::query()
            ->where('trace_id', strtolower($trace))
            ->whereIn('project_id', $projectIds)
            ->newestFirst()
            ->first();

        return $profile === null
            ? redirect()->route('profiling.index')
            : redirect()->route('profiling.show', $profile);
    }

    /**
     * Der Vergleich zweier Versionen — sofern eine zweite gewählt ist.
     *
     * Die eine Seite ist die Zusammenfassung, die die Seite ohnehin zeigt; für
     * die andere werden die Bäume der Vergleichsversion geholt. Zwei Abfragen
     * und nicht eine: die Profile beider Versionen in einem Zug zu lesen und
     * hinterher zu trennen, hieße die Obergrenze
     * ({@see Profile::AGGREGATE_LIMIT}) auf beide zusammen anzuwenden — und bei
     * einer frisch ausgelieferten Version bekäme die alte alle Plätze.
     *
     * @return list<array<string, mixed>>|null
     */
    private function comparison(
        ProfileOverview $overview,
        ?CallTree $aggregate,
        ?string $name,
        ?string $compare,
    ): ?array {
        if ($name === null || $compare === null || $aggregate === null) {
            return null;
        }

        return ProfileComparison::between(CallTree::merge($overview->trees($compare)), $aggregate);
    }

    /**
     * Eine Zeile der Liste.
     *
     * @return array<string, mixed>
     */
    private function row(Profile $profile): array
    {
        return [
            'id' => $profile->id,
            'href' => route('profiling.show', $profile),
            'transactionName' => $profile->transaction_name,
            'traceId' => $profile->trace_id,
            'environment' => $profile->environment,
            'release' => $profile->release,
            'startedAt' => $profile->started_at->toIso8601String(),
            'durationUs' => $profile->duration_us,
            'sampleCount' => $profile->sample_count,
        ];
    }
}
