<?php

namespace App\Http\Requests;

use App\Enums\InboundFilterKind;
use App\Models\InboundFilterRule;
use App\Support\Ingest\Filtering\Addresses;
use App\Support\Ingest\Filtering\Browsers;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ein Eintrag in der Liste eines Eingangsfilters.
 *
 * Geprüft wird strenger als beim üblichen Formular, und der Grund ist derselbe
 * wie bei den Fingerprint-Regeln, nur schärfer: ein Eintrag entscheidet, ob
 * künftige Meldungen **entstehen**. Ein Tippfehler in einer Adresse sperrt
 * niemanden — schlimm genug, aber harmlos —, ein zu weit gefasstes Muster
 * dagegen lässt Meldungen verschwinden, ohne dass in der Liste eine Lücke zu
 * sehen wäre.
 *
 * Deshalb die beiden Prüfungen, die über „ist ein Text" hinausgehen:
 *
 * - **Ein Muster darf nicht nur aus Platzhaltern bestehen.** `*` trifft jede
 *   Meldung und leert die Fehlerliste des Projekts, ohne dass es dabei nach
 *   einem Fehler aussieht.
 * - **Adresse und Browser-Grenze werden auf ihre Form geprüft.** Beide haben
 *   eine, und ein unbrauchbarer Eintrag greift stillschweigend nie — er sieht
 *   in der Liste aus wie ein wirksamer.
 */
class InboundFilterRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Beim Ändern bleibt die Art, wie sie ist: sie zu wechseln hieße,
            // denselben Text plötzlich gegen ein anderes Feld zu halten. Wer das
            // will, legt einen neuen Eintrag an.
            'kind' => [
                $this->isMethod('POST') ? 'required' : 'prohibited',
                Rule::enum(InboundFilterKind::class)->only(
                    array_filter(InboundFilterKind::cases(), fn (InboundFilterKind $k): bool => $k->usesRules()),
                ),
            ],
            'expression' => ['required', 'string', 'max:500', $this->expressionRule()],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => __('inbound.rules.kind'),
            'expression' => __('inbound.rules.expression'),
        ];
    }

    /**
     * Was der Ausdruck sein muss, hängt an der Art des Eintrags.
     *
     * Beim Ändern steht die Art nicht im Formular — sie kommt dann vom
     * bestehenden Eintrag über die Route.
     */
    private function expressionRule(): Closure
    {
        $kind = $this->kind();

        return static function (string $attribute, mixed $value, callable $fail) use ($kind): void {
            if (! is_string($value)) {
                return;
            }

            $value = trim($value);

            match ($kind) {
                InboundFilterKind::IpAddress => Addresses::isValid($value)
                    ? null
                    : $fail(__('inbound.validation.address')),
                InboundFilterKind::LegacyBrowser => Browsers::isValid($value)
                    ? null
                    : $fail(__('inbound.validation.browser')),
                // Ein Muster, in dem außer Platzhaltern nichts steht, trifft
                // alles. Das ist die einzige Eingabe, die ein Projekt in einem
                // Zug still stellen kann.
                default => trim(str_replace('*', '', $value)) === ''
                    ? $fail(__('inbound.validation.too_broad'))
                    : null,
            };
        };
    }

    /**
     * Die Art, um die es geht — aus dem Formular beim Anlegen, sonst vom
     * bestehenden Eintrag.
     */
    private function kind(): ?InboundFilterKind
    {
        $rule = $this->route('inbound_filter_rule');

        if ($rule instanceof InboundFilterRule) {
            return $rule->kind;
        }

        $kind = $this->input('kind');

        return is_string($kind) ? InboundFilterKind::tryFrom($kind) : null;
    }
}
