<?php

namespace App\Http\Controllers;

use App\Enums\OwnershipMatcher;
use App\Http\Requests\OwnershipRuleRequest;
use App\Models\Organization;
use App\Models\OwnershipRule;
use App\Models\Project;
use App\Support\Issues\IssueAssignee;
use App\Support\Ownership\CodeownersImport;
use App\Support\Ownership\Ownership;
use App\Support\Ownership\OwnershipAssignment;
use App\Support\Ownership\OwnershipSubjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Die Zuständigkeits-Regeln eines Projekts: anlegen, ändern, verschieben,
 * löschen, importieren — und ausprobieren.
 *
 * Ansehen darf jedes Mitglied: die Liste ist die Antwort auf „warum steht mein
 * Name an diesem Fehler?". Ändern darf nur die Verwaltung
 * ({@see App\Policies\ProjectPolicy::manageOwnership()}).
 *
 * **Rückwirkend ändert sich nichts.** Eine neue Regel weist keine bestehenden
 * Fehler zu — sie gilt ab dem nächsten neuen. Das ist eine Entscheidung und
 * keine Einschränkung: eine Regel, die beim Speichern zwölftausend Einträge
 * umverteilt, wäre in dem Moment nicht mehr zurückzunehmen, in dem die
 * Benachrichtigungen draußen sind. Wer bestehende Fehler verteilen will, nimmt
 * die Sammelaktion der Fehlerliste — dort sieht er vorher, was er anfasst.
 */
