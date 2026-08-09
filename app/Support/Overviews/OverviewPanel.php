<?php

namespace App\Support\Overviews;

/**
 * Der Bauplan einer Kachel der Übersichtsseiten — eine Form für alle drei
 * Seiten.
 *
 * **Drei Darstellungen und nicht dreizehn.** Eine Übersicht ist keine freie
 * Auswertung: sie zeigt einen Verlauf, ein paar Kennzahlen oder eine kurze
 * Liste. Alles, was auf den drei Seiten vorkommt, passt in diese drei Formen —
 * und dadurch braucht die Oberfläche genau eine Komponente je Form und keine
 * je Kachel.
 *
 * **Jede Zahl trägt ihren Weg bei sich.** `href` steht am Panel, an jeder
 * Kennzahl und an jeder Zeile; die Zusage der Aufgabe („jede Kennzahl verlinkt
 * in die passende Detailansicht") ist damit eine Eigenschaft der Nutzlast und
 * nicht eine Frage, an die in der Oberfläche jemand denken muss.
 *
 * **Was fehlt, sagt warum.** Eine Kachel ohne Daten ist entweder leer (nichts
 * passiert), ausstehend (Projekt noch nicht angeschlossen) oder abgelehnt (der
 * Motor hat eine Grenze gezogen). Drei verschiedene Auskünfte, die alle drei
 * nicht wie ein Diagramm mit einer Nulllinie aussehen dürfen.
 */
final class OverviewPanel
{
    /**
     * Ein Verlauf: eine Linie auf dem Raster des Motors.
     *
     * @param  array{at: list<string>, values: list<float|null>, interval: string}  $series
     * @param  array{key: string, label: string, format: string, unit: string}  $column
     * @return array<string, mixed>
     */
    public static function series(string $key, array $column, array $series, ?string $href = null): array
    {
        $values = array_filter($series['values'], static fn (?float $value): bool => $value !== null);

        return self::of($key, 'series', [
            'series' => $series + ['column' => $column],
            'total' => $values === [] ? null : array_sum($values),
        ], $href, empty: $values === []);
    }

    /**
     * Ein paar Kennzahlen nebeneinander.
     *
     * @param  list<array<string, mixed>>  $stats
     * @return array<string, mixed>
     */
    public static function stats(string $key, array $stats, ?string $href = null): array
    {
        return self::of($key, 'stats', ['stats' => $stats], $href, empty: $stats === []);
    }

    /**
     * Eine kurze Liste — Rangliste, jüngste Einträge, offene Punkte.
     *
     * `$stats` sind die Zahlen **über** der Liste, wo eine Liste allein die
     * Frage nicht beantwortet: „drei Teams" ist die Auskunft, die Namen
     * darunter sind die Antwort auf die Anschlussfrage. Ohne sie bleibt die
     * Kachel eine reine Liste.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $stats
     * @return array<string, mixed>
     */
    public static function rows(string $key, array $rows, ?string $href = null, array $stats = []): array
    {
        return self::of(
            $key,
            'rows',
            ['rows' => $rows, 'stats' => $stats],
            $href,
            empty: $rows === [] && $stats === [],
        );
    }

    /**
     * Eine Kachel, die nicht gerechnet hat — mit dem Grund des Motors.
     *
     * @param  array<string, mixed>  $error
     * @return array<string, mixed>
     */
    public static function failed(string $key, array $error): array
    {
        return self::of($key, 'error', ['error' => $error], null, empty: true);
    }

    /**
     * Der Einrichtungs-Hinweis an die Kachel heften.
     *
     * Er tritt **an die Stelle** des Inhalts, solange von keinem der Projekte
     * etwas vorliegt — sonst steht er daneben. Ein leeres Diagramm mit dem
     * Hinweis darunter wäre die Nulllinie, die hier gerade vermieden werden
     * soll.
     *
     * @param  array<string, mixed>  $panel
     * @param  array{projects: list<array{slug: string, name: string, href: string}>, all: bool}|null  $hint
     * @return array<string, mixed>
     */
    public static function withSetup(array $panel, ?array $hint): array
    {
        return [...$panel, 'setup' => $hint];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function of(string $key, string $kind, array $payload, ?string $href, bool $empty): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'href' => $href,
            'empty' => $empty,
            'setup' => null,
            'error' => null,
            'series' => null,
            'stats' => [],
            'rows' => [],
            'total' => null,
            ...$payload,
        ];
    }
}
