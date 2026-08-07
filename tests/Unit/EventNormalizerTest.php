<?php

namespace Tests\Unit;

use App\Enums\EventLevel;
use App\Support\Ingest\Normalization\EventNormalizer;
use App\Support\Ingest\Normalization\Limits;
use App\Support\Ingest\Normalization\NormalizedEvent;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die Normalisierung für sich, ohne Warteschlange und ohne Datenbank.
 *
 * Geprüft wird das Versprechen des Schritts: aus jeder Meldung — gleich welchen
 * SDK, gleich wie gefüllt — entsteht ein Datensatz derselben Form, ohne dass
 * ein kaputter Abschnitt die übrigen mitnimmt. Die Beispiele sind deshalb
 * bewusst schief: so kommen sie im Feld an.
 */
class EventNormalizerTest extends TestCase
{
    /**
     * Die Uhr steht.
     *
     * Nicht der Bequemlichkeit halber: die Normalisierung misst Zeitpunkte
     * gegen „jetzt" — ein Zeitpunkt weit in der Zukunft gilt als Uhrenfehler,
     * einer weit in der Vergangenheit als unbrauchbar. Mit der echten Uhr
     * wären diese Prüfungen vom Tag des Laufs abhängig, und die Tests würden
     * irgendwann von selbst rot.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<mixed>  $data
     */
    private function normalize(array $data, ?Limits $limits = null): NormalizedEvent
    {
        return EventNormalizer::make($limits)->normalize($data, str_repeat('a', 32));
    }

    public function test_a_php_exception_with_a_cause_keeps_its_order(): void
    {
        $event = $this->normalize([
            'platform' => 'php',
            'exception' => ['values' => [
                ['type' => 'PDOException', 'value' => 'connection refused'],
                ['type' => 'RuntimeException', 'value' => 'Bericht konnte nicht erzeugt werden'],
            ]],
        ]);

        $this->assertCount(2, $event->exceptions);

        // Die Ursache zuerst, die zuletzt geworfene Ausnahme zuletzt — genau
        // wie Sentry sie schickt. Ein Umdrehen hier würde Ursache und Wirkung
        // vertauschen.
        $this->assertSame('PDOException', data_get($event->exceptions, '0.type'));
        $this->assertSame('RuntimeException', data_get($event->exceptions, '1.type'));

        // Die Überschrift kommt von der zuletzt geworfenen Ausnahme: das ist
        // die, die die Anwendung gesehen hat.
        $this->assertSame('RuntimeException: Bericht konnte nicht erzeugt werden', $event->title);
        $this->assertTrue($event->hasException());
    }

    public function test_a_stacktrace_keeps_its_frames_in_order(): void
    {
        $event = $this->normalize([
            'exception' => ['values' => [[
                'type' => 'TypeError',
                'stacktrace' => ['frames' => [
                    ['filename' => 'public/index.php', 'function' => 'main', 'lineno' => 12, 'in_app' => true],
                    ['filename' => 'vendor/framework/Kernel.php', 'function' => 'handle', 'lineno' => '340', 'in_app' => 'false'],
                    ['filename' => 'app/Report.php', 'function' => 'build', 'lineno' => 88, 'in_app' => 1, 'context_line' => '    return $this->rows[$key];'],
                ]],
            ]]],
        ]);

        $frames = $event->frames();

        $this->assertCount(3, $frames);
        $this->assertSame('main', data_get($frames, '0.function'));
        $this->assertSame('build', data_get($frames, '2.function'));

        // Zahlen und Wahrheitswerte kommen als Zeichenketten an — SDKs sind
        // darin uneinheitlich, und eine Meldung deswegen zu verwerfen wäre
        // Pedanterie auf Kosten der Daten.
        $this->assertSame(340, data_get($frames, '1.lineno'));
        $this->assertFalse(data_get($frames, '1.in_app'));
        $this->assertTrue(data_get($frames, '2.in_app'));

        // Der letzte Rahmen aus eigenem Code verortet die Meldung.
        $this->assertSame('build (app/Report.php)', $event->culprit);
    }

