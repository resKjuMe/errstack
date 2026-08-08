<?php

namespace App\Support\Profiling;

/**
 * Der Vergleich zweier Auslieferungen: welche Funktion in der neuen Version
 * mehr Rechenzeit verbraucht als in der alten.
 *
 * Verglichen werden **Anteile** und nicht Zeiten, und das ist die eigentliche
 * Entscheidung dieser Klasse. Die absolute Selbstzeit einer Funktion hängt
 * daran, wie viele Profile in den Vergleich eingegangen sind und wie lange die
 * gemessenen Aufrufe waren: dreißig Profile aus Version 1.4 gegen hundert aus
 * 1.3 gestellt, ist jede Funktion in 1.4 „schneller geworden". Der Anteil an
 * der gemessenen Rechenzeit ist von beidem unabhängig und beantwortet die
 * Frage, die gestellt wird: hat sich die Verteilung verschoben?
 *
 * Was der Vergleich damit **nicht** beantwortet, ist „ist es insgesamt langsamer
 * geworden" — das steht in der Performance-Übersicht (PF2) und ist dort
 * richtig aufgehoben. Hier steht, **wo** es langsamer geworden ist.
 */
final class ProfileComparison
{
    /**
     * Wie viele Zeilen der Vergleich zeigt.
     *
     * Die größten Verschiebungen in beide Richtungen; was sich um Bruchteile
     * eines Prozents bewegt hat, ist Rauschen aus der Abtastung und keine
     * Verschlechterung.
     */
    public const LIMIT = 50;

    /**
     * Stellt zwei zusammengefasste Bäume gegenüber.
     *
     * Sortiert nach der Größe der Verschiebung, nicht nach ihrer Richtung: eine
     * Funktion, die deutlich billiger geworden ist, ist genauso erklärungsbedürftig
     * wie eine, die teurer wurde — meistens ist sie an eine andere Stelle
     * gewandert, und die steht dann ein paar Zeilen weiter oben.
     *
     * @return list<array<string, mixed>>
     */
    public static function between(CallTree $baseline, CallTree $candidate): array
    {
        $before = self::shares($baseline);
        $after = self::shares($candidate);

        $rows = [];

        // Die Vereinigung beider Schlüsselmengen: eine Funktion, die es nur in
        // einer der beiden Versionen gibt, ist der interessanteste Fall
        // überhaupt — sie ist neu hinzugekommen oder ganz verschwunden.
        foreach (array_keys($before + $after) as $key) {
            // Mindestens eine der beiden Seiten hat den Schlüssel — er kommt aus
            // ihrer Vereinigung. Die andere fehlt genau dann, wenn die Funktion
            // in der Version nicht vorkam; dort steht überall die Null.
            $left = $before[$key] ?? null;
            $right = $after[$key] ?? null;
            $known = $left ?? $right;

            if ($known === null) {
                continue;
            }

            $frame = $known['frame'];
            $baselineShare = $left === null ? 0.0 : $left['share'];
            $candidateShare = $right === null ? 0.0 : $right['share'];

            $rows[] = [
                'function' => $frame->function,
                'module' => $frame->module,
                'file' => $frame->file,
                'inApp' => $frame->inApp,
                'baselineShare' => round($baselineShare, 5),
                'candidateShare' => round($candidateShare, 5),
                'deltaShare' => round($candidateShare - $baselineShare, 5),
                // Die Zeiten stehen daneben, damit die Anteile einzuordnen sind:
                // eine Verschiebung um zehn Prozentpunkte bei insgesamt drei
                // Millisekunden Rechenzeit ist keine.
                'baselineUs' => intdiv($left === null ? 0 : $left['selfNs'], 1000),
                'candidateUs' => intdiv($right === null ? 0 : $right['selfNs'], 1000),
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => abs((float) $b['deltaShare']) <=> abs((float) $a['deltaShare']),
        );

        return array_slice($rows, 0, self::LIMIT);
    }

    /**
     * Die Selbstzeit je Funktion als Anteil an der Rechenzeit des Baums.
     *
     * @return array<string, array{frame: ProfileFrame, share: float, selfNs: int}>
     */
    private static function shares(CallTree $tree): array
    {
        $total = $tree->totalNs;
        $shares = [];

        foreach ($tree->functions() as $function) {
            $shares[$function->frame->key()] = [
                'frame' => $function->frame,
                // Ein Baum ohne gemessene Zeit hat keine Anteile. Ohne diese
                // Abfrage wäre der Vergleich eine Division durch Null — und die
                // Seite eine Fehlermeldung statt eines leeren Vergleichs.
                'share' => $total > 0 ? $function->selfNs / $total : 0.0,
                'selfNs' => $function->selfNs,
            ];
        }

        return $shares;
    }
}
