<?php

namespace App\Support\Performance\Trends;

use App\Enums\TrendDirection;
use App\Support\Performance\DurationHistogram;
use App\Support\Performance\TransactionTrend;

/**
 * Die Suche nach dem Punkt, an dem eine Transaktion umgeschlagen ist.
 *
 * **Warum nicht der Vergleich zweier Zeiträume.** Den gibt es bereits
 * ({@see TransactionTrend}), und für die Übersicht ist er richtig: „gegenüber
 * der Vorwoche 20 % langsamer" beantwortet die Frage, mit der jemand die Seite
 * öffnet. Eine schleichende Verschlechterung findet er nicht. Wer gestern
 * umgeschlagen ist, sieht im Wochenvergleich harmlos aus, weil sechs gute Tage
 * den einen schlechten mitteln — und wer vor drei Wochen umgeschlagen ist, ist
 * gegenüber der Vorwoche völlig unauffällig, weil beide Seiten des Vergleichs
 * längst auf der neuen Höhe liegen. Genau das ist der Fall aus dem Auftrag: die
 * Seite, die von 200 ms auf 900 ms gerutscht ist und monatelang niemandem
 * auffällt.
 *
 * Deshalb wird der Trennpunkt **gesucht** und nicht gesetzt: für jede mögliche
 * Stelle im Verlauf werden die Fenster davor mit denen danach verglichen, und
 * die Stelle mit dem deutlichsten Unterschied gewinnt.
 *
 * **Verglichen wird mit einem Rangsummentest** (Mann-Whitney-U, in der
 * Normalverteilungs-Näherung), nicht mit den Mittelwerten der beiden Seiten.
 * Drei Gründe, und alle drei stehen so im Auftrag:
 *
 *   - **Ausreißer.** Der Test kennt nur die Reihenfolge der Fenster-Perzentile,
 *     nicht ihren Abstand. Eine Stunde, in der die Datenbank hustete, ist damit
 *     ein hoher Rang und keine Verdopplung des Mittelwerts.
 *   - **Aussagekraft.** Der z-Wert wächst mit der Zahl der Fenster. Aus vier
 *     guten und vier schlechten Stunden lässt sich derselbe Unterschied nicht
 *     belegen wie aus vierzig und vierzig — und der Schwellwert
 *     ({@see MINIMUM_Z}) trennt genau das.
 *   - **Bindungen.** Die Verteilung springt in Verdopplungsschritten
 *     ({@see DurationHistogram}), gleiche Perzentile sind deshalb der Regelfall
 *     und keine Ausnahme. Der Test rechnet sie mit der üblichen Korrektur heraus;
 *     ohne sie wäre der z-Wert systematisch zu groß, und die Zusage „kein Alarm
 *     bei zu geringer Aussagekraft" wäre keine.
 *
 * **Der Schwellwert ist bewusst hoch.** Über alle Trennstellen zu suchen und
 * dann die beste zu nehmen, ist ein Mehrfachvergleich: schon in einem Verlauf
 * ohne jede Änderung findet man eine Stelle, an der die Hälften zufällig
 * auseinanderliegen. Der übliche Wert von 1,96 (5 %) wäre hier deshalb falsch —
 * er gilt für **einen** Test, nicht für hundertfünfzig. {@see MINIMUM_Z} nimmt
 * die Größenordnung dieses Aufschlags vorweg, statt eine Rechnung zu behaupten,
 * die für einen gleitenden Suchlauf ohnehin nur näherungsweise stimmt.
 *
 * Die Zahlen, die am Ende ausgewiesen werden, stammen dagegen **nicht** aus den
 * Fenster-Perzentilen, sondern aus den zusammengelegten Verteilungen beider
 * Seiten: der Test entscheidet, **ob** etwas passiert ist, die Verteilung sagt,
 * **wie viel**. Das ist die belastbarere Zahl — sie steht auf allen Messungen
 * einer Seite und nicht auf einem Perzentil je Stunde.
 *
 * **Was die Klassenbreite kostet, und warum es trotzdem reicht.** Die Verteilung
 * legt Dauern in Klassen ab, die sich je Schritt verdoppeln
 * ({@see DurationHistogram}); ein daraus gelesenes p95 springt entsprechend. Zwei
 * Folgen, die man kennen muss: eine Änderung, die keine Klassengrenze
 * überschreitet, ist gar nicht zu sehen — und eine, die es tut, wird im
 * ungünstigsten Fall um bis zum Doppelten zu groß ausgewiesen. Dass eine
 * Transaktion umgeschlagen ist und wann, steht damit fest; „um wie viel" ist eine
 * Größenordnung und keine Messung. Für die Frage, um derentwillen diese Liste
 * existiert, ist das die richtige Abwägung — dieselbe, die schon die Übersicht
 * trifft ({@see TransactionTrend}). Wer den genauen Verlauf braucht, findet ihn
 * auf der Detailseite (PF3).
 */
