<?php

namespace App\Http\Controllers;

use App\Enums\IssueIgnoreMode;
use App\Http\Requests\IssueActionRequest;
use App\Models\Issue;
use App\Policies\IssuePolicy;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use App\Support\Issues\IssueActionResult;
use App\Support\Issues\IssueActions;
use App\Support\Issues\IssueList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Die Aktionen an Fehler-Einträgen: erledigen, stummschalten, merken,
 * abonnieren, löschen — einzeln und als Sammelaktion.
 *
 * **Ein Endpunkt für beides.** Die Detailseite schickt eine Kennung, die Liste
 * schickt fünfzig oder den Schalter „alle". Zwei Endpunkte wären zwei Stellen
 * mit derselben Rechteprüfung, demselben Verlaufseintrag und derselben Meldung —
 * und erfahrungsgemäß behielte einer davon eine Kleinigkeit für sich.
 *
 * **Der Rückweg liegt in der Sitzung, nicht im Formular.** „Rückgängig" braucht
 * die betroffenen Kennungen; sie in die Seite zu schreiben hieße, sie beim Klick
 * ungeprüft zurückzunehmen — wer die Antwort abfängt, könnte an ihrer Stelle
 * beliebige Kennungen einsetzen. Die Oberfläche bekommt deshalb nur eine
 * Kennmarke, und was dahintersteht, weiß allein der Server.
 */
class IssueActionController extends Controller
{
    /**
     * Der Schlüssel, unter dem der Rückweg in der Sitzung liegt.
     *
     * Genau einer und keine Liste: „Rückgängig" meint die letzte Aktion. Eine
     * Historie in der Sitzung wäre ein zweites Gedächtnis neben dem
     * Aktivitätsverlauf, nur ohne dessen Haltbarkeit.
     */
    private const UNDO_KEY = 'issues.undo';

    public function store(IssueActionRequest $request): RedirectResponse
    {
        $actions = new IssueActions($request->user());
        $action = (string) $request->validated('action');
        $query = $this->target($request);

        $result = match ($action) {
            'resolve' => $actions->resolve($query, $request->resolveMode()),
            'unresolve' => $actions->unresolve($query),
            'ignore' => $actions->ignore($query, $request->ignoreMode(), $request->threshold(), $request->window()),
            'bookmark' => $actions->bookmark($query, true),
            'unbookmark' => $actions->bookmark($query, false),
            'subscribe' => $actions->subscribe($query, true),
            'unsubscribe' => $actions->subscribe($query, false),
            'delete' => $actions->delete($query, discard: false),
            'discard' => $actions->delete($query, discard: true),
        };

        if ($result->count === 0) {
            // Kein Treffer heißt in aller Regel: die Auswahl ist inzwischen weg
            // — jemand anderes war schneller, oder die Seite stand lange offen.
            // Als Erfolg zu melden wäre die bequeme und die falsche Antwort.
            return back()->with('error', __('issues.actions.flash.none'));
        }

        return back()
            ->with('status', $this->message($action, $request, $result))
            ->with('undo', $this->remember($action, $result));
    }

    /**
     * Der Rückweg der zuletzt ausgeführten Aktion.
     *
     * Die Kennmarke wird beim Einlösen entfernt: zweimal „Rückgängig" auf
     * derselben Meldung — der Doppelklick, den jede Schaltfläche irgendwann
     * abbekommt — soll nicht die Aktion davor mitnehmen.
     */
    public function undo(Request $request): RedirectResponse
    {
        $undo = $request->session()->get(self::UNDO_KEY);
        $token = (string) $request->input('token');

        if (! is_array($undo) || $token === '' || ($undo['token'] ?? null) !== $token) {
            return back()->with('error', __('issues.actions.flash.undo_expired'));
        }

        $request->session()->forget(self::UNDO_KEY);

        $actions = new IssueActions($request->user());
        $ids = array_map('intval', $undo['issues'] ?? []);

        // Auch der Rückweg prüft die Rechte neu. Dass die Kennungen aus der
        // eigenen Sitzung stammen, sagt nur, dass sie einmal erlaubt waren —
        // eine Mitgliedschaft kann seither enden.
        $query = $ids === []
            ? null
            : IssuePolicy::scopeFor(Issue::query()->whereIn('issues.id', $ids), $request->user());

        $count = match ((string) ($undo['action'] ?? '')) {
            'unresolve' => $query === null ? 0 : $actions->unresolve($query)->count,
            'bookmark' => $query === null ? 0 : $actions->bookmark($query, true)->count,
            'unbookmark' => $query === null ? 0 : $actions->bookmark($query, false)->count,
            'subscribe' => $query === null ? 0 : $actions->subscribe($query, true)->count,
            'unsubscribe' => $query === null ? 0 : $actions->subscribe($query, false)->count,
            'undiscard' => $actions->undiscard($this->ownDiscards($request, $undo['discards'] ?? [])),
            default => 0,
        };

        return $count === 0
            ? back()->with('error', __('issues.actions.flash.undo_expired'))
            : back()->with('status', __('issues.actions.flash.undone'));
    }

