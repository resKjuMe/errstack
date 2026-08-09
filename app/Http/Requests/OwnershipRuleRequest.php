<?php

namespace App\Http\Requests;

use App\Enums\OwnershipMatcher;
use App\Models\OwnershipRule;
use App\Support\Issues\IssueAssignee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Angaben zu einer Zuständigkeits-Regel.
 *
 * Zwei Prüfungen gehen über das Übliche hinaus, und beide verhindern denselben
 * stillen Fehlschlag — eine Regel, die dasteht und nie etwas bewirkt:
 *
 * - **Ein Merkmal braucht seinen Schlüssel.** `tag:web-*` ohne die Angabe,
 *   welches Merkmal gemeint ist, wäre „irgendein Merkmal hat diesen Wert" und
 *   trifft deshalb nie zu ({@see App\Support\Ownership\OwnershipSubjects::for()}).
 * - **`me` und `none` sind keine Zuständigen.** Beide sind Wörter der
 *   Suchsprache und bezeichnen niemanden bzw. den Betrachter — in einer Regel
 *   ist der Betrachter niemand Bestimmtes. Ohne diese Prüfung ließe sich eine
 *   Regel speichern, die aussieht, als weise sie zu.
 *
 * Was **nicht** geprüft wird: ob die genannten Zuständigen existieren. Eine
 * Regel für ein Team, das nächste Woche angelegt wird, ist zulässig — und ein
 * Konto, das später austritt, macht eine bestehende Regel nicht ungültig. Die
 * Auflösung passiert beim Zuweisen, und die Vorschau zeigt sofort, wenn sie ins
 * Leere geht.
 */
class OwnershipRuleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'matcher' => ['required', Rule::enum(OwnershipMatcher::class)],
            'tag_key' => ['nullable', 'string', 'max:64'],
            'pattern' => ['required', 'string', 'max:'.OwnershipRule::PATTERN_LIMIT],

            'owners' => ['required', 'array', 'min:1', 'max:'.OwnershipRule::MAX_OWNERS],
            'owners.*' => ['required', 'string', 'max:255'],

            'position' => ['sometimes', 'integer', 'min:0', 'max:'.(OwnershipRule::MAX_PER_PROJECT - 1)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Die Werte, wie sie am Datensatz stehen.
     *
     * Die Umwandlung steht hier und nicht im Controller, weil sie beim Anlegen
     * und beim Ändern dieselbe ist — und weil `owners` als Liste in die Spalte
     * geht, während das Formular sie als Liste **von Textfeldern** schickt, in
     * der eine leere Zeile das Normalste der Welt ist.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $validated = $this->validated();

        $matcher = OwnershipMatcher::from((string) $validated['matcher']);

        return [
            'matcher' => $matcher,
            // Der Schlüssel ist nur bei einem Merkmal etwas wert. Ihn bei den
            // übrigen Arten stehen zu lassen wäre eine Angabe, die niemand
            // liest und die beim nächsten Umschalten der Art wieder auftaucht.
            'tag_key' => $matcher->needsKey() ? trim((string) ($validated['tag_key'] ?? '')) : null,
            'pattern' => trim((string) $validated['pattern']),
            'owners' => self::owners($validated['owners'] ?? []),
            ...array_intersect_key($validated, array_flip(['position', 'is_active'])),
        ];
    }

    protected function prepareForValidation(): void
    {
        $owners = $this->input('owners');

        if (is_array($owners)) {
            // Leere Zeilen fallen **vor** der Prüfung weg. Ohne das müsste
            // jedes Formular seine eigenen leeren Felder aufräumen, und die
            // Meldung „owners.2 ist erforderlich" wäre die Antwort auf ein Feld,
            // das der Nutzer bewusst leer gelassen hat.
            $this->merge(['owners' => array_values(array_filter(
                array_map(static fn (mixed $owner): string => is_string($owner) ? trim($owner) : '', $owners),
                static fn (string $owner): bool => $owner !== '',
            ))]);
        }
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $matcher = OwnershipMatcher::tryFrom((string) $this->input('matcher'));

                if ($matcher?->needsKey() === true && trim((string) $this->input('tag_key')) === '') {
                    $validator->errors()->add('tag_key', __('ownership.validation.tag_key_required'));
                }

                foreach ((array) $this->input('owners', []) as $index => $owner) {
                    if (! is_string($owner) || ! self::namesSomebody($owner)) {
                        $validator->errors()->add("owners.{$index}", __('ownership.validation.owner_invalid'));
                    }
                }
            },
        ];
    }

    /**
     * Bezeichnet dieser Text jemand Bestimmtes?
     *
     * `none` scheidet über {@see IssueAssignee::means()} aus, `me` zusätzlich:
     * es bezeichnet **jemanden**, aber niemanden Bestimmten — für die Suche ist
     * das richtig, für eine Regel nicht.
     */
    private static function namesSomebody(string $owner): bool
    {
        return IssueAssignee::means($owner)
            && mb_strtolower(trim($owner)) !== IssueAssignee::SELF;
    }

    /**
     * @param  array<array-key, mixed>  $owners
     * @return list<string>
     */
    private static function owners(array $owners): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $owner): string => is_string($owner) ? trim($owner) : '', $owners),
            static fn (string $owner): bool => $owner !== '',
        )));
    }
}
