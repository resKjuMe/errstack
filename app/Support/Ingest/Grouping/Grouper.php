<?php

namespace App\Support\Ingest\Grouping;

use App\Enums\GroupingSource;
use App\Models\FingerprintRule;
use App\Support\Ingest\Normalization\NormalizedEvent;
use Illuminate\Support\Collection;

/**
 * Bestimmt den Fingerabdruck einer Meldung.
 *
 * Drei Verfahren, und die Rangfolge zwischen ihnen ist die eigentliche Aussage
 * dieser Klasse:
 *
 * 1. **Projektweite Regel.** Sie gewinnt, auch über die Angabe des SDK. Der
 *    Grund ist die Beweislast: eine Regel wurde von Hand angelegt, weil das
 *    Grouping in **diesem** Projekt daneben lag — häufig gerade weil ein SDK
 *    einen unbrauchbaren Fingerabdruck schickt. Gewönne das SDK, wäre die Regel
 *    genau dort wirkungslos, wo sie gebraucht wird, und niemand könnte
 *    eingreifen, ohne die überwachte Anwendung neu auszuliefern.
 * 2. **Eigene Angabe des SDK.** Wer sie setzt, weiß etwas über seinen Code, das
 *    aus der Meldung nicht hervorgeht.
 * 3. **Standardverfahren** ({@see DefaultFingerprint}).
 *
 * Die ersten beiden dürfen mit `{{ default }}` auf das dritte zurückgreifen —
 * deshalb wird es **immer** gerechnet, auch wenn eine Regel greift. Das kostet
 * einen Durchlauf über den Stacktrace und erspart die Alternative: zwei
 * Verfahren, zwischen denen man wählen muss, statt eines, das man verfeinert.
 */
final class Grouper
{
    public function __construct(
        private readonly DefaultFingerprint $default,
    ) {}

    public static function fromConfig(): self
    {
        $maxFrames = config('ingest.grouping.max_frames');

        return new self(new DefaultFingerprint(
            maxFrames: is_int($maxFrames) && $maxFrames > 0 ? $maxFrames : 30,
        ));
    }

    /**
     * @param  iterable<FingerprintRule>  $rules  Die aktiven Regeln des Projekts,
     *                                            in ihrer Reihenfolge.
     */
    public function fingerprint(NormalizedEvent $event, iterable $rules = []): Fingerprint
    {
        $attributes = new Attributes($event);
        $default = $this->default->for($event);

        $rule = $this->matching($rules, $attributes);

        if ($rule !== null) {
            return Fingerprint::of(
                source: GroupingSource::Rule,
                values: FingerprintTemplate::expand($rule->values(), $attributes, $default),
                components: $default->components,
                ruleId: $rule->id,
            );
        }

        $custom = $this->custom($event);

        if ($custom !== []) {
            return Fingerprint::of(
                source: GroupingSource::Custom,
                values: FingerprintTemplate::expand($custom, $attributes, $default),
                components: $default->components,
            );
        }

        return Fingerprint::fromComponents($default);
    }

    /**
     * Die erste Regel, deren sämtliche Bedingungen zutreffen.
     *
     * Die erste und nicht die beste: „am besten passend" wäre eine Wertung, die
     * niemand nachvollziehen kann, und die Reihenfolge ist einstellbar. Wer
     * eine Regel vorziehen will, verschiebt sie.
     *
     * @param  iterable<FingerprintRule>  $rules
     */
    private function matching(iterable $rules, Attributes $attributes): ?FingerprintRule
    {
        foreach ($rules as $rule) {
            if (! $rule->isUsable()) {
                continue;
            }

            foreach ($rule->conditions() as $condition) {
                if (! $condition->matches($attributes)) {
                    continue 2;
                }
            }

            return $rule;
        }

        return null;
    }

    /**
     * Die eigene Angabe des SDK.
     *
     * Ein Fingerabdruck, der **nur** aus `{{ default }}` besteht, gilt als keine
     * Angabe: manche SDKs schicken ihn als Vorgabewert mit, ohne dass jemand
     * etwas eingestellt hätte. Ihn als eigene Angabe zu werten, würde am
     * Ergebnis nichts ändern — die Bestandteile wären dieselben —, aber die
     * Begründung am Ereignis würde „eigene Angabe des SDK" behaupten, wo das
     * Standardverfahren gearbeitet hat. Genau daran scheitert später die Suche
     * nach der Ursache einer falschen Gruppierung.
     *
     * @return list<string>
     */
    private function custom(NormalizedEvent $event): array
    {
        $values = $event->fingerprint ?? [];

        if ($values === []) {
            return [];
        }

        $meaningful = array_filter(
            $values,
            static fn (string $value): bool => ! FingerprintTemplate::isDefault($value),
        );

        return $meaningful === [] ? [] : $values;
    }

    /**
     * Die Regeln eines Projekts, wie die Gruppierung sie braucht.
     *
     * @return Collection<int, FingerprintRule>
     */
    public static function rulesFor(int $projectId): Collection
    {
        return FingerprintRule::query()
            ->where('project_id', $projectId)
            ->inOrder()
            ->limit(FingerprintRule::MAX_PER_PROJECT)
            ->get();
    }
}