    public function test_a_loose_stacktrace_is_attached_to_the_exception(): void
    {
        $event = $this->normalize([
            'exception' => ['values' => [['type' => 'ValueError', 'value' => 'kaputt']]],
            'stacktrace' => ['frames' => [['filename' => 'app/Report.php', 'function' => 'build']]],
        ]);

        $this->assertCount(1, $event->frames());
        $this->assertSame('build', data_get($event->frames(), '0.function'));
    }

    public function test_a_message_without_an_exception_survives(): void
    {
        $event = $this->normalize([
            'message' => 'Zahlung ohne Auftrag eingegangen',
            'level' => 'warning',
        ]);

        $this->assertFalse($event->hasException());
        $this->assertSame('Zahlung ohne Auftrag eingegangen', data_get($event->message, 'formatted'));
        $this->assertSame('Zahlung ohne Auftrag eingegangen', $event->title);
        $this->assertSame(EventLevel::Warning, $event->level);
    }

    public function test_a_message_keeps_its_template_and_parameters(): void
    {
        $event = $this->normalize([
            'logentry' => [
                'message' => 'Nutzer %s nicht gefunden',
                'params' => ['4711'],
                'formatted' => 'Nutzer 4711 nicht gefunden',
            ],
        ]);

        // Beides wird gebraucht: die Vorlage, damit ab I5 nicht je Kennung eine
        // eigene Fehlergruppe entsteht — der eingesetzte Text für die Anzeige.
        $this->assertSame('Nutzer %s nicht gefunden', data_get($event->message, 'template'));
        $this->assertSame('Nutzer 4711 nicht gefunden', data_get($event->message, 'formatted'));
        $this->assertSame(['4711'], data_get($event->message, 'params'));
    }

    public function test_missing_level_platform_and_timestamp_get_defaults(): void
    {
        $received = Carbon::parse('2026-08-07 12:00:00');

        $event = EventNormalizer::make()->normalize([], str_repeat('b', 32), $received);

        $this->assertSame(EventLevel::Error, $event->level);
        $this->assertSame(NormalizedEvent::PLATFORM_FALLBACK, $event->platform);
        $this->assertTrue($received->equalTo($event->timestamp));
    }

    public function test_unusual_level_names_are_mapped(): void
    {
        $this->assertSame(EventLevel::Fatal, $this->normalize(['level' => 'critical'])->level);
        $this->assertSame(EventLevel::Warning, $this->normalize(['level' => ' WARN '])->level);
        $this->assertSame(EventLevel::Error, $this->normalize(['level' => 'völlig unbekannt'])->level);
    }

    public function test_all_three_timestamp_notations_are_understood(): void
    {
        $expected = '2026-08-07T09:30:00Z';
        $seconds = Carbon::parse($expected)->getTimestamp();

        // Dieselbe Angabe in den drei Schreibweisen, die im Feld vorkommen —
        // ohne Vereinheitlichung stünden sie unvergleichbar nebeneinander.
        $asFloat = $this->normalize(['timestamp' => $seconds + 0.5])->timestamp;
        $asNumericString = $this->normalize(['timestamp' => (string) $seconds])->timestamp;
        $asIso = $this->normalize(['timestamp' => $expected])->timestamp;

        $this->assertSame($expected, $asFloat->toIso8601ZuluString());
        $this->assertSame($expected, $asNumericString->toIso8601ZuluString());
        $this->assertSame($expected, $asIso->toIso8601ZuluString());
    }

    public function test_a_timestamp_far_in_the_future_falls_back_to_now(): void
    {
        $event = $this->normalize(['timestamp' => '2035-01-01T00:00:00Z']);

        // Eine falsch gestellte Uhr darf nicht dazu führen, dass eine Meldung
        // für neun Jahre oben in jeder Zeitleiste steht.
        $this->assertSame('2026-08-07T12:00:00Z', $event->timestamp->toIso8601ZuluString());
        $this->assertContains('timestamp', $event->notes['invalid'] ?? []);
    }

