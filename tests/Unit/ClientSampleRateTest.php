<?php

namespace Tests\Unit;

use App\Support\Ingest\Sampling\ClientSampleRate;
use PHPUnit\Framework\TestCase;

/**
 * Das Lesen der Quote, mit der ein SDK schon vor dem Senden ausgesiebt hat.
 *
 * Die Prüfung ist so kleinteilig, weil der Fehler hier lautlos wäre: wird die
 * Angabe überlesen, fehlt an den gespeicherten Messungen nichts — die Übersicht
 * weist bloß ein Zehntel des Verkehrs aus, und niemand kann es an den Daten
 * sehen. Deshalb steht hier jede Stelle und jede Schreibweise, an der die Quote
 * im Feld auftritt.
 */
class ClientSampleRateTest extends TestCase
{
    public function test_the_rate_is_read_from_the_trace_data(): void
    {
        $rate = ClientSampleRate::fromPayload([
            'contexts' => ['trace' => ['data' => ['sentry.sample_rate' => 0.25]]],
        ]);

        $this->assertSame(0.25, $rate);
    }

    public function test_the_rate_may_be_a_string(): void
    {
        // Der Dynamic Sampling Context führt die Quote als Text, und manche SDKs
        // schreiben sie so auch in den Rumpf.
        $rate = ClientSampleRate::fromPayload([
            'contexts' => ['trace' => ['sample_rate' => '0.1']],
        ]);

        $this->assertSame(0.1, $rate);
    }

    public function test_the_older_spelling_is_accepted(): void
    {
        $rate = ClientSampleRate::fromPayload(['traces_sample_rate' => 0.5]);

        $this->assertSame(0.5, $rate);
    }

    public function test_the_rate_at_the_call_beats_the_one_at_the_sdk(): void
    {
        // Die Einstellung des SDK sagt, was allgemein gilt; die Angabe am Trace
        // sagt, womit **dieser** Aufruf ausgesiebt wurde. Bei einem SDK, das je
        // Vorgang entscheidet, gehen die beiden auseinander — und dann ist die
        // engere die richtige.
        $rate = ClientSampleRate::fromPayload([
            'contexts' => ['trace' => ['data' => ['sentry.sample_rate' => 0.01]]],
            'sdk' => ['traces_sample_rate' => 0.5],
        ]);

        $this->assertSame(0.01, $rate);
    }

    public function test_a_missing_rate_stays_missing(): void
    {
        $this->assertNull(ClientSampleRate::fromPayload([]));
        $this->assertNull(ClientSampleRate::fromPayload(['contexts' => ['trace' => []]]));
    }

    /**
     * Eine Quote von 0 wäre kein „nichts behalten", sondern ein Widerspruch: bei
     * 0 wäre die Meldung nie gesendet worden. Als Gewicht ergäbe sie eine
     * Division durch Null — hier ist der Ort, an dem das aufhört.
     */
    public function test_impossible_rates_are_ignored(): void
    {
        $this->assertNull(ClientSampleRate::fromPayload(['sample_rate' => 0]));
        $this->assertNull(ClientSampleRate::fromPayload(['sample_rate' => -0.5]));
        $this->assertNull(ClientSampleRate::fromPayload(['sample_rate' => 1.5]));
        $this->assertNull(ClientSampleRate::fromPayload(['sample_rate' => 'viel']));
        $this->assertNull(ClientSampleRate::fromPayload(['sample_rate' => ['0.1']]));
    }

    public function test_a_full_rate_is_kept_as_such(): void
    {
        // 1 ist eine gültige Angabe und bedeutet „alles gesendet". Sie zu
        // verwerfen wäre folgenlos, aber die Spalte soll sagen können, dass das
        // SDK sich dazu geäußert hat.
        $this->assertSame(1.0, ClientSampleRate::fromPayload(['sample_rate' => 1]));
    }
}
