<?php

namespace Tests\Unit;

use App\Enums\TransactionSort;
use App\Enums\TrendDirection;
use App\Support\Performance\DurationHistogram;
use App\Support\Performance\TransactionOverviewRow;
use App\Support\Performance\TransactionSearch;
use App\Support\Performance\TransactionTrend;
use PHPUnit\Framework\TestCase;

/**
 * Die Rechnung hinter der Performance-Übersicht.
 *
 * Ohne Laravel und ohne Datenbank: was hier geprüft wird, ist Arithmetik und
 * Reihenfolge — Durchsatz, Anteile, Trend, Sortierung. Der Weg dorthin (Filter,
 * Abfragen, Anzeige) hat seinen eigenen Test; hier geht es um die Stellen, an
 * denen sich ein Fehler versteckt, ohne dass irgendetwas abstürzt.
 */
class TransactionOverviewTest extends TestCase
{
    /**
     * Eine Zeile mit lauter gleich langen Messungen — dann ist jedes Perzentil
     * die Obergrenze ihrer Klasse und damit vorhersagbar.
     */
    private function row(
        string $name = 'GET /checkout',
        int $durationUs = 500_000,
        int $count = 10,
        ?float $extrapolated = null,
        int $failures = 0,
        int $users = 0,
        int $miserableUsers = 0,
        ?TransactionTrend $trend = null,
        int $minutes = 60,
        string $op = 'http.server',
    ): TransactionOverviewRow {
        return new TransactionOverviewRow(
            name: $name,
            op: $op,
            transactionCount: $count,
            extrapolatedCount: $extrapolated ?? (float) $count,
            failureCount: $failures,
            durationSumUs: $durationUs * $count,
            minUs: $durationUs,
            maxUs: $durationUs,
            histogram: DurationHistogram::fromStored([DurationHistogram::bucketFor($durationUs) => $count]),
            users: $users,
            miserableUsers: $miserableUsers,
            trend: $trend ?? TransactionTrend::between(null, 0, null, 0),
            minutes: $minutes,
        );
    }

    public function test_the_throughput_counts_the_extrapolated_calls_per_minute(): void
    {
        // Zehn gespeicherte Messungen, die für hundert Aufrufe stehen (I9): der
        // Durchsatz ist der der hundert. Alles andere wäre eine Anwendung mit
        // Stichprobe, die zehnmal leiser aussieht, als sie ist.
        $row = $this->row(count: 10, extrapolated: 100.0, minutes: 60);

        $this->assertSame(100 / 60, $row->throughputPerMinute);
        $this->assertSame(10, $row->transactionCount);
    }

    public function test_a_period_shorter_than_a_minute_does_not_inflate_the_throughput(): void
    {
        // Der Nenner ist mindestens eine Minute. Ohne diese Untergrenze ergäbe
        // ein Zeitraum von Sekunden einen Durchsatz, den niemand gesehen hat.
        $row = $this->row(count: 10, minutes: 0);

        $this->assertSame(10.0, $row->throughputPerMinute);
    }

    public function test_the_failure_rate_uses_the_measured_numbers(): void
    {
        // Nicht die hochgerechneten: ein Anteil ändert sich nicht dadurch, dass
        // man Zähler und Nenner mit derselben Zahl multipliziert — er würde nur
        // eine Genauigkeit vortäuschen, die die Stichprobe nicht hergibt.
        $row = $this->row(count: 8, extrapolated: 800.0, failures: 2);

        $this->assertSame(0.25, $row->failureRate);
    }

    public function test_percentiles_come_from_the_distribution(): void
    {
        $row = $this->row(durationUs: 500_000, count: 10);
        $expected = DurationHistogram::lowerBound(DurationHistogram::bucketFor(500_000) + 1);

        $this->assertSame($expected, $row->p50Us);
        $this->assertSame($expected, $row->p95Us);
        $this->assertSame(500_000.0, $row->averageUs);
    }

    public function test_user_misery_is_the_share_of_users_who_waited_too_long(): void
    {
        $this->assertSame(0.25, $this->row(users: 8, miserableUsers: 2)->userMisery);

        // Ohne bekannte Nutzer gibt es keinen Anteil — und das ist etwas anderes
        // als null Prozent. Eine Hintergrundaufgabe hat keine zufriedenen
        // Nutzer, sie hat gar keine.
        $this->assertNull($this->row(users: 0, miserableUsers: 0)->userMisery);
    }

    public function test_a_slower_period_is_a_worse_trend(): void
    {
        $trend = TransactionTrend::between(200_000, 10, 100_000, 10);

        $this->assertSame(TrendDirection::Worse, $trend->direction);
        $this->assertSame(1.0, $trend->changeRatio);
    }