    public function test_a_timestamp_from_long_ago_falls_back_to_the_time_of_receipt(): void
    {
        $received = Carbon::parse('2026-08-07T11:00:00Z');

        // Der Regelfall dahinter ist ein Feld, das versehentlich in
        // Millisekunden statt Sekunden gefüllt wurde und deshalb um den Faktor
        // 1000 zu klein ist — es landet nahe 1970.
        $event = EventNormalizer::make()->normalize(['timestamp' => 12_345], str_repeat('d', 32), $received);

        $this->assertSame('2026-08-07T11:00:00Z', $event->timestamp->toIso8601ZuluString());
        $this->assertContains('timestamp', $event->notes['invalid'] ?? []);
    }

    public function test_a_broken_section_does_not_take_the_event_with_it(): void
    {
        $event = $this->normalize([
            'exception' => ['values' => [['type' => 'RuntimeException', 'value' => 'kaputt']]],
            // Alles daneben ist falsch geformt: die Anfrage eine Zeichenkette,
            // die Spuren eine Zahl, der Nutzer eine Liste.
            'request' => 'GET /berichte',
            'breadcrumbs' => 42,
            'user' => ['a', 'b'],
        ]);

        $this->assertSame('RuntimeException: kaputt', $event->title);
        $this->assertNull($event->request);
        $this->assertNull($event->user);
        $this->assertSame([], $event->breadcrumbs);

        $invalid = $event->notes['invalid'] ?? [];
        $this->assertContains('request', $invalid);
        $this->assertContains('breadcrumbs', $invalid);
        $this->assertContains('user', $invalid);
    }

    public function test_an_over_long_value_is_truncated_and_marked(): void
    {
        $limits = new Limits(stringChars: 50);

        $event = $this->normalize([
            'exception' => ['values' => [['type' => 'RuntimeException', 'value' => str_repeat('x', 5_000)]]],
        ], $limits);

        $this->assertSame(50, mb_strlen((string) data_get($event->exceptions, '0.value')));
        $this->assertContains('exception.0.value', $event->notes['truncated'] ?? []);
    }

    public function test_too_many_frames_are_cut_at_the_end(): void
    {
        $frames = [];

        for ($i = 0; $i < 20; $i++) {
            $frames[] = ['function' => 'f'.$i];
        }

        $event = $this->normalize([
            'exception' => ['values' => [['type' => 'E', 'stacktrace' => ['frames' => $frames]]]],
        ], new Limits(frames: 5));

        // Vorn steht der älteste Rahmen. Wer dort abschneidet, verliert den
        // Einstiegspunkt — deshalb fällt das Ende weg.
        $this->assertCount(5, $event->frames());
        $this->assertSame('f0', data_get($event->frames(), '0.function'));
        $this->assertContains('exception.0.stacktrace', $event->notes['truncated'] ?? []);
    }

    public function test_too_many_breadcrumbs_are_cut_at_the_beginning(): void
    {
        $crumbs = [];

        for ($i = 0; $i < 20; $i++) {
            $crumbs[] = ['message' => 'schritt '.$i];
        }

        $event = $this->normalize(['breadcrumbs' => ['values' => $crumbs]], new Limits(breadcrumbs: 5));

        // Umgekehrt zum Stacktrace: die jüngste Spur ging dem Fehler
        // unmittelbar voraus und ist die wertvollste.
        $this->assertCount(5, $event->breadcrumbs);
        $this->assertSame('schritt 15', data_get($event->breadcrumbs, '0.message'));
        $this->assertSame('schritt 19', data_get($event->breadcrumbs, '4.message'));
        $this->assertContains('breadcrumbs', $event->notes['truncated'] ?? []);
    }

    public function test_unknown_fields_are_kept(): void
    {
        $event = $this->normalize([
            'message' => 'hallo',
            'ein_ganz_neues_feld' => ['a' => 1],
        ]);

        $this->assertSame(['a' => 1], data_get($event->unknown, 'ein_ganz_neues_feld'));
    }

    public function test_a_clean_event_carries_no_notes(): void
    {
        $event = $this->normalize([
            'platform' => 'php',
            'timestamp' => '2026-08-07T12:00:00Z',
            'message' => 'alles in Ordnung',
        ]);

        // Kein Vermerk heißt: unverändert übernommen. Das muss der Regelfall
        // sein, sonst sagt der Vermerk nichts mehr aus.
        $this->assertNull($event->notes);
    }

