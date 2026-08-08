<?php

namespace App\Http\Requests;

use App\Enums\IssueSort;
use App\Models\SavedSearch;
use App\Support\Search\SearchExpression;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Eine gespeicherte Suche als Eingabe: Name, Suchausdruck, Sortierung, Freigabe.
 *
 * **Der Ausdruck wird hier nicht auf Verständlichkeit geprüft.** Er darf
 * gespeichert werden, auch wenn er nicht aufgeht — dieselbe Haltung wie in der
 * Fehlerliste selbst ({@see SearchExpression}): eine
 * unverständliche Eingabe wird benannt, nicht abgewiesen. Ein Speichern, das
 * mit „ungültiger Ausdruck" abbricht, während die Liste daneben denselben
 * Ausdruck klaglos annimmt und erklärt, wäre zweierlei Maß — und zwar an der
 * Stelle, an der man gerade dabei ist, etwas auszuprobieren.
 *
 * Der Zeitraum fehlt in dieser Liste, und das ist kein Versehen: er gehört zur
 * Filterleiste und ausdrücklich nicht zur Suche (siehe {@see SavedSearch}).
 */
class SavedSearchRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:'.SavedSearch::NAME_LIMIT,
                // Zweimal derselbe Name im eigenen Bestand wäre in der
                // Auswahlliste zweimal dasselbe Wort — und welche der beiden
                // gerade wirkt, sähe man nicht. Die Datenbank hält denselben
                // Schlüssel; hier steht er, damit daraus eine Meldung am Feld
                // wird und kein abgebrochener Aufruf.
                Rule::unique('saved_searches', 'name')
                    ->where('organization_id', $this->organizationId())
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('search')),
            ],
            'q' => ['nullable', 'string', 'max:'.SavedSearch::QUERY_LIMIT],
            'sort' => ['nullable', Rule::enum(IssueSort::class)],
            'shared' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Die Organisation, in der der Name eindeutig sein muss.
     *
     * Beim Ändern ist es die der Suche und nicht die gerade aktive: wer die
     * Organisation gewechselt hat und einen alten Reiter absendet, soll seine
     * Suche nicht in eine andere schieben.
     */
    private function organizationId(): ?int
    {
        $search = $this->route('search');

        if ($search instanceof SavedSearch) {
            return $search->organization_id;
        }

        return $this->user()->resolveCurrentOrganization()?->id;
    }

    /**
     * Der Name wird mit umschließenden Leerzeichen geprüft, wie er ankommt —
     * gespeichert wird er ohne. Damit „ Offen " und „Offen" als derselbe Name
     * gelten, wird vor der Prüfung beschnitten.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    /**
     * Der Suchausdruck — unverändert bis auf umschließende Leerzeichen.
     *
     * Nicht `query()`: so heißt bereits der Zugriff auf die Adresszeile
     * ({@see Request::query()}), und ihn zu überschreiben wäre
     * ein anderer Rückgabetyp unter demselben Namen.
     */
    public function expression(): string
    {
        return trim((string) ($this->validated('q') ?? ''));
    }

    public function sort(): IssueSort
    {
        return IssueSort::tryFrom((string) $this->validated('sort')) ?? IssueSort::default();
    }

    public function shared(): bool
    {
        return (bool) $this->validated('shared');
    }
}