final class BreakpointScan
{
    /**
     * Messungen, die ein Fenster tragen muss, um überhaupt bewertet zu werden.
     *
     * Ein p95 aus drei Messungen ist die größte der drei. Solche Fenster
     * bleiben draußen, statt mit einem zufälligen Wert in den Rangsummentest zu
     * gehen — sie sind der Weg, auf dem eine einzelne langsame Antwort in einer
     * stillen Nachtstunde zu einer „Verschlechterung" würde.
     */
    public const MINIMUM_WINDOW_SAMPLES = 5;

    /**
     * Bewertbare Fenster, die auf **jeder** Seite liegen müssen.
     *
     * Sechs, und zwar auf beiden Seiten: erst dann ist eine neue Höhe ein
     * Zustand und kein Vorfall. Ein Ausschlag von zwei, drei Stunden kann damit
     * keine eigene Seite bilden — er landet zwangsläufig in einer Seite, die
     * überwiegend aus der alten Höhe besteht, und daran scheitert der Beleg. Für
     * den kurzen Ausschlag ist der Schwellwert-Alarm zuständig (A3); dort ist
     * die schnelle Meldung der Zweck, hier die Dauerhaftigkeit.
     */
    public const MINIMUM_SIDE_WINDOWS = 6;

    /**
     * Messungen, die auf **jeder** Seite zusammenkommen müssen.
     *
     * Die Mindestdatenmenge aus dem Auftrag. Sie steht neben der Zahl der
     * Fenster, weil beide Verschiedenes absichern: die Fenster sorgen dafür,
     * dass die Änderung anhält, die Messungen dafür, dass die Höhen, die
     * verglichen werden, überhaupt etwas bedeuten.
     */
    public const MINIMUM_SIDE_SAMPLES = 50;

    /**
     * Relative Änderung, unter der nichts gemeldet wird.
     *
     * Deutlich weiter als das Band der Übersicht ({@see TransactionTrend::FLAT_BAND},
     * fünf Prozent) — dort geht es um einen Pfeil neben einer Zahl, hier um eine
     * Meldung, die jemanden aus der Arbeit holt.
     *
     * In der Praxis greift die Schwelle selten: was die Klassenbreite überhaupt
     * sichtbar macht, liegt ohnehin darüber. Sie steht trotzdem da, weil die
     * zusammengelegten Verteilungen zweier Seiten sich auch um einen Bruchteil
     * einer Klasse unterscheiden können — dann ist der Unterschied ein Artefakt
     * der Mischung und keine Verschiebung.
     */
    public const MINIMUM_CHANGE = 0.2;

    /**
     * Der z-Wert, ab dem der Unterschied als belegt gilt.
     *
     * Vier statt der üblichen 1,96 — der Aufschlag für den Suchlauf über alle
     * Trennstellen (siehe Klassenkommentar).
     */
    public const MINIMUM_Z = 4.0;

    /**
     * Höhe, die eine der beiden Seiten erreichen muss, damit die Änderung
     * überhaupt jemanden angeht.
     *
     * Von 2 ms auf 6 ms ist eine Verdreifachung und trotzdem keine Nachricht:
     * niemand merkt sie, und die Liste wäre voll davon. Fünfzig Millisekunden
     * sind die Größenordnung, ab der ein Unterschied im Gebrauch spürbar wird.
     */
    public const NOTICEABLE_US = 50_000;

    /**
     * Sucht den Bruchpunkt in einem Verlauf.
     *
     * @param  list<TrendWindow>  $windows  aufsteigend nach Zeit
     * @return Breakpoint|null `null`, wenn nichts zu belegen ist — der
     *                         Regelfall für die allermeisten Transaktionen
     */
    public static function find(array $windows): ?Breakpoint
    {
        $usable = array_values(array_filter(
            $windows,
            static fn (TrendWindow $window): bool => $window->count >= self::MINIMUM_WINDOW_SAMPLES
                && $window->p95Us !== null,
        ));

        $total = count($usable);

        if ($total < 2 * self::MINIMUM_SIDE_WINDOWS) {
            return null;
        }

        /** @var list<int> $values */
        $values = array_map(static fn (TrendWindow $window): int => (int) $window->p95Us, $usable);

        $bestSplit = null;
        $bestZ = 0.0;

        for ($split = self::MINIMUM_SIDE_WINDOWS; $split <= $total - self::MINIMUM_SIDE_WINDOWS; $split++) {
            $z = self::rankSumZ(
                array_slice($values, 0, $split),
                array_slice($values, $split),
            );

            // Streng größer: bei gleichem z-Wert bleibt die **frühere** Stelle
            // stehen. Eine Verschlechterung wird sonst später datiert, als sie
            // stattfand — und die Zuordnung zu einer Auslieferung hinge davon
            // ab, in welcher Richtung die Schleife läuft.
            if ($z > $bestZ) {
                $bestZ = $z;
                $bestSplit = $split;
            }
        }

        if ($bestSplit === null || $bestZ < self::MINIMUM_Z) {
            return null;
        }

        $before = self::side(array_slice($usable, 0, $bestSplit));
        $after = self::side(array_slice($usable, $bestSplit));

        if ($before['count'] < self::MINIMUM_SIDE_SAMPLES || $after['count'] < self::MINIMUM_SIDE_SAMPLES) {
            return null;
        }

        // Kann bei einer nicht leeren Seite nicht vorkommen — aber eine Division
        // durch Null ist ein zu hoher Preis für diese Gewissheit.
        if ($before['p95Us'] === null || $after['p95Us'] === null || $before['p95Us'] <= 0) {
            return null;
        }

        if (max($before['p95Us'], $after['p95Us']) < self::NOTICEABLE_US) {
            return null;
        }

        $change = $after['p95Us'] / $before['p95Us'] - 1.0;

        if (abs($change) < self::MINIMUM_CHANGE) {
            return null;
        }

        return new Breakpoint(
            direction: $change > 0 ? TrendDirection::Worse : TrendDirection::Better,
            at: $usable[$bestSplit]->at,
            beforeP95Us: $before['p95Us'],
            afterP95Us: $after['p95Us'],
            beforeCount: $before['count'],
            afterCount: $after['count'],
            changeRatio: $change,
            zScore: $bestZ,
        );
    }

