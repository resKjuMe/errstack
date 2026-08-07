<?php

namespace Tests\Unit;

use App\Enums\IngestType;
use App\Exceptions\IngestRejection;
use App\Support\Ingest\Envelope;
use PHPUnit\Framework\TestCase;

/**
 * Das Zerlegen eines Envelope.
 *
 * Hier steht die Formtreue auf dem Prüfstand, nicht die Fachlichkeit: Wo endet
 * ein Element, wenn eine Länge angegeben ist? Wo, wenn nicht? Was passiert mit
 * einem Zeilenumbruch mitten in einem Anhang? Jede Abweichung davon ist ein
 * echtes SDK, dessen Daten still verloren gehen — deshalb sind es viele kleine
 * Fälle statt eines großen.
 */
class EnvelopeTest extends TestCase
{
    public function test_a_header_only_envelope_is_valid_and_has_no_items(): void
    {
        $envelope = Envelope::parse('{"event_id":"9ec79c33ec9942ab8353589fcb2e04dc"}');

        $this->assertSame([], $envelope->items);
        $this->assertSame(0, $envelope->unreadable);
        $this->assertSame('9ec79c33ec9942ab8353589fcb2e04dc', $envelope->eventId());
    }

    /**
     * `{}` ist ein gültiger Kopf und kommt im Feld laufend vor — ein Envelope
     * aus Sitzungen braucht keine Kopfdaten. Wer ihn mit
     * `json_decode(…, true)` liest und danach auf eine Liste prüft, wirft
     * genau diesen Fall weg, weil `{}` und `[]` dabei beide zu `[]` werden.
     */
    public function test_an_empty_header_object_is_accepted(): void
    {
        $envelope = Envelope::parse("{}\n{\"type\":\"session\"}\n{\"sid\":\"abc\"}");

        $this->assertCount(1, $envelope->items);
        $this->assertSame(IngestType::Session, $envelope->items[0]->type());
    }

    public function test_a_missing_header_is_rejected(): void
    {
        $this->expectException(IngestRejection::class);

        Envelope::parse("kein json\n{\"type\":\"event\"}\n{}");
    }

    /**
     * Ein JSON-Array als Kopfzeile ist kein Kopf, auch wenn es gültiges JSON
     * ist.
     */
    public function test_a_json_array_is_not_a_header(): void
    {
        $this->expectException(IngestRejection::class);

        Envelope::parse("[1,2,3]\n{\"type\":\"event\"}\n{}");
    }

    public function test_items_without_a_declared_length_end_at_the_next_line_break(): void
    {
        $envelope = Envelope::parse(implode("\n", [
            '{}',
            '{"type":"event"}',
            '{"message":"eins"}',
            '{"type":"session"}',
            '{"sid":"zwei"}',
        ]));

        $this->assertCount(2, $envelope->items);
        $this->assertSame('{"message":"eins"}', $envelope->items[0]->payload);
        $this->assertSame('{"sid":"zwei"}', $envelope->items[1]->payload);
    }

    /**
     * Der Fall, an dem ein naives Zerlegen an `\n` scheitert: Binärdaten
     * enthalten Zeilenumbrüche, und ohne die Längenangabe zu beachten, wäre
     * jeder Screenshot ab dem ersten `0x0A` abgeschnitten — und alle folgenden
     * Elemente verschoben.
     */
    public function test_a_declared_length_wins_over_line_breaks_inside_the_payload(): void
    {
        $binary = "PNG\nmit\nUmbruechen";

        $envelope = Envelope::parse(
            '{}'."\n".
            '{"type":"attachment","length":'.strlen($binary).',"filename":"bild.png"}'."\n".
            $binary."\n".
            '{"type":"session"}'."\n".
            '{"sid":"danach"}'
        );

        $this->assertCount(2, $envelope->items);
        $this->assertSame($binary, $envelope->items[0]->payload);
        $this->assertSame('bild.png', $envelope->items[0]->header['filename']);
        $this->assertSame('{"sid":"danach"}', $envelope->items[1]->payload);
    }

    /**
     * Nullbytes sind der zweite Grund, warum die Längenangabe zählt: an ihnen
     * scheitert jede Verarbeitung, die Nutzdaten als Text behandelt.
     */
    public function test_binary_payloads_survive_untouched(): void
    {
        $binary = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0d";

        $envelope = Envelope::parse(
            '{}'."\n".
            '{"type":"attachment","length":'.strlen($binary).'}'."\n".
            $binary
        );

        $this->assertSame($binary, $envelope->items[0]->payload);
    }

    /**
     * Der abschließende Umbruch ist freigestellt — beide Varianten kommen vor,
     * und beide müssen dasselbe ergeben.
     */
    public function test_a_trailing_line_break_is_optional(): void
    {
        $body = "{}\n{\"type\":\"event\",\"length\":18}\n{\"message\":\"eins\"}";

        $this->assertCount(1, Envelope::parse($body)->items);
        $this->assertCount(1, Envelope::parse($body."\n")->items);
    }

