<?php

namespace App\Support\Discover;

use App\Support\Tags\EventTags;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Die drei Stellen, an denen sich MySQL und SQLite nicht einig sind.
 *
 * Der Motor ist ansonsten in der Abfrage-Schreibweise von Laravel gehalten, die
 * den Unterschied verbirgt — auch bei den JSON-Feldern, wo `tags->browser` je
 * Treiber verschieden übersetzt wird. Drei Dinge kann sie nicht: Zeit in
 * Abschnitte rastern, zwei Textfelder verbinden und einen Text vor einem Zeichen
 * abschneiden. Sie stehen hier zusammen, damit die Treiber-Sonderfälle **eine**
 * Stelle haben und nicht über die Feldlisten verstreut sind.
 *
 * Warum die Prüfung überhaupt nötig ist: die Anwendung läuft auf MySQL, die
 * Testsuite auf SQLite. Ein Ausdruck, der nur in einem von beiden richtig ist,
 * fällt deshalb entweder im Betrieb oder nie auf.
 */
final class Sql
{
    /**
     * Die Nummer des Zeitabschnitts, in den eine Zeile fällt — 0 für den ersten.
     *
     * Gerechnet wird der **Abstand zum Anfang des Zeitraums** und nicht der
     * Zeitstempel selbst. Das hat einen handfesten Grund: MySQL rechnet
     * `unix_timestamp()` auf einer `timestamp`-Spalte in die Zeitzone der
     * Sitzung um, und ein Raster über die Epoche läge dann je nach Servereinstellung
     * verschoben. Ein Abstand zwischen zwei Zeitangaben ist von der Zeitzone
     * unabhängig, solange beide gleich behandelt werden — und genau das leisten
     * `timestampdiff` und `strftime` mit einem gebundenen Wert.
     *
     * @return array{0: string, 1: list<string>} Ausdruck und seine Werte
     */
    public static function bucketIndex(Connection $connection, string $column, Interval $interval, TimeRange $range): array
    {
        $wrapped = $connection->getQueryGrammar()->wrap($column);
        $origin = $range->from->format('Y-m-d H:i:s');

        // Abgerundet wird ohne `floor()`: die Funktion ist in SQLite nur vorhanden,
        // wenn die Übersetzung die Mathematik-Erweiterung mitgenommen hat — im
        // Standard-Paket vieler Systeme fehlt sie. Eine Division zweier ganzer Zahlen
        // schneidet dort ohnehin ab, und negativ kann der Abstand nicht werden: der
        // Zeitraum ist die Bedingung der Abfrage.
        return match (self::driver($connection)) {
            'sqlite' => [
                sprintf(
                    'cast((cast(strftime(\'%%s\', %s) as integer) - cast(strftime(\'%%s\', ?) as integer)) / %d as integer)',
                    $wrapped,
                    $interval->seconds,
                ),
                [$origin],
            ],
            'pgsql' => [
                sprintf(
                    'cast(floor(extract(epoch from (%s - cast(? as timestamp))) / %d) as integer)',
                    $wrapped,
                    $interval->seconds,
                ),
                [$origin],
            ],
            default => [
                sprintf('cast(floor(timestampdiff(second, ?, %s) / %d) as integer)', $wrapped, $interval->seconds),
                [$origin],
            ],
        };
    }

    /**
     * Zwei Textfelder mit einem Leerzeichen dazwischen — und der erste allein,
     * wenn der zweite fehlt.
     *
     * Das ist die Form der zusammengesetzten Merkmale („Chrome 124.0"), die die
     * Aufnahme ohnehin baut ({@see EventTags}). Ohne den
     * Rückfall auf den ersten Wert stünde in der Auswertung nichts, wo ein SDK
     * keine Fassung mitgeschickt hat — und `||` in SQLite macht aus einem `null`
     * an einer Stelle den ganzen Ausdruck zu `null`.
     */
    public static function join(Connection $connection, string $first, string $second): string
    {
        return match (self::driver($connection)) {
            'sqlite', 'pgsql' => sprintf('coalesce(%1$s || \' \' || %2$s, %1$s)', $first, $second),
            default => sprintf('if(%2$s is null or %2$s = \'\', %1$s, concat_ws(\' \', %1$s, %2$s))', $first, $second),
        };
    }

    /**
     * Der Teil eines Textes vor dem ersten Vorkommen eines Zeichens.
     *
     * Gebraucht für die Adresse: `?id=4711` macht jede Adresse einzigartig, und
     * eine Auswertung „nach Seite" mit einer Zeile je Aufruf beantwortet keine
     * Frage. Die Aufnahme schneidet aus demselben Grund an derselben Stelle ab.
     */
    public static function before(Connection $connection, string $expression, string $character): string
    {
        // Nur ein einzelnes Zeichen, und es steht in dieser Datei — die
        // Zeichenkette landet unmaskiert im Ausdruck.
        if (mb_strlen($character) !== 1 || $character === '\'') {
            throw DiscoverException::invalid('Nur ein einzelnes Zeichen kann abgeschnitten werden.');
        }

        return match (self::driver($connection)) {
            'sqlite' => sprintf(
                'case when instr(%1$s, \'%2$s\') > 0 then substr(%1$s, 1, instr(%1$s, \'%2$s\') - 1) else %1$s end',
                $expression,
                $character,
            ),
            'pgsql' => sprintf('split_part(%s, \'%s\', 1)', $expression, $character),
            default => sprintf('substring_index(%s, \'%s\', 1)', $expression, $character),
        };
    }

    /**
     * Die Zeitgrenze, die MySQL selbst einhält.
     *
     * Ein Hinweis an den Optimierer und keine Einstellung der Verbindung: er gilt
     * für **diese** Abfrage und kann deshalb nicht in einer anderen hängen
     * bleiben. SQLite kennt ihn nicht und liest ihn als Kommentar — dort bleibt
     * die Grenze der Zeitraum und die Zeilenzahl, die vorher geprüft werden.
     */
    public static function timeout(Connection $connection, int $milliseconds): string
    {
        if ($milliseconds < 1 || self::driver($connection) !== 'mysql') {
            return '';
        }

        return sprintf('/*+ MAX_EXECUTION_TIME(%d) */ ', $milliseconds);
    }

    /**
     * Hat die Datenbank die Abfrage wegen der Zeitgrenze abgebrochen?
     *
     * MySQL meldet das als Fehler 3024 mit dem Zustand `HY000`; die Meldung
     * daneben ist die einzige Stelle, an der „abgebrochen" von „kaputt"
     * unterschieden werden kann.
     */
    public static function isTimeout(Throwable $error): bool
    {
        return str_contains($error->getMessage(), '3024')
            || str_contains(mb_strtolower($error->getMessage()), 'maximum statement execution time exceeded');
    }

    private static function driver(Connection $connection): string
    {
        $driver = $connection->getDriverName();

        return $driver === 'mariadb' ? 'mysql' : $driver;
    }
}
