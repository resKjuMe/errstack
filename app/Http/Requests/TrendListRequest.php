<?php

namespace App\Http\Requests;

use App\Enums\TrendDirection;
use App\Enums\TrendListSort;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Die Eingaben der Trend-Liste: Sortierung, Richtung, Bearbeitungsstand.
 *
 * Aufgebaut wie {@see PerformanceIssueListRequest} und aus demselben Grund: der
 * Zustand der Ansicht steht vollständig in der Adresszeile, damit sie das
 * Neuladen übersteht und sich als Link teilen lässt — „sieh dir das an" ist bei
 * einer Verschlechterung die häufigste nächste Handlung.
 */
class TrendListRequest extends GlobalFilterRequest
{
    /**
     * Der Wert für „beide Richtungen".
     */
    public const DIRECTION_ANY = 'alle';

    /**
     * Die Werte des Bearbeitungsstands. „Offen" ist der Vorgabewert: die Liste
     * ist eine Arbeitsliste, und was jemand abgehakt hat, gehört nicht mehr
     * obenauf.
     */
    public const SEEN_OPEN = 'offen';

    public const SEEN_DONE = 'gesehen';

    public const SEEN_ANY = 'alle';

    /**
     * @return array<string, list<ValidationRule|string>>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'sort' => ['nullable', Rule::enum(TrendListSort::class)],
            'direction' => ['nullable', Rule::in([
                self::DIRECTION_ANY,
                TrendDirection::Worse->value,
                TrendDirection::Better->value,
            ])],
            'seen' => ['nullable', Rule::in([self::SEEN_OPEN, self::SEEN_DONE, self::SEEN_ANY])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function sort(): TrendListSort
    {
        return TrendListSort::tryFrom((string) $this->validated('sort')) ?? TrendListSort::default();
    }

    /**
     * Die gewählte Richtung — `null` heißt „beide".
     *
     * Ohne Angabe beide, und das ist der Auftrag: die Liste zeigt
     * verschlechterte **und** verbesserte Transaktionen. Die Reihenfolge sorgt
     * dafür, dass die Verschlechterungen trotzdem oben stehen
     * ({@see TrendListSort::Impact}).
     */
    public function direction(): ?TrendDirection
    {
        $direction = (string) ($this->validated('direction') ?? '');

        if ($direction === self::DIRECTION_ANY) {
            return null;
        }

        return TrendDirection::tryFrom($direction);
    }

    /**
     * Der gewählte Bearbeitungsstand — `null` heißt „alle".
     */
    public function seen(): ?bool
    {
        return match ((string) ($this->validated('seen') ?? self::SEEN_OPEN)) {
            self::SEEN_DONE => true,
            self::SEEN_ANY => null,
            default => false,
        };
    }

    /**
     * Die Werte, wie die Oberfläche sie in ihren Feldern führt.
     *
     * @return array{sort: string, direction: string, seen: string}
     */
    public function listValues(): array
    {
        $direction = $this->direction();

        return [
            'sort' => $this->sort()->value,
            'direction' => $direction === null ? self::DIRECTION_ANY : $direction->value,
            'seen' => match ($this->seen()) {
                true => self::SEEN_DONE,
                null => self::SEEN_ANY,
                false => self::SEEN_OPEN,
            },
        ];
    }
}