    public function test_a_faster_period_is_a_better_trend(): void
    {
        $trend = TransactionTrend::between(50_000, 10, 100_000, 10);

        $this->assertSame(TrendDirection::Better, $trend->direction);
        $this->assertSame(-0.5, $trend->changeRatio);
    }

    public function test_a_small_change_is_no_change(): void
    {
        // Zwei Zeiträume sind nie exakt gleich. Ohne das Band um die Null trüge
        // jede Zeile einen Pfeil, und keiner davon hieße etwas.
        $trend = TransactionTrend::between(102_000, 10, 100_000, 10);

        $this->assertSame(TrendDirection::Flat, $trend->direction);
    }

    public function test_without_a_previous_period_the_transaction_is_new(): void
    {
        $trend = TransactionTrend::between(100_000, 10, null, 0);

        $this->assertSame(TrendDirection::New, $trend->direction);
        $this->assertNull($trend->changeRatio);
    }

    public function test_too_few_measurements_produce_no_trend(): void
    {
        // Vier Messungen auf einer Seite: ein p95 daraus ist die langsamste
        // davon, und ein einzelner Ausreißer wäre eine Verzehnfachung.
        $trend = TransactionTrend::between(1_000_000, 4, 100_000, 10);

        $this->assertSame(TrendDirection::Unknown, $trend->direction);
        $this->assertNull($trend->changeRatio);

        $other = TransactionTrend::between(1_000_000, 10, 100_000, 4);

        $this->assertSame(TrendDirection::Unknown, $other->direction);
    }

    public function test_sorting_puts_missing_values_last_in_both_directions(): void
    {
        $withUsers = $this->row(name: 'GET /a', users: 10, miserableUsers: 5);
        $withoutUsers = $this->row(name: 'GET /b');

        $descending = TransactionOverviewRow::sorted(
            [$withoutUsers, $withUsers],
            TransactionSort::UserMisery,
            true,
        );

        $ascending = TransactionOverviewRow::sorted(
            [$withoutUsers, $withUsers],
            TransactionSort::UserMisery,
            false,
        );

        // In **beiden** Richtungen unten. Als kleinster Wert behandelt stünde
        // aufsteigend das ganz oben, worüber es nichts zu sagen gibt.
        $this->assertSame(['GET /a', 'GET /b'], self::names($descending));
        $this->assertSame(['GET /a', 'GET /b'], self::names($ascending));
    }

    public function test_sorting_by_a_metric_orders_by_its_value(): void
    {
        $slow = $this->row(name: 'GET /slow', durationUs: 2_000_000);
        $fast = $this->row(name: 'GET /fast', durationUs: 20_000);

        $this->assertSame(
            ['GET /slow', 'GET /fast'],
            self::names(TransactionOverviewRow::sorted([$fast, $slow], TransactionSort::P95, true)),
        );

        $this->assertSame(
            ['GET /fast', 'GET /slow'],
            self::names(TransactionOverviewRow::sorted([$slow, $fast], TransactionSort::P95, false)),
        );
    }

    public function test_equal_values_keep_a_stable_order(): void
    {
        // Ohne festen zweiten Schlüssel könnte dieselbe Anfrage zwei
        // verschiedene Seiten liefern — beim Blättern stünde eine Transaktion
        // zweimal da, während eine andere fehlt.
        $rows = [
            $this->row(name: 'GET /c'),
            $this->row(name: 'GET /a'),
            $this->row(name: 'GET /b'),
        ];

        foreach ([true, false] as $descending) {
            $this->assertSame(
                ['GET /a', 'GET /b', 'GET /c'],
                self::names(TransactionOverviewRow::sorted($rows, TransactionSort::P95, $descending)),
            );
        }
    }

    public function test_the_search_reads_free_text_and_operations(): void
    {
        $this->assertTrue(TransactionSearch::parse(null)->isEmpty());
        $this->assertTrue(TransactionSearch::parse('   ')->isEmpty());

        // Ein `op:` ohne Wert ist eine halb getippte Eingabe und schränkt nichts
        // ein — es als „Operation ist leer" zu lesen würde die Liste leeren.
        $this->assertTrue(TransactionSearch::parse('op:')->isEmpty());

        $this->assertFalse(TransactionSearch::parse('checkout')->isEmpty());
        $this->assertFalse(TransactionSearch::parse('op:http.server')->isEmpty());

        // Die Eingabe wandert unverändert (nur ohne Ränder) zurück in das Feld —
        // wer sie umgeschrieben zurückbekäme, würde der Suche nicht mehr trauen.
        $this->assertSame('checkout op:http.server', TransactionSearch::parse(' checkout op:http.server ')->input);
    }

    /**
     * @param  list<TransactionOverviewRow>  $rows
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        return array_map(fn (TransactionOverviewRow $row): string => $row->name, $rows);
    }
}
