<?php

namespace App\Http\Requests;

use App\Enums\IssueIgnoreMode;
use App\Enums\IssueResolveMode;
use App\Support\Issues\IssueActions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Eine Aktion an einem oder vielen Fehlern.
 *
 * **Erbt von {@see IssueListRequest}**, und das ist der Kern der Sache: eine
 * Sammelaktion über „alle 12.480 der Auswahl" meint genau die Menge, die die
 * Liste gerade zeigt — also denselben Filter, dieselbe Suche, dieselbe
 * Merkmal-Einschränkung. Die Bedingungen ein zweites Mal zu beschreiben wäre
 * die Stelle, an der die Aktion und die Anzeige auseinanderlaufen: das Formular
 * schickt die Felder der Adresszeile mit, und hier werden sie nach denselben
 * Regeln geprüft wie beim Anzeigen.
 *
 * Zwei Wege, die Menge zu benennen, und sie schließen einander aus:
 *
 *   `issues[]` — diese Kennungen. Der Regelfall, auch für die Detailseite (dort
 *                genau eine).
 *   `all`      — alles, worauf der mitgeschickte Filter passt. Ein Schalter und
 *                keine Kennungsliste: 100.000 Zahlen in einem Formular sind
 *                keine Auswahl, sondern ein Zeitlimit.
 */
class IssueActionRequest extends IssueListRequest
{
    /**
     * Wie viele Kennungen eine Auswahl tragen darf.
     *
     * Die Liste zeigt 50 je Seite; wer mehr treffen will, nimmt „alle
     * auswählen". Die Grenze liegt darüber, weil ein Rückweg genauso viele
     * Kennungen zurückschickt wie die Aktion selbst
     * ({@see IssueActions::UNDO_LIMIT}).
     */
    public const MAX_IDS = IssueActions::UNDO_LIMIT;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'action' => ['required', 'string', Rule::in([
                'resolve', 'unresolve', 'ignore',
                'bookmark', 'unbookmark', 'subscribe', 'unsubscribe',
                'delete', 'discard',
            ])],

            'issues' => ['array', 'max:'.self::MAX_IDS],
            'issues.*' => ['integer', 'min:1'],
            'all' => ['nullable', 'boolean'],

            // Nur für `resolve` bzw. `ignore`. Die Bedingung steht in
            // withValidator(): „erforderlich, wenn" wäre hier zwar kürzer,
            // ließe aber eine Schwelle ohne passende Art durchgehen.
            'mode' => ['nullable', 'string'],
            'count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'window' => ['nullable', 'integer', 'min:1', 'max:'.(60 * 24 * 30)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $action = (string) $this->input('action');

            if ($this->targetsNothing()) {
                $validator->errors()->add('issues', __('issues.actions.validation.no_target'));
            }

            if ($action === 'resolve' && IssueResolveMode::tryFrom((string) $this->input('mode')) === null) {
                $validator->errors()->add('mode', __('issues.actions.validation.mode'));
            }

            if ($action !== 'ignore') {
                return;
            }

            $mode = IssueIgnoreMode::tryFrom((string) $this->input('mode'));

            if ($mode === null) {
                $validator->errors()->add('mode', __('issues.actions.validation.mode'));

                return;
            }

            // Eine Schwelle ohne Zahl wäre eine Stummschaltung, die nie endet —
            // also das Gegenteil dessen, was jemand gewählt hat, und still.
            if ($mode->needsCount() && $this->input('count') === null) {
                $validator->errors()->add('count', __('issues.actions.validation.count'));
            }

            if (! $mode->allowsWindow() && $this->input('window') !== null) {
                $validator->errors()->add('window', __('issues.actions.validation.window'));
            }
        });
    }

    /**
     * Die gewählten Kennungen.
     *
     * @return list<int>
     */
    public function issueIds(): array
    {
        $ids = $this->validated('issues');

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    /**
     * Meint die Aktion alles, worauf der Filter passt?
     */
    public function targetsAll(): bool
    {
        return $this->boolean('all');
    }

    public function resolveMode(): IssueResolveMode
    {
        return IssueResolveMode::tryFrom((string) $this->input('mode')) ?? IssueResolveMode::Now;
    }

    public function ignoreMode(): IssueIgnoreMode
    {
        return IssueIgnoreMode::tryFrom((string) $this->input('mode')) ?? IssueIgnoreMode::Forever;
    }

    public function threshold(): ?int
    {
        $count = $this->validated('count');

        return $count === null ? null : (int) $count;
    }

    public function window(): ?int
    {
        $window = $this->validated('window');

        return $window === null ? null : (int) $window;
    }

    /**
     * Innerhalb der Prüfung wird die **rohe** Eingabe gelesen und nicht
     * `validated()`.
     *
     * Der Grund ist kein Stilempfinden: `validated()` lässt die Regeln erneut
     * laufen, wenn sie noch nicht abgeschlossen sind — und dieser Aufruf steht
     * in einem `after`-Rückruf, also mitten darin. Die Folge wäre eine
     * Endlosschleife, und zwar erst dann, wenn jemand das Formular abschickt.
     */
    private function targetsNothing(): bool
    {
        $ids = $this->input('issues');

        return ! $this->boolean('all') && (! is_array($ids) || $ids === []);
    }
}