class OwnershipRuleController extends Controller
{
    public function index(Organization $organization, Project $project): InertiaResponse
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Ownership', [
            'organization' => [
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'project' => [
                'name' => $project->name,
                'href' => route('projects.show', [$organization, $project]),
                'ownershipHref' => route('projects.ownership.store', [$organization, $project]),
                'previewHref' => route('projects.ownership.preview', [$organization, $project]),
                'importHref' => route('projects.ownership.import', [$organization, $project]),
                'autoAssignHref' => route('projects.ownership.auto-assign', [$organization, $project]),
                'autoAssign' => $project->ownership_auto_assign,
            ],
            'rules' => $this->rules($organization, $project),
            'matcherOptions' => OwnershipMatcher::options(),
            'limits' => [
                'maxPerProject' => OwnershipRule::MAX_PER_PROJECT,
                'maxOwners' => OwnershipRule::MAX_OWNERS,
            ],
            'canManage' => Gate::allows('manageOwnership', $project),
        ]);
    }

    public function store(OwnershipRuleRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageOwnership', $project);

        if ($project->ownershipRules()->count() >= OwnershipRule::MAX_PER_PROJECT) {
            return back()->withErrors([
                'pattern' => __('ownership.validation.too_many', ['max' => OwnershipRule::MAX_PER_PROJECT]),
            ]);
        }

        $rule = new OwnershipRule($request->values());

        // Neue Regeln kommen ans Ende, und hier ist das nicht nur Gewohnheit:
        // die **letzte** zutreffende Regel gewinnt. Ans Ende gehängt ist eine
        // neue Zeile damit die Ausnahme von allem darüber — genau das, was man
        // beim Anlegen im Sinn hat.
        $rule->position = $request->has('position')
            ? (int) $request->validated('position')
            : (int) $project->ownershipRules()->max('position') + 1;

        $project->ownershipRules()->save($rule);

        return back()->with('status', __('ownership.flash.created', ['pattern' => $rule->expression()]));
    }

    public function update(
        OwnershipRuleRequest $request,
        Organization $organization,
        Project $project,
        OwnershipRule $ownership_rule,
    ): RedirectResponse {
        Gate::authorize('manageOwnership', $project);

        $ownership_rule->fill($request->values())->save();

        return back()->with('status', __('ownership.flash.updated', ['pattern' => $ownership_rule->expression()]));
    }

    /**
     * An- und abschalten.
     *
     * Eine abgeschaltete Regel bleibt samt Zuständigen stehen und zählt nicht
     * mehr mit — auch nicht als „getroffen, aber still". Solange sie aus ist,
     * gewinnt die Regel darüber, und genau darum geht es beim Ausprobieren.
     */
    public function toggle(Organization $organization, Project $project, OwnershipRule $ownership_rule): RedirectResponse
    {
        Gate::authorize('manageOwnership', $project);

        $ownership_rule->is_active = ! $ownership_rule->is_active;
        $ownership_rule->save();

        return back()->with('status', __(
            $ownership_rule->is_active ? 'ownership.flash.enabled' : 'ownership.flash.disabled',
            ['pattern' => $ownership_rule->expression()],
        ));
    }

    public function destroy(Organization $organization, Project $project, OwnershipRule $ownership_rule): RedirectResponse
    {
        Gate::authorize('manageOwnership', $project);

        $pattern = $ownership_rule->expression();

        $ownership_rule->delete();

        return back()->with('status', __('ownership.flash.deleted', ['pattern' => $pattern]));
    }

    /**
     * Die automatische Zuweisung schalten.
     *
     * Ein eigener Weg und nicht ein Feld in den Projekteinstellungen: der
     * Schalter gehört neben die Regeln, die er scharf stellt. In einer
     * Einstellungsseite, die er nicht kennt, wäre er ein Häkchen ohne Kontext —
     * und die Frage „was passiert, wenn ich das anmache?" bliebe unbeantwortet.
     */
    public function autoAssign(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('manageOwnership', $project);

        $project->ownership_auto_assign = $request->boolean('enabled');
        $project->save();

        return back()->with('status', __(
            $project->ownership_auto_assign ? 'ownership.flash.auto_on' : 'ownership.flash.auto_off',
        ));
    }

    /**
     * „Wer wäre für so ein Ereignis zuständig?"
     *
     * Die Vorschau ist nicht der Höflichkeit halber da. Ein Regelwerk, dessen
     * Wirkung sich erst am nächsten echten Fehler zeigt, wird entweder nie
     * eingeschaltet oder blind eingeschaltet — beides schlechter als eine
     * Antwort vor dem Klick. Sie rechnet mit **denselben** Klassen wie die
     * Aufnahme ({@see Ownership}, {@see OwnershipSubjects}); eine eigene
     * Auswertung wäre eine zweite Meinung, und die eine Frage, die eine Vorschau
     * beantworten muss, ist gerade „was passiert wirklich?".
     */
    public function preview(
        Request $request,
        Organization $organization,
        Project $project,
        OwnershipAssignment $assignment,
    ): JsonResponse {
        Gate::authorize('view', $project);

        $tagKey = trim((string) $request->input('tag_key', ''));
        $tagValue = trim((string) $request->input('tag_value', ''));

        $subjects = OwnershipSubjects::of(
            path: self::text($request->input('path')),
            url: self::text($request->input('url')),
            module: self::text($request->input('module')),
            tags: $tagKey === '' || $tagValue === '' ? [] : [$tagKey => $tagValue],
        );

        $matches = Ownership::matching($subjects, Ownership::rulesFor($project->id));
        $winner = $matches === [] ? null : $matches[array_key_last($matches)];
        $suggestions = $assignment->suggest($subjects, $project);

        return response()->json([
            'empty' => $subjects->isEmpty(),
            'matches' => array_map(fn (OwnershipRule $rule): array => [
                'id' => $rule->id,
                'expression' => $rule->expression(),
                'owners' => $rule->owners,
                'winner' => $winner !== null && $rule->id === $winner->id,
            ], $matches),
            // Wer die Zuweisung bekäme — der erste auflösbare Zuständige der
            // gewinnenden Regel. Er steht getrennt von den Treffern, weil das
            // die eigentliche Frage ist: eine Regel kann zutreffen und trotzdem
            // niemanden benennen, den es hier noch gibt.
            'assignee' => $suggestions === [] ? null : self::assignee($suggestions[0]),
            'suggestions' => array_map(self::assignee(...), $suggestions),
            'autoAssign' => $project->ownership_auto_assign,
        ]);
    }

    /**
     * Übernimmt eine CODEOWNERS-Datei.
     *
     * **Angehängt und nicht ersetzt.** Ein Import, der die Liste leerräumt,
     * wäre die naheliegende Lesart von „übernehmen" und die gefährliche: die
     * von Hand geschriebenen Ausnahmen wären weg, und zwar still. Angehängt
     * heißt zugleich, dass die importierten Zeilen die bestehenden
     * **überstimmen** — die letzte zutreffende gewinnt —, und das ist beim
     * zweiten Import derselben Datei genau der Grund, vorher aufzuräumen.
     */
    public function import(
        Request $request,
        Organization $organization,
        Project $project,
        CodeownersImport $import,
    ): RedirectResponse {
        Gate::authorize('manageOwnership', $project);

        $validated = $request->validate([
            'contents' => ['required', 'string', 'max:200000'],
        ]);

        $parsed = $import->parse((string) $validated['contents'], $organization);

        $position = (int) $project->ownershipRules()->max('position');
        $free = OwnershipRule::MAX_PER_PROJECT - $project->ownershipRules()->count();

        if ($free < 1) {
            return back()->withErrors([
                'contents' => __('ownership.validation.too_many', ['max' => OwnershipRule::MAX_PER_PROJECT]),
            ]);
        }

        // Die Reihenfolge der Datei bleibt die Reihenfolge der Liste — nur so
        // bedeutet die importierte Liste dasselbe wie die Datei.
        $taken = array_slice($parsed['rules'], 0, $free);

        foreach ($taken as $draft) {
            $project->ownershipRules()->save(new OwnershipRule([
                'matcher' => $draft['matcher'],
                'pattern' => $draft['pattern'],
                'owners' => $draft['owners'],
                'source' => OwnershipRule::SOURCE_CODEOWNERS,
                'position' => ++$position,
            ]));
        }

        return back()->with('status', __('ownership.flash.imported', [
            'count' => count($taken),
            'skipped' => count($parsed['skipped']) + (count($parsed['rules']) - count($taken)),
        ]));
    }

    /**
     * Die Regeln für die Anzeige.
     *
     * @return list<array<string, mixed>>
     */
    private function rules(Organization $organization, Project $project): array
    {
        return $project->ownershipRules()
            ->inOrder()
            ->get()
            ->map(fn (OwnershipRule $rule): array => [
                'id' => $rule->id,
                'matcher' => $rule->matcher->value,
                'tag_key' => $rule->tag_key,
                'pattern' => $rule->pattern,
                'owners' => $rule->owners,
                'expression' => $rule->expression(),
                'source' => $rule->source,
                'position' => $rule->position,
                'active' => $rule->is_active,
                'href' => route('projects.ownership.update', [$organization, $project, $rule]),
                'toggleHref' => route('projects.ownership.toggle', [$organization, $project, $rule]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{value: string, label: string, kind: string}
     */
    private static function assignee(IssueAssignee $assignee): array
    {
        return [
            'value' => $assignee->term(),
            'label' => $assignee->label(),
            'kind' => $assignee->kind(),
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
