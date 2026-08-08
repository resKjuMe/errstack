<?php

namespace App\Enums;

use App\Support\Performance\Detection\Thresholds;

/**
 * Die erkannten Leistungsmuster — je Fall ein eigener Erkenner, ein eigener
 * Fingerabdruck und ein eigener Satz Schwellen.
 *
 * **Warum ein Enum und keine Tabelle:** ein Muster ist kein Datensatz, sondern
 * Code. Zu jedem Fall gehört ein Erkenner, der weiß, wonach er sucht; ein
 * Eintrag in einer Tabelle ohne diesen Code wäre eine Zeile, die nichts findet.
 * Einstellbar ist deshalb nicht, **welche** Muster es gibt, sondern ab wann sie
 * anschlagen — und das steht je Projekt in `performance_settings`.
 *
 * **Die Schwellen stehen in Einheiten, die jemand eingibt** — Millisekunden,
 * Kilobyte, Anzahl —, nicht in den Einheiten, in denen gerechnet wird
 * (Mikrosekunden, Bytes). Der Grund ist das Formular: wer „langsamer als 1000"
 * einträgt, meint Millisekunden, und eine Oberfläche, die intern durch 1000
 * teilt, verliert bei jedem zweiten Speichern eine Nachkommastelle. Die
 * Umrechnung passiert an genau einer Stelle, im Erkenner
 * ({@see Thresholds}).
 *
 * Die Vorgabewerte sind bewusst **zurückhaltend**: ein Erkenner, der bei jedem
 * zweiten Seitenaufruf anschlägt, erzeugt eine Liste, die niemand ansieht. Sie
 * dürfen je Projekt in beide Richtungen verschoben werden.
 */
enum PerformanceProblem: string
{
    /**
     * N+1-Abfragen: eine auslösende Abfrage, danach dieselbe Abfrageform
     * vielfach wiederholt — der Klassiker aus einer Schleife über ein
     * Ergebnis.
     *
     * Was ihn von den bloß aufeinanderfolgenden Abfragen unterscheidet, ist die
     * **auslösende** Abfrage davor: sie ist der Beleg dafür, dass die
     * Wiederholungen aus deren Ergebnis stammen, und sie ist der Ansatzpunkt
     * für die Behebung (ein Join oder ein Vorabladen).
     */
    case NPlusOneQueries = 'n_plus_one_queries';

    /**
     * Aufeinanderfolgende gleichartige Abfragen: dieselbe Abfrageform mehrfach
     * hintereinander, ohne Überlappung — nacheinander abgearbeitet, wo eine
     * gebündelte Abfrage gereicht hätte.
     *
     * Kein N+1, weil die auslösende Abfrage fehlt; trotzdem verlorene Zeit,
     * denn die Wartezeiten addieren sich, statt sich zu überlagern.
     */
    case ConsecutiveQueries = 'consecutive_queries';

    /**
     * Doppelte Abfragen: **exakt** dieselbe Abfrage mehr als einmal in
     * demselben Ablauf, samt Parametern.
     *
     * Anders als bei den vorigen beiden ist die Antwort hier nachweislich
     * dieselbe — die Wiederholung ist reine Verschwendung und lässt sich ohne
     * fachliche Entscheidung durch einen Zwischenspeicher ersetzen.
     */
    case DuplicateQueries = 'duplicate_queries';

    /**
     * Langsamer HTTP-Aufruf an einen fremden Dienst.
     */
    case SlowHttpCall = 'slow_http_call';

    /**
     * Übergroße oder unkomprimiert ausgelieferte Datei.
     *
     * „Unkomprimiert" heißt hier nachweisbar: das SDK meldet übertragene und
     * entpackte Größe getrennt, und wer beide gleich meldet, hat nicht
     * komprimiert.
     */
    case OversizedAsset = 'oversized_asset';

    /**
     * Render-blockierende Ressource: ein Skript oder Stylesheet, auf das der
     * Browser wartet, bevor er überhaupt etwas anzeigt.
     */
    case RenderBlockingAsset = 'render_blocking_asset';

