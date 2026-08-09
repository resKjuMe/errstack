<?php

namespace App\Support\Releases\Health;

use App\Support\Formats;

/**
 * Die Gesundheit einer Auslieferung, wie sie auf der Seite steht.
 *
 * Die eine Stelle, an der aus einer Quote eine Anzeige wird — Liste wie
 * Detailseite. Zweimal geschrieben wären es zwei Rundungen, zwei Schreibweisen
 * und irgendwann zwei Antworten auf dieselbe Frage.
 *
 * **Was sich nicht sagen lässt, bleibt `null`.** Aus keiner einzigen Sitzung
 * folgt keine Crash-Free-Rate, schon gar nicht „100 %"
 * ({@see ReleaseHealthSummary}) — und eine Anzeige, die daraus „alles in
 * Ordnung" macht, ist die gefährlichste Zahl auf der Seite: sie steht groß und
 * grün über einer Version, aus der gerade gar nichts mehr kommt.
 */
final class ReleaseHealthData
{
    /**
     * Ab dieser Änderung in Prozentpunkten gilt der Vergleich als Bewegung.
     *
     * Ohne eine Schwelle wäre praktisch jeder Vergleich „schlechter" oder
     * „besser" — zwei Nachkommastellen sind zwischen zwei Auslieferungen nie
     * gleich. Ein Pfeil, der immer zeigt, zeigt nichts.
     */
    public const SIGNIFICANT_POINTS = 0.05;

    /**
     * Die Kennzahlen einer Auslieferung.
     *
     * @return array<string, mixed>
     */
    public static function summary(ReleaseHealthSummary $health): array
    {
        return [
            'hasData' => $health->hasData(),
            'crashFreeSessions' => self::percent($health->crashFreeSessions()),
            'crashFreeUsers' => self::percent($health->crashFreeUsers()),
            'adoptionSessions' => self::percent($health->adoptionSessions()),
            'adoptionUsers' => self::percent($health->adoptionUsers()),
            'sessions' => self::count($health->sessions->sessions),
            'crashedSessions' => self::count($health->sessions->crashed),
            'erroredSessions' => self::count($health->sessions->errored),
            'abnormalSessions' => self::count($health->sessions->abnormal),
            'healthySessions' => self::count($health->sessions->healthy()),
            'users' => self::count($health->users),
            'crashedUsers' => self::count($health->crashedUsers),
            'unhealthyUsers' => self::count($health->unhealthyUsers),
        ];
    }

    /**
     * Der Vergleich zweier Auslieferungen — der eigentliche Zweck der Zahlen.
     *
     * „99,2 % absturzfrei" allein sagt niemandem, ob die Auslieferung gut war;
     * erst „vorher waren es 99,8 %" macht daraus eine Aussage. Deshalb steht
     * hier nicht nur die zweite Zahl, sondern die **Richtung**: besser,
     * schlechter oder unverändert.
     *
     * Ohne Vorversion — die erste Auslieferung eines Projekts — gibt es keinen
     * Vergleich, und dann steht hier `null` statt einer Null. Der Unterschied
     * ist der zwischen „nichts verändert" und „nichts zu vergleichen".
     *
     * @return array<string, mixed>|null
     */
    public static function comparison(ReleaseHealthSummary $current, ?ReleaseHealthSummary $previous): ?array
    {
        if ($previous === null) {
            return null;
        }

        return [
            'version' => $previous->release->version,
            'hasData' => $previous->hasData(),
            'crashFreeSessions' => self::delta($current->crashFreeSessions(), $previous->crashFreeSessions()),
            'crashFreeUsers' => self::delta($current->crashFreeUsers(), $previous->crashFreeUsers()),
            'adoptionSessions' => self::delta($current->adoptionSessions(), $previous->adoptionSessions()),
            'adoptionUsers' => self::delta($current->adoptionUsers(), $previous->adoptionUsers()),
        ];
    }

    /**
     * Eine Quote roh und geschrieben — oder `null`, wenn sie sich nicht sagen
     * lässt.
     *
     * Beides, wie überall in dieser Anwendung: wie eine Zahl aussieht,
     * entscheidet die Sprache, und die kennt der Server. Zwei Nachkommastellen,
     * weil der Unterschied zwischen 99,95 % und 99,5 % das Zehnfache an
     * Abstürzen ist und bei einer Stelle beides „99,9" hieße.
     *
     * @return array{value: float, label: string}|null
     */
    private static function percent(?float $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return [
            'value' => round($value, 2),
            'label' => Formats::number($value, 2).' %',
        ];
    }

    /**
     * @return array{value: int, label: string}
     */
    private static function count(int $value): array
    {
        return [
            'value' => $value,
            'label' => Formats::number($value),
        ];
    }

    /**
     * Der Abstand zweier Quoten in Prozentpunkten, samt Richtung.
     *
     * **Prozentpunkte und nicht Prozent.** Von 99,8 auf 99,6 sind es 0,2 Punkte
     * — und das ist die Zahl, die jemand sucht, der wissen will, ob es
     * schlechter geworden ist. „0,2 % weniger" wäre eine andere Rechnung mit
     * einer anderen Zahl, und beide sähen gleich aus.
     *
     * Fehlt eine der beiden Quoten — die Vorversion hat keine Sitzungen im
     * Zeitraum, oder diese hier hat keine —, gibt es keinen Abstand. Eine Null
     * an dieser Stelle hieße „unverändert" und wäre erfunden.
     *
     * @return array{value: float, label: string, direction: string}|null
     */
    private static function delta(?float $current, ?float $previous): ?array
    {
        if ($current === null || $previous === null) {
            return null;
        }

        $difference = $current - $previous;

        return [
            'value' => round($difference, 2),
            // Das Vorzeichen steht dran, auch bei einer Verbesserung: „+0,3"
            // liest sich als Bewegung, „0,3" als Wert.
            'label' => ($difference > 0 ? '+' : ($difference < 0 ? '−' : '±'))
                .Formats::number(abs($difference), 2),
            'direction' => self::direction($difference),
        ];
    }

    /**
     * Besser, schlechter oder unverändert.
     *
     * Höher ist hier immer besser: sowohl die Crash-Free-Rate als auch die
     * Verbreitung sind Zahlen, die man wachsen sehen will. Eine sinkende
     * Verbreitung ist zwar kein Fehler — die nächste Version löst sie ab —,
     * aber wer sie hier ansieht, fragt nach dem Ausrollen und nicht nach dem
     * Ablösen.
     */
    private static function direction(float $difference): string
    {
        if (abs($difference) < self::SIGNIFICANT_POINTS) {
            return 'flat';
        }

        return $difference > 0 ? 'up' : 'down';
    }
}