    public function test_an_item_may_be_empty(): void
    {
        $envelope = Envelope::parse("{}\n{\"type\":\"attachment\",\"length\":0}\n\n{\"type\":\"session\"}\n{\"sid\":\"x\"}");

        $this->assertCount(2, $envelope->items);
        $this->assertSame('', $envelope->items[0]->payload);
        $this->assertSame(IngestType::Session, $envelope->items[1]->type());
    }

    /**
     * Einzelne SDKs schreiben die Länge als Zeichenkette. Sie deshalb zu
     * ignorieren, würde denselben Schaden anrichten wie gar keine Länge.
     */
    public function test_a_length_given_as_a_string_still_counts(): void
    {
        $envelope = Envelope::parse("{}\n{\"type\":\"attachment\",\"length\":\"4\"}\nA\nB\n{\"type\":\"session\"}\n{\"sid\":\"x\"}");

        $this->assertSame("A\nB\n", $envelope->items[0]->payload);
        $this->assertCount(2, $envelope->items);
    }

    /**
     * Ein abgeschnittener Envelope: die angekündigten Bytes stehen nicht mehr
     * da. Das Element gilt als unlesbar, alles davor bleibt gültig.
     */
    public function test_a_truncated_item_is_counted_and_does_not_take_the_others(): void
    {
        $envelope = Envelope::parse(
            '{}'."\n".
            '{"type":"event"}'."\n".
            '{"message":"heil"}'."\n".
            '{"type":"attachment","length":9999}'."\n".
            'zu kurz'
        );

        $this->assertCount(1, $envelope->items);
        $this->assertSame(1, $envelope->unreadable);
        $this->assertSame('{"message":"heil"}', $envelope->items[0]->payload);
    }

    /**
     * Ein unlesbarer Element-Kopf beendet das Zerlegen: ohne ihn ist unbekannt,
     * wo die Nutzdaten enden. Weiterzuraten würde aus einem kaputten Element
     * mehrere machen.
     */
    public function test_an_unreadable_item_header_stops_the_parsing_without_losing_earlier_items(): void
    {
        $envelope = Envelope::parse("{}\n{\"type\":\"event\"}\n{\"message\":\"heil\"}\nkaputt\n{\"noch\":\"was\"}");

        $this->assertCount(1, $envelope->items);
        $this->assertSame(1, $envelope->unreadable);
    }

    public function test_the_event_id_falls_back_to_the_first_item_that_carries_one(): void
    {
        $envelope = Envelope::parse(
            '{"sent_at":"2026-08-07T10:00:00Z"}'."\n".
            '{"type":"session"}'."\n".
            '{"sid":"ohne nummer"}'."\n".
            '{"type":"event"}'."\n".
            '{"event_id":"A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C5D"}'
        );

        $this->assertSame('a1b2c3d4e5f64a7b8c9d0e1f2a3b4c5d', $envelope->eventId());
    }

    /**
     * Nur Fehler, Transaktion und Aufzeichnungs-Kopf tragen eine eigene Nummer.
     * Ein Anhang mit einem `event_id`-Feld in seinen Bytes ist Zufall, kein
     * Hinweis.
     */
    public function test_only_types_that_carry_an_event_id_are_asked_for_one(): void
    {
        $envelope = Envelope::parse(
            '{}'."\n".
            '{"type":"attachment","length":47}'."\n".
            '{"event_id":"ffffffffffffffffffffffffffffffff"}'
        );

        $this->assertNull($envelope->items[0]->eventId());
        $this->assertNull($envelope->eventId());
    }

    public function test_the_sdk_is_read_from_the_envelope_header(): void
    {
        $envelope = Envelope::parse('{"sdk":{"name":"sentry.javascript.browser","version":"8.0.0"}}');

        $this->assertSame('sentry.javascript.browser/8.0.0', $envelope->sdk());
    }

    public function test_an_incomplete_sdk_entry_is_ignored(): void
    {
        $this->assertNull(Envelope::parse('{"sdk":{"version":"8.0.0"}}')->sdk());
        $this->assertNull(Envelope::parse('{"sdk":"sentry.php"}')->sdk());
        $this->assertSame('sentry.php', Envelope::parse('{"sdk":{"name":"sentry.php"}}')->sdk());
    }

    /**
     * Ein Typ, den wir nicht kennen, bleibt als Rohwert lesbar — die Zählung
     * soll benennen können, was da hereinkam.
     */
    public function test_an_unknown_type_stays_readable_as_a_raw_value(): void
    {
        $envelope = Envelope::parse("{}\n{\"type\":\"Statsd\"}\n{\"was\":\"auch immer\"}");

        $this->assertSame('statsd', $envelope->items[0]->rawType());
        $this->assertNull($envelope->items[0]->type());
    }

    public function test_an_item_without_a_type_has_none(): void
    {
        $envelope = Envelope::parse("{}\n{\"length\":2}\n{}");

        $this->assertNull($envelope->items[0]->rawType());
        $this->assertNull($envelope->items[0]->type());
    }
}
