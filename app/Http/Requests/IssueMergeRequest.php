<?php

namespace App\Http\Requests;

use App\Enums\IssueCategory;
use App\Models\Issue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Die Auswahl, die zusammengeführt werden soll.
 *
 * Geprüft wird nicht nur, dass es die Einträge gibt, sondern auch, dass sie
 * **zusammenpassen**: dasselbe Projekt, und keiner von ihnen bereits Untergruppe
 * eines anderen. Beides kommt in der Oberfläche gar nicht erst vor — die Liste
 * zeigt keine beigetretenen Einträge, und über Projektgrenzen hinweg wird nicht
 * ausgewählt. Genau deshalb steht es hier: was nur die Oberfläche verhindert,
 * ist nicht verhindert.
 */
class IssueMergeRequest extends FormRequest
{
    /**
     * Das Recht wird **vor** der Prüfung entschieden, und eine unbekannte
     * Kennung zählt dabei wie eine fremde.
     *
     * Das ist nicht Vorsicht, sondern schließt ein Auszählen: liefe die Prüfung
     * zuerst, wäre die Antwort auf einen fremden Eintrag „kein Recht" (403) und
     * auf einen nicht vorhandenen „gibt es nicht" (422) — und damit ließe sich
     * durch Raten von Kennungen erfahren, welche Fehler es in anderen
     * Organisationen gibt. Beides gleich zu beantworten kostet nichts; dieselbe
     * Überlegung wie bei der Detailseite ({@see App\Policies\IssuePolicy}).
     */
    public function authorize(): bool
    {
        $ids = self::ids($this->input('issues'));
        $issues = $this->issues();

        if ($ids === [] || count($ids) !== $issues->count()) {
            return false;
        }

        return $issues->every(fn (Issue $issue): bool => $this->user()?->can('merge', $issue) === true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'issues' => ['required', 'array', 'min:2'],
            // Ohne `exists`: dass es die Einträge gibt, hat {@see authorize()}
            // schon entschieden — eine zweite Antwort darauf wäre genau die,
            // die dort vermieden wird.
            'issues.*' => ['integer', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $issues = $this->issues();

            if ($issues->pluck('project_id')->unique()->count() > 1) {
                $validator->errors()->add('issues', __('issues.merge.error.mixed_projects'));
            }

            if ($issues->contains(fn (Issue $issue): bool => $issue->category !== IssueCategory::Error)) {
                // Zusammenführen ist eine Sache der Fehlerliste. Ein
                // Leistungsproblem (PF6) steht in derselben Tabelle, aber in
                // einer anderen Liste — und die lässt beigetretene Einträge
                // nicht aus ({@see App\Support\Performance\Detection\PerformanceIssueList}).
                // Ein zusammengeführtes Leistungsproblem stünde dort weiter
                // einzeln da, mit Zahlen, die schon im Kopf enthalten sind.
                $validator->errors()->add('issues', __('issues.merge.error.only_errors'));
            }

            if ($issues->contains(fn (Issue $issue): bool => $issue->isMerged())) {
                // Ein Eintrag, der bereits Untergruppe ist, würde beim Beitritt
                // seinem bisherigen Kopf die Häufigkeit lassen und dem neuen
                // eine zweite gutschreiben. Er wird zuerst herausgelöst.
                $validator->errors()->add('issues', __('issues.merge.error.already_merged'));
            }
        });
    }

    /**
     * Die gewählten Einträge, in einer Abfrage geladen.
     *
     * Einmal geladen und behalten: die Prüfung braucht sie, und der Aufruf
     * dahinter braucht dieselben — ohne das wäre es zweimal dieselbe Abfrage.
     *
     * @return Collection<int, Issue>
     */
    public function issues(): Collection
    {
        if ($this->loaded instanceof Collection) {
            return $this->loaded;
        }

        return $this->loaded = Issue::query()
            ->whereKey(self::ids($this->input('issues')))
            ->get();
    }

    /**
     * Die Kennungen der Anfrage, ohne Doppelte.
     *
     * Ohne Doppelte, weil sie gegen die Zahl der gefundenen Einträge verglichen
     * werden: zweimal dieselbe Kennung ergäbe zwei Wünsche und einen Fund, und
     * das wäre kein fehlendes Recht, sondern ein Zählfehler. Dass sie doppelt
     * gar nicht vorkommen dürfen, sagt die Prüfregel `distinct`.
     *
     * @return list<int>
     */
    private static function ids(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        return array_values(array_unique(array_map(intval(...), $input)));
    }

    /**
     * Nicht `$issues`: eine Eigenschaft dieses Namens läge über dem
     * gleichnamigen Eingabefeld, das eine Anfrage über `__get` anbietet.
     *
     * @var Collection<int, Issue>|null
     */
    private ?Collection $loaded = null;
}