    /**
     * Hauptthread-Blockade: ein Stück Arbeit, das den Browser so lange
     * beschäftigt, dass er in der Zeit auf keine Eingabe reagiert.
     */
    case MainThreadBlock = 'main_thread_block';

    /**
     * Cache-Fehlgriffe: Nachschläge im Zwischenspeicher, die ins Leere gehen.
     *
     * Gezählt wird der Fehlgriff, nicht die Neuberechnung dahinter — deren
     * Kosten stehen in keinem eigenen Schritt und wären geraten. Die gemeldete
     * verlorene Zeit ist damit ausdrücklich eine **Untergrenze**.
     */
    case CacheMisses = 'cache_misses';

    public function label(): string
    {
        return __('enums.performance_problem.'.$this->value);
    }

    public function description(): string
    {
        return __('enums.performance_problem_description.'.$this->value);
    }

    /**
     * Der Grad, mit dem ein neuer Eintrag dieses Musters entsteht.
     *
     * Alle Leistungsprobleme sind Warnungen, keine Fehler: die Anwendung tut,
     * was sie soll — sie tut es nur zu langsam. Ein `error` würde in jeder
     * Alarmregel neben echten Ausnahmen stehen und diese entwerten.
     */
    public function level(): EventLevel
    {
        return EventLevel::Warning;
    }

    /**
     * Die Vorgabeschwellen dieses Musters.
     *
     * Die Schlüssel benennen zugleich die Einheit: `_ms` Millisekunden, `_kb`
     * Kilobyte, `_count` eine Anzahl.
     *
     * @return array<string, int>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::NPlusOneQueries => ['min_count' => 5, 'min_total_ms' => 50],
            self::ConsecutiveQueries => ['min_count' => 4, 'min_total_ms' => 100],
            self::DuplicateQueries => ['min_count' => 2, 'min_total_ms' => 20],
            self::SlowHttpCall => ['min_duration_ms' => 1000],
            self::OversizedAsset => ['min_size_kb' => 500, 'min_duration_ms' => 100],
            self::RenderBlockingAsset => ['min_duration_ms' => 300],
            self::MainThreadBlock => ['min_duration_ms' => 200],
            self::CacheMisses => ['min_count' => 5, 'min_total_ms' => 10],
        };
    }

    /**
     * Die erlaubte Spanne je Schwelle — die Prüfung des Formulars und zugleich
     * die Grenze, hinter der eine Einstellung nur noch schadet.
     *
     * Die Untergrenzen sind nicht kosmetisch: `min_count` unter 2 hieße, dass
     * eine einzelne Abfrage als „wiederholt" gilt, und eine Schwelle von 0 ms
     * macht aus jedem Ablauf ein Leistungsproblem.
     *
     * @return array<string, array{min: int, max: int}>
     */
    public function limits(): array
    {
        $limits = [
            'min_count' => ['min' => 2, 'max' => 10_000],
            'min_total_ms' => ['min' => 1, 'max' => 600_000],
            'min_duration_ms' => ['min' => 1, 'max' => 600_000],
            'min_size_kb' => ['min' => 1, 'max' => 1_048_576],
        ];

        // Ausdrücklich in der Reihenfolge der Vorgaben und nicht in der dieser
        // Liste: sie ist zugleich die Reihenfolge der Felder im Formular, und
        // dort gehört die naheliegendere Angabe nach vorn. Ein
        // `array_intersect_key` mit den Grenzen zuerst gäbe die Reihenfolge
        // dieser Liste zurück — bei den Dateien also erst die Dauer und dann
        // die Größe, obwohl es um die Größe geht.
        $selected = [];

        foreach (array_keys($this->defaults()) as $key) {
            if (isset($limits[$key])) {
                $selected[$key] = $limits[$key];
            }
        }

        return $selected;
    }

    public function thresholdLabel(string $key): string
    {
        return __('enums.performance_threshold.'.$key);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
