<?php

namespace App\Support\Profiling;

/**
 * Der Aufrufbaum in der Form, in der ihn die Oberfläche zeichnet.
 *
 * Zwei Dinge passieren hier, und beide gehören zusammen: die Zeiten werden auf
 * Mikrosekunden gebracht, und die Äste, die im Bild ohnehin unsichtbar wären,
 * fallen weg.
 *
 * Zur Einheit: gerechnet wird in Nanosekunden, weil die SDKs so messen. Als
 * Zahl an den Browser gegeben ist das ein Problem — JavaScript stellt ganze
 * Zahlen nur bis 2^53 genau dar, und eine Auswertung, deren Anteile aus
 * lautlos gerundeten Werten entstehen, ist keine Auswertung. Mikrosekunden
 * halten auch eine Stunde Rechenzeit mit Abstand im sicheren Bereich.
 *
 * Zum Kürzen: ein Baum darf {@see CallTree::MAX_NODES} Knoten haben. Auf einem
 * Bildschirm von 1500 Pixeln ist ein Ast mit einem Tausendstel der Gesamtzeit
 * anderthalb Pixel breit — er ist nicht klein, er ist nicht da. Ihn trotzdem zu
 * schicken kostet ein Megabyte je Seitenaufruf und trägt nichts bei. Was
 * wegfällt, wird gezählt und angezeigt; die Zeit selbst bleibt in der
 * Gesamtzeit des Elternknotens enthalten, damit die Summen weiter aufgehen.
 */
final class FlamegraphData
{
    /**
     * Kleinster Anteil an der Gesamtzeit, ab dem ein Ast noch mitgeschickt wird.
     */
    public const MIN_SHARE = 0.001;

    /**
     * Wie viele Zeilen die Funktionsliste höchstens trägt.
     *
     * Sortiert wird nach Selbstzeit, und was in einer Anwendung die Zeit kostet,
     * steht in den ersten zwanzig Zeilen. Zweihundert sind großzügig genug, dass
     * auch ein gleichmäßig verteiltes Profil noch etwas hergibt — und die Liste
     * bleibt eine Liste und wird kein zweiter Datenbestand im Browser.
     */
    public const FUNCTION_LIMIT = 200;

    /**
     * @return array<string, mixed>
     */
    public static function present(CallTree $tree): array
    {
        $threshold = (int) max(1, $tree->totalNs * self::MIN_SHARE);
        $pruned = 0;

        $roots = [];

        // Eine Schleife und kein `array_map`: der Zähler der weggelassenen Äste
        // wird als Referenz durchgereicht, und eine Pfeilfunktion übernimmt
        // ihre Umgebung nur als Kopie — der Zähler bliebe bei null stehen.
        foreach ($tree->roots as $node) {
            $roots[] = self::node($node, $threshold, $pruned);
        }

        // Die Wurzeln nach Gesamtzeit: der Ast, in dem die Zeit steckt, gehört
        // nach oben. Bei mehreren Wurzeln (das kommt vor, wenn das SDK den
        // Stapel nicht bis zum Einstiegspunkt meldet) wäre die Reihenfolge sonst
        // die des Auftretens, und das Bild sähe bei jedem Profil anders aus.
        usort($roots, static fn (array $a, array $b): int => (int) $b['total'] <=> (int) $a['total']);

        $functions = array_slice($tree->functions(), 0, self::FUNCTION_LIMIT);

        return [
            'roots' => $roots,
            'totalUs' => intdiv($tree->totalNs, 1000),
            'samples' => $tree->sampleCount,
            // Beides zusammen ist die Antwort auf „ist das vollständig?": beim
            // Aufnehmen abgeschnittene Wege und hier weggelassene Äste.
            'droppedNodes' => $tree->droppedNodes,
            'prunedNodes' => $pruned,
            'functionCount' => count($tree->frames),
            'functions' => array_map(
                static fn (FunctionTotal $total): array => $total->toArray(),
                $functions,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function node(CallNode $node, int $threshold, int &$pruned): array
    {
        $children = [];

        foreach ($node->children as $child) {
            if ($child->totalNs < $threshold) {
                $pruned++;

                continue;
            }

            $children[] = self::node($child, $threshold, $pruned);
        }

        usort($children, static fn (array $a, array $b): int => (int) $b['total'] <=> (int) $a['total']);

        return [
            'frame' => $node->frame,
            'self' => intdiv($node->selfNs, 1000),
            'total' => intdiv($node->totalNs, 1000),
            'samples' => $node->selfSamples,
            'children' => $children,
        ];
    }

    /**
     * Die Rahmentabelle für die Anzeige.
     *
     * Sie geht getrennt vom Baum hinaus, genau wie sie getrennt abgelegt ist:
     * derselbe Rahmen kommt im Baum hundertfach vor, und ausgeschrieben wäre die
     * Nutzlast der Seite ein Vielfaches.
     *
     * @param  list<ProfileFrame>  $frames
     * @return list<array<string, mixed>>
     */
    public static function frames(array $frames): array
    {
        return array_map(
            static fn (ProfileFrame $frame): array => [
                'function' => $frame->function,
                'module' => $frame->module,
                'file' => $frame->file,
                'line' => $frame->line,
                'inApp' => $frame->inApp,
            ],
            $frames,
        );
    }
}
