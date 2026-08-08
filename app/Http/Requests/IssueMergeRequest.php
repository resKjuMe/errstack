<?php

namespace App\Http\Requests;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'issues' => ['required', 'array', 'min:2'],
            'issues.*' => ['integer', 'distinct', 'exists:issues,id'],
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

        $ids = array_map(intval(...), (array) $this->input('issues', []));

        return $this->loaded = Issue::query()->whereKey($ids)->get();
    }

    /**
     * Nicht `$issues`: eine Eigenschaft dieses Namens läge über dem
     * gleichnamigen Eingabefeld, das eine Anfrage über `__get` anbietet.
     *
     * @var Collection<int, Issue>|null
     */
    private ?Collection $loaded = null;
}
