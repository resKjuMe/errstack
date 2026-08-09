<?php

namespace App\Support\Discover;

use App\Support\Search\FieldResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Was eine Datenquelle über ihre Felder weiß — die Grenze, an der der Motor
 * endet und das Schema beginnt.
 *
 * Der Auflöser der Suchsprache ({@see FieldResolver}) ist darin enthalten und
 * nicht daneben: dieselbe Feldliste muss filtern **und** gruppieren können, sonst
 * bedeutet `browser` in der Bedingung etwas anderes als in der Gruppierung. Genau
 * diese Trennung war der Grund, die Suchsprache in S4 hinter einen Auflöser zu
 * legen.
 *
 * Der Motor kennt hinter dieser Grenze nichts mehr: keine Tabelle, keine Spalte,
 * keinen Sonderfall einer Kennzahl. Eine neue Quelle ist deshalb eine Klasse und
 * eine Zeile in {@see Dataset} — und keine Änderung an {@see DiscoverEngine}.
 */
interface DatasetFields extends FieldResolver
{
    public function dataset(): Dataset;

    /**
     * Die Abfrage, auf der alles aufsetzt — ohne Einschränkung.
     *
     * @return Builder<*>
     */
    public function query(): Builder;

    /**
     * Die Verbindung, auf der diese Quelle liegt — sie entscheidet über die
     * Treiber-Sonderfälle in {@see Sql}.
     */
    public function connection(): Connection;

    /**
     * Die Spalte, nach der der Zeitraum eingeschränkt und die Zeitreihe gerastert
     * wird — qualifiziert, weil eine Suchbedingung Tabellen dazunehmen darf.
     */
    public function timeColumn(): string;

    /**
     * Das Feld unter diesem Namen, oder `null`.
     *
     * Auch für Felder, die es nicht als Aufzählung gibt: ein Merkmal wie
     * `tags[checkout_step]` entsteht hier auf Zuruf.
     */
    public function definition(string $name): ?FieldDefinition;

    /**
     * Wonach sich gruppieren lässt — der Katalog für die Oberfläche (D2).
     *
     * @return list<string>
     */
    public function groupable(): array;

    /**
     * Worüber sich rechnen lässt.
     *
     * @return list<string>
     */
    public function aggregatable(): array;

    /**
     * Die Kennzahl, übersetzt für diese Quelle.
     *
     * @throws DiscoverException wenn die Quelle sie nicht rechnen kann
     */
    public function measure(Aggregation $aggregation): Measure;
}