    /**
     * Die Menge, auf die die Aktion wirkt.
     *
     * Zwei Wege, und beide enden in einer Abfrage, die nur Einträge zulässt, an
     * denen der Betrachter arbeiten darf:
     *
     *   `all`      — genau die Abfrage der Liste, samt Filter, Suche, Zustand
     *                und Merkmal. Sie ist über {@see GlobalFilter}
     *                bereits auf die Projekte der Mitgliedschaft eingeschränkt.
     *   Kennungen  — die Einschränkung steht ausdrücklich davor
     *                ({@see IssuePolicy::scopeFor()}). Eine geratene Kennung ist
     *                ein Aufruf wie jeder andere.
     *
     * @return Builder<Issue>
     */
    private function target(IssueActionRequest $request): Builder
    {
        if ($request->targetsAll()) {
            return IssueList::query(
                $request->filter(),
                $request->sort(),
                $request->status(),
                $request->search(),
                $request->tag(),
                // Die Sortierung der Liste ist für eine Aktion ohne Bedeutung und
                // stört das blockweise Abarbeiten, das nach der Kennung ordnet.
            )->reorder();
        }

        return IssuePolicy::scopeFor(
            Issue::query()->whereIn('issues.id', $request->issueIds()),
            $request->user(),
        );
    }

    /**
     * Legt den Rückweg in der Sitzung ab und gibt zurück, was die Seite davon
     * erfährt: eine Kennmarke und eine Beschriftung.
     *
     * @return array{token: string, label: string}|null
     */
    private function remember(string $action, IssueActionResult $result): ?array
    {
        $inverse = match ($action) {
            'resolve', 'ignore' => 'unresolve',
            'bookmark' => 'unbookmark',
            'unbookmark' => 'bookmark',
            'subscribe' => 'unsubscribe',
            'unsubscribe' => 'subscribe',
            // Löschen ohne Verwerfen ist endgültig — dafür gibt es keinen
            // Rückweg, und einen anzubieten wäre schlimmer als keiner.
            'discard' => 'undiscard',
            default => null,
        };

        if ($inverse === null || ! $result->isUndoable()) {
            // Den alten Eintrag wegräumen: sonst zeigt die nächste Meldung
            // zwar keine Schaltfläche mehr, in der Sitzung stünde aber noch der
            // Rückweg einer Aktion von vorhin.
            session()->forget(self::UNDO_KEY);

            return null;
        }

        $token = (string) Str::uuid();

        session()->put(self::UNDO_KEY, [
            'token' => $token,
            'action' => $inverse,
            'issues' => $result->undoIds,
            'discards' => $result->discards,
        ]);

        return [
            'token' => $token,
            // Beim Löschen nimmt der Rückweg nur die Verwerfung zurück, nicht
            // die Löschung. Die Beschriftung sagt das, statt „Rückgängig" zu
            // versprechen und die Hälfte zu tun.
            'label' => __($inverse === 'undiscard'
                ? 'issues.actions.undo.discard'
                : 'issues.actions.undo.default'),
        ];
    }

    /**
     * Die Meldung zur Aktion — mit Anzahl und, wo es eine gibt, der Bedingung.
     */
    private function message(string $action, IssueActionRequest $request, IssueActionResult $result): string
    {
        $count = Formats::number($result->count);

        if ($action === 'ignore') {
            $mode = $request->ignoreMode();

            return __('issues.actions.flash.ignored', [
                'count' => $count,
                'condition' => $this->conditionLabel($mode, $request->threshold(), $request->window()),
            ]);
        }

        if ($action === 'resolve') {
            return __('issues.actions.flash.resolved', [
                'count' => $count,
                'condition' => $request->resolveMode()->label(),
            ]);
        }

        return __('issues.actions.flash.'.$action, ['count' => $count]);
    }

    /**
     * Die Bedingung einer Stummschaltung in Worten.
     */
    private function conditionLabel(IssueIgnoreMode $mode, ?int $count, ?int $window): string
    {
        return match (true) {
            $mode === IssueIgnoreMode::UntilCount && $window !== null => __('issues.actions.condition.count_window', [
                'count' => Formats::number((int) $count),
                'minutes' => Formats::number($window),
            ]),
            $mode === IssueIgnoreMode::UntilCount => __('issues.actions.condition.count', [
                'count' => Formats::number((int) $count),
            ]),
            $mode === IssueIgnoreMode::UntilUsers => __('issues.actions.condition.users', [
                'count' => Formats::number((int) $count),
            ]),
            default => $mode->label(),
        };
    }

    /**
     * Von den gemerkten Verwerfungen nur die, deren Projekt dem Betrachter
     * offensteht.
     *
     * @param  list<array{project?: int, fingerprint?: string}>|mixed  $discards
     * @return list<array{project: int, fingerprint: string}>
     */
    private function ownDiscards(Request $request, mixed $discards): array
    {
        if (! is_array($discards) || $discards === []) {
            return [];
        }

        $projectIds = array_values(array_unique(array_map(
            static fn (array $entry): int => (int) ($entry['project'] ?? 0),
            $discards,
        )));

        $allowed = DB::table('projects')
            ->join('memberships', 'memberships.organization_id', '=', 'projects.organization_id')
            ->where('memberships.user_id', $request->user()->id)
            ->whereIn('projects.id', $projectIds)
            ->pluck('projects.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_filter(
            array_map(static fn (array $entry): array => [
                'project' => (int) ($entry['project'] ?? 0),
                'fingerprint' => (string) ($entry['fingerprint'] ?? ''),
            ], $discards),
            static fn (array $entry): bool => $entry['fingerprint'] !== ''
                && in_array($entry['project'], $allowed, strict: true),
        ));
    }
}
