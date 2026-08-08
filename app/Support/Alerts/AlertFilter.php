<?php

namespace App\Support\Alerts;

use App\Enums\AlertHistoryState;
use App\Enums\FilterPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Die Einschränkung des Alarm-Verlaufs: Zeitraum und Zustand.
 *
 * Sie steht in der Adresszeile (`?zeitraum=7d&zustand=critical`) und wird
 * serverseitig aufgelöst — dieselbe Wahl wie bei der globalen Filterleiste und
 * aus demselben Grund: ein geteilter Link auf „letzte 7 Tage" zeigt beim
 * Empfänger dessen letzte sieben Tage.
 *
 * **Unbekannte Werte werden übergangen, nicht abgewiesen.** Ein Link aus einer
 * älteren Fassung soll die Seite nicht zerschießen; er zeigt dann eben den
 * voreingestellten Zeitraum. Deshalb steht hier keine Prüfung, die einen Fehler
 * wirft, sondern zweimal `tryFrom` mit Rückfall.
 *
 * **Ohne eigenen Zeitraum.** {@see FilterPeriod::Custom} braucht zwei
 * Datumsfelder und einen Kalender daneben; diese Seite hat keinen. Wer den
 * genauen Ausschnitt braucht, findet ihn über den Eintrag im Verlauf — die
 * Auswahl hier beantwortet „heute Nacht" und „letzte Woche".
 */
final class AlertFilter
{
    private function __construct(
        public readonly FilterPeriod $period,
        public readonly AlertHistoryState $state,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function fromRequest(Request $request, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        $period = FilterPeriod::tryFrom((string) $request->query('zeitraum', '')) ?? FilterPeriod::default();

        if ($period === FilterPeriod::Custom) {
            $period = FilterPeriod::default();
        }

        $state = AlertHistoryState::tryFrom((string) $request->query('zustand', ''))
            ?? AlertHistoryState::default();

        return new self(
            $period,
            $state,
            $now->subHours($period->hours() ?? 24),
            $now,
        );
    }

    /**
     * Die Auswahl für die Oberfläche — Wert und Möglichkeiten in einem.
     *
     * Die Möglichkeiten kommen vom Server, damit die Auswahlfelder genau die
     * Werte tragen, die er auch annimmt: zwei getrennt gepflegte Listen wären
     * ein Filter, der sich einstellen lässt und nichts bewirkt.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period->value,
            'state' => $this->state->value,
            'periodOptions' => array_values(array_filter(
                FilterPeriod::options(),
                static fn (array $option): bool => $option['value'] !== FilterPeriod::Custom->value,
            )),
            'stateOptions' => AlertHistoryState::options(),
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
        ];
    }
}