    public function test_the_request_is_split_into_its_parts(): void
    {
        $event = $this->normalize([
            'request' => [
                'url' => 'https://beispiel.test/berichte',
                'method' => 'post',
                'query_string' => 'seite=2&sortierung=datum',
                'headers' => ['User-Agent' => 'Mozilla/5.0', 'X-Ids' => ['a', 'b']],
                'data' => ['bericht' => ['id' => 7]],
            ],
        ]);

        $this->assertSame('POST', data_get($event->request, 'method'));
        $this->assertSame(['seite' => '2', 'sortierung' => 'datum'], data_get($event->request, 'query_string'));
        $this->assertSame('Mozilla/5.0', data_get($event->request, 'headers.User-Agent'));
        $this->assertSame('a, b', data_get($event->request, 'headers.X-Ids'));
        $this->assertSame(['bericht' => ['id' => 7]], data_get($event->request, 'data'));
    }

    public function test_the_user_keeps_its_own_fields_and_a_placeholder_ip_is_dropped(): void
    {
        $event = $this->normalize([
            'user' => [
                'id' => '4711',
                'email' => 'k@beispiel.test',
                'ip_address' => '{{auto}}',
                'geo' => ['country_code' => 'DE'],
                'abteilung' => 'Vertrieb',
            ],
        ]);

        $this->assertSame('4711', data_get($event->user, 'id'));
        $this->assertArrayNotHasKey('ip_address', $event->user);
        $this->assertSame('DE', data_get($event->user, 'geo.country_code'));
        $this->assertSame('Vertrieb', data_get($event->user, 'data.abteilung'));
    }

    public function test_contexts_keep_unknown_boxes_and_normalize_the_trace(): void
    {
        $event = $this->normalize([
            'contexts' => [
                'os' => ['name' => 'Linux', 'version' => '6.8'],
                'unser_eigenes' => ['irgendwas' => true],
                'trace' => ['trace_id' => 'AABBCCDDEEFF00112233445566778899', 'op' => 'http.server'],
            ],
        ]);

        $this->assertSame('Linux', data_get($event->contexts, 'os.name'));
        $this->assertSame('os', data_get($event->contexts, 'os.type'));
        $this->assertSame('unser_eigenes', data_get($event->contexts, 'unser_eigenes.type'));

        // Kleingeschrieben, sonst finden Fehler und Transaktion desselben
        // Vorgangs nicht zueinander.
        $this->assertSame('aabbccddeeff00112233445566778899', data_get($event->contexts, 'trace.trace_id'));
    }

    public function test_tags_are_accepted_as_object_and_as_pairs(): void
    {
        $asObject = $this->normalize(['tags' => ['umgebung' => 'produktion', 'kunde' => 7]]);
        $asPairs = $this->normalize(['tags' => [['umgebung', 'produktion'], ['kunde', 7]]]);

        $this->assertSame(['umgebung' => 'produktion', 'kunde' => '7'], $asObject->tags);
        $this->assertSame($asObject->tags, $asPairs->tags);
    }

    public function test_a_deeply_nested_extra_is_cut_off(): void
    {
        $deep = 'tief';

        for ($i = 0; $i < 20; $i++) {
            $deep = ['weiter' => $deep];
        }

        $event = $this->normalize(['extra' => ['start' => $deep]], new Limits(depth: 3));

        $this->assertNotNull($event->extra);
        $this->assertNotEmpty($event->notes['truncated'] ?? []);
    }

    public function test_the_result_survives_json_encoding(): void
    {
        // Ungültige Bytefolgen und Sonderwerte kommen im Feld vor. Käme davon
        // etwas durch, würde nicht dieser Schritt scheitern, sondern das
        // Ablegen — und die ganze Meldung wäre weg.
        $event = $this->normalize([
            'message' => "kaputt\xB1\x00",
            'extra' => ['zahl' => INF, 'text' => "\xC3\x28"],
        ]);

        $this->assertIsString(json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_a_body_that_is_a_list_yields_an_event_instead_of_an_error(): void
    {
        $event = EventNormalizer::make()->normalize(['a', 'b'], str_repeat('c', 32));

        $this->assertSame(str_repeat('c', 32), $event->eventId);
        $this->assertContains('', $event->notes['invalid'] ?? []);
    }
}
