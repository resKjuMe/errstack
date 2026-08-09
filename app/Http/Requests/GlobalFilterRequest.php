<?php

namespace App\Http\Requests;

use App\Enums\FilterPeriod;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\Filters\CurrentFilter;
use App\Support\Filters\FilterQuery;
use App\Support\Filters\GlobalFilter;
use App\Support\Filters\RememberedFilter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Die globale Filterleiste als Eingabe: dieselben Felder für jede
 * Auswertungsseite. Der Zustand steht vollständig in der Adresszeile, damit ein
 * Neuladen ihn behält und ein geteilter Link dieselbe Auswahl zeigt.
 *
 * Fehlt sie dort — beim ersten Aufruf, nach dem Anmelden, über einen Link ohne
 * Parameter —, tritt der zuletzt benutzte Stand ein ({@see RememberedFilter}).
 * Die Adresse bleibt damit die Wahrheit; der gemerkte Stand füllt nur die Lücke,
 * die sie lässt.
 *
 * Geprüft wird nur die Form; welche Projekte und Umgebungen es gibt, entscheidet
 * {@see GlobalFilter} — ein Link auf ein gelöschtes Projekt soll die Seite nicht
 * mit einem Fehler beantworten, sondern ohne diese Einschränkung zeigen.
 */
class GlobalFilterRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'projects' => ['nullable', 'array'],
            'projects.*' => ['string', 'max:255'],
            'environment' => ['nullable', 'string', 'max:'.Environment::NAME_LIMIT],
            'period' => ['nullable', Rule::enum(FilterPeriod::class)],
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'required_if:period,custom'],
            'tz' => ['nullable', 'string', 'timezone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => __('validation.messages.range_reversed'),
            'from.required_if' => __('validation.messages.range_from_missing'),
            'to.required_if' => __('validation.messages.range_to_missing'),
        ];
    }

    /**
     * Der aufgelöste Filter für die Organisation, die in der Adresse steht
     * ({@see CurrentOrganization}) — nicht für die zuletzt angesehene.
     *
     * Er wird an der laufenden Anfrage hinterlegt ({@see CurrentFilter}): der
     * Rahmen zeichnet die Filterleiste daraus, ohne ihn ein zweites Mal
     * aufzulösen. Dass eine Seite ihn überhaupt anfordert, ist zugleich das
     * Kennzeichen, dass sie eine Auswertungsseite ist und die Leiste bekommt.
     *
     * Steht in der Adresse keine Auswahl, tritt der zuletzt benutzte Stand an
     * ihre Stelle ({@see RememberedFilter}) — dadurch überlebt die Auswahl den
     * Seitenwechsel und die Abmeldung. Steht dort eine, gewinnt sie; die
     * Rangfolge und ihr Grund stehen bei {@see FilterQuery::isExplicit()}.
     */
    public function filter(): GlobalFilter
    {
        return CurrentFilter::remember(request(), function (): GlobalFilter {
            $user = $this->user();
            $organization = CurrentOrganization::for($this);

            $filter = GlobalFilter::resolve($organization, $user, [
                ...$this->selection($user, $organization),
                // Die Zeitzone kommt immer aus der Adresse: sie sagt, in welcher
                // Uhr gerechnet wird, und gehört damit zum Browser und nicht zur
                // gemerkten Auswahl.
                'tz' => $this->stringOrNull($this->validated('tz')),
            ]);

            RememberedFilter::remember($user, $filter);

            return $filter;
        });
    }

    /**
     * Die Auswahl dieses Aufrufs: was in der Adresse steht — und wenn dort
     * nichts steht, der gemerkte Stand.
     *
     * @return array{projects: list<string>, environment: string|null, period: string|null, from: string|null, to: string|null}
     */
    private function selection(User $user, ?Organization $organization): array
    {
        if (! FilterQuery::isExplicit($this)) {
            return [
                'projects' => [],
                'environment' => null,
                'period' => null,
                'from' => null,
                'to' => null,
                ...RememberedFilter::for($user, $organization),
            ];
        }

        $projects = $this->validated('projects');

        return [
            'projects' => is_array($projects)
                ? array_values(array_map(fn (mixed $slug): string => (string) $slug, $projects))
                : [],
            'environment' => $this->stringOrNull($this->validated('environment')),
            'period' => $this->stringOrNull($this->validated('period')),
            'from' => $this->stringOrNull($this->validated('from')),
            'to' => $this->stringOrNull($this->validated('to')),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