    /**
     * Die Kennzahlen einer Seite: alle Messungen, eine Verteilung, ein p95.
     *
     * @param  list<TrendWindow>  $windows
     * @return array{count: int, p95Us: int|null}
     */
    private static function side(array $windows): array
    {
        $histogram = DurationHistogram::empty();
        $count = 0;

        foreach ($windows as $window) {
            $histogram->merge($window->histogram);
            $count += $window->count;
        }

        return ['count' => $count, 'p95Us' => $histogram->percentile(0.95)];
    }

    /**
     * Der z-Wert des Rangsummentests für zwei Stichproben.
     *
     * Gerechnet über die U-Statistik: aus der Rangsumme der ersten Gruppe wird
     * abgezogen, was sie schon durch ihre bloße Größe erreicht — übrig bleibt,
     * wie oft ein Wert der ersten Gruppe über einem der zweiten liegt. Unter der
     * Annahme, dass beide aus derselben Verteilung stammen, ist diese Zahl
     * bekannt verteilt, und der Abstand zu ihrem Erwartungswert in
     * Standardabweichungen ist der Rückgabewert.
     *
     * Zurückgegeben wird der **Betrag**: ob es besser oder schlechter wurde,
     * entscheidet der Vergleich der Höhen, nicht das Vorzeichen der Rangsumme.
     *
     * @param  list<int>  $before
     * @param  list<int>  $after
     */
    public static function rankSumZ(array $before, array $after): float
    {
        $n1 = count($before);
        $n2 = count($after);
        $total = $n1 + $n2;

        if ($n1 === 0 || $n2 === 0) {
            return 0.0;
        }

        [$ranks, $tieTerm] = self::ranks([...$before, ...$after]);

        $rankSum = array_sum(array_slice($ranks, 0, $n1));

        $u = $rankSum - $n1 * ($n1 + 1) / 2;
        $expected = $n1 * $n2 / 2;

        // Die Streuung mit Korrektur für Bindungen. Ohne sie stünde dort
        // schlicht `n1·n2·(N+1)/12` — richtig für lauter verschiedene Werte und
        // deutlich zu groß, sobald sich Werte wiederholen. Bei Perzentilen aus
        // einer Verteilung mit festen Klassen wiederholen sie sich fast immer.
        $variance = ($n1 * $n2 / 12) * (($total + 1) - $tieTerm / ($total * ($total - 1)));

        if ($variance <= 0.0) {
            // Alle Werte gleich: es gibt nichts zu unterscheiden.
            return 0.0;
        }

        // Stetigkeitskorrektur: die U-Statistik ist ganzzahlig, die
        // Normalverteilung nicht. Ein halber Schritt weniger Abstand ist die
        // übliche und die vorsichtige Richtung.
        return max(0.0, abs($u - $expected) - 0.5) / sqrt($variance);
    }

    /**
     * Die Ränge einer Werteliste, Bindungen mit ihrem Mittelrang — dazu der
     * Term, mit dem die Streuung sie herausrechnet.
     *
     * @param  list<int>  $values
     * @return array{list<float>, float}
     */
    private static function ranks(array $values): array
    {
        $order = array_keys($values);

        usort($order, static fn (int $a, int $b): int => $values[$a] <=> $values[$b] ?: $a <=> $b);

        $ranks = array_fill(0, count($values), 0.0);
        $tieTerm = 0.0;
        $position = 0;
        $size = count($order);

        while ($position < $size) {
            $end = $position;

            while ($end + 1 < $size && $values[$order[$end + 1]] === $values[$order[$position]]) {
                $end++;
            }

            $tied = $end - $position + 1;

            // Ränge sind einsbasiert; der Mittelrang einer Bindung ist die Mitte
            // der Plätze, die sie gemeinsam belegt.
            $rank = ($position + $end + 2) / 2;

            for ($index = $position; $index <= $end; $index++) {
                $ranks[$order[$index]] = $rank;
            }

            if ($tied > 1) {
                $tieTerm += $tied ** 3 - $tied;
            }

            $position = $end + 1;
        }

        return [$ranks, $tieTerm];
    }
}
