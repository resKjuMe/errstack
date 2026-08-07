<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Enums\ScrubRuleType;
use App\Jobs\ProcessIngestPayload;
use App\Models\Event;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\ScrubRule;
use App\Support\Ingest\Scrubbing\Directive;
use App\Support\Ingest\Scrubbing\Scrubber;
use App\Support\Ingest\Scrubbing\ScrubResult;
use App\Support\Ingest\Scrubbing\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Das Entfernen personenbezogener und geheimer Angaben.
 *
 * Die Prüfungen fallen in zwei Gruppen, und die Trennung ist Absicht. Die erste
 * arbeitet ohne Datenbank am Scrubber selbst — dort geht es darum, *was* getroffen
 * wird, und das muss sich an einem Feld-Baum zeigen lassen, ohne ein Projekt
 * aufzubauen. Die zweite lässt eine echte Meldung durch die Kette und prüft die
 * eigentliche Zusage der Aufgabe: dass in der Datenbank nichts landet, was dort
 * nicht stehen darf — auch nicht in der Eingangsablage daneben.
 */
class ScrubbingTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = Scrubber::FILTERED;

    // ---------------------------------------------------------------- Scrubber

    public function test_the_default_fields_are_removed_without_any_configuration(): void
    {
        $result = self::scrub(Settings::of(), [
            'request' => [
                'headers' => ['Authorization' => 'Bearer abc123def456', 'User-Agent' => 'curl/8'],
                'cookies' => ['session' => 'sk_1234'],
                'data' => ['password' => 'geheim', 'betrag' => 4990],
            ],
        ]);

        $this->assertSame(self::MARKER, $result->data['request']['headers']['Authorization']);
        $this->assertSame(self::MARKER, $result->data['request']['data']['password']);

        // Der ganze Keks-Abschnitt ist getroffen — seine Form bleibt aber
        // stehen, damit die Normalisierung danach ein Objekt vorfindet.
        $this->assertSame(['session' => self::MARKER], $result->data['request']['cookies']);

        // Was unverdächtig ist, bleibt: ein Scrubbing, das alles mitnimmt, macht
        // das Werkzeug wertlos.
        $this->assertSame('curl/8', $result->data['request']['headers']['User-Agent']);
        $this->assertSame(4990, $result->data['request']['data']['betrag']);
    }

    /**
     * Ein Feldname trifft auch den ganzen Ast darunter. `password: {"neu": …}`
     * ist ebenso ein Kennwort wie `password: "…"` — und keiner der Werte darin
     * bleibt stehen, ohne dass eine Regel für ihn nötig wäre.
     */
    public function test_a_field_rule_takes_every_value_below_it(): void
    {
        $result = self::scrub(Settings::of(), [
            'extra' => ['password' => ['alt' => 'a', 'neu' => ['b', 'c']]],
        ]);

        $this->assertSame(
            ['alt' => self::MARKER, 'neu' => [self::MARKER, self::MARKER]],
            $result->data['extra']['password'],
        );
    }

    /**
     * Ein Muster greift mitten im Text — und ersetzt nur den Treffer. Der Satz
     * darum ist die Auskunft, um die es geht.
     */
    public function test_a_pattern_replaces_only_the_match(): void
    {
        $result = self::scrub(Settings::of(), [
            'message' => 'Zahlung fehlgeschlagen für Karte 4111111111111111 im Shop.',
        ]);

        $this->assertSame(
            'Zahlung fehlgeschlagen für Karte '.self::MARKER.' im Shop.',
            $result->data['message'],
        );
    }

    /**
     * Ein Wert, der als Zahl kommt, ist derselbe Wert. Nur Texte anzusehen wäre
     * eine Lücke, die ein weggelassenes Anführungszeichen aufmacht.
     */
    public function test_a_pattern_also_looks_at_numbers(): void
    {
        $result = self::scrub(Settings::of(), ['extra' => ['nr' => 4111111111111111]]);

        $this->assertSame(self::MARKER, $result->data['extra']['nr']);

        // Was kein Treffer ist, behält seinen Typ — sonst wäre nach dem
        // Scrubbing aus jeder Zahl ein Text geworden.
        $this->assertSame(4990, self::scrub(Settings::of(), ['extra' => ['betrag' => 4990]])->data['extra']['betrag']);
    }

    /**
     * Ein Nachweis in der Adresse. Die Feld-Regel greift dort, obwohl es dort
     * kein Feld gibt: die Adresse ist **ein** Text, und sie ist bei einem
     * Web-Fehler das erste, was gespeichert wird.
     */
    public function test_query_parameters_in_an_address_are_filtered(): void
    {
        $settings = Settings::of([new Directive(ScrubRuleType::Field, 'kundennummer')]);

        $result = self::scrub($settings, [
            'request' => [
                'url' => 'https://example.com/kasse?token=abc123&kundennummer=K-1&seite=2',
                'query_string' => 'password=geheim&sortierung=preis',
            ],
        ]);

        $this->assertSame(
            'https://example.com/kasse?token='.self::MARKER.'&kundennummer='.self::MARKER.'&seite=2',
            $result->data['request']['url'],
        );
        $this->assertSame(
            'password='.self::MARKER.'&sortierung=preis',
            $result->data['request']['query_string'],
        );
    }

    /**
     * Ein Gleichheitszeichen ist nicht schon ein Abfrage-Wert. Sonst würde jede
     * Zuweisung in einer Quelltextzeile geschwärzt.
     */
    public function test_an_equals_sign_outside_an_address_is_left_alone(): void
    {
        $result = self::scrub(Settings::of(), [
            'exception' => ['values' => [['stacktrace' => ['frames' => [
                ['context_line' => '$password = $request->input("password");'],
            ]]]]],
        ]);

        $frames = $result->data['exception']['values'][0]['stacktrace']['frames'];

        $this->assertSame('$password = $request->input("password");', $frames[0]['context_line']);
    }

    public function test_a_custom_field_rule_can_be_limited_to_a_section(): void
    {
        $settings = Settings::of([new Directive(ScrubRuleType::Field, 'id', 'request.data')]);

        $result = self::scrub($settings, [
            'request' => ['data' => ['id' => '4711']],
            'user' => ['id' => '4711'],
        ]);

        $this->assertSame(self::MARKER, $result->data['request']['data']['id']);
        $this->assertSame('4711', $result->data['user']['id'], 'Die Einschränkung muss gelten.');
    }

    /**
     * Der Platzhalter ist der Grund, warum eine Regel nicht für jede
     * Schreibweise erneut angelegt werden muss.
     */
    public function test_a_field_rule_matches_case_insensitively_and_with_a_wildcard(): void
    {
        $settings = Settings::of([new Directive(ScrubRuleType::Field, 'kunden_*')]);

        $result = self::scrub($settings, [
            'extra' => ['Kunden_ID' => '1', 'kunden_name' => 'Meier', 'kunde' => 'nein'],
        ]);

        $this->assertSame(self::MARKER, $result->data['extra']['Kunden_ID']);
        $this->assertSame(self::MARKER, $result->data['extra']['kunden_name']);
        $this->assertSame('nein', $result->data['extra']['kunde']);
    }

    /**
     * Listen tragen keinen Zähler im Weg — sonst wäre eine Regel beim zweiten
     * Stapelrahmen wirkungslos.
     */
    public function test_a_rule_applies_to_every_entry_of_a_list(): void
    {
        $settings = Settings::of([new Directive(ScrubRuleType::Field, 'vars', 'exception.values.stacktrace.frames')]);

        $result = self::scrub($settings, [
            'exception' => ['values' => [
                ['stacktrace' => ['frames' => [
                    ['function' => 'a', 'vars' => ['x' => 1]],
                    ['function' => 'b', 'vars' => ['y' => 2]],
                ]]],
            ]],
        ]);

        $frames = $result->data['exception']['values'][0]['stacktrace']['frames'];

        $this->assertSame(['x' => self::MARKER], $frames[0]['vars']);
        $this->assertSame(['y' => self::MARKER], $frames[1]['vars']);
        $this->assertSame('b', $frames[1]['function']);
    }

    /**
     * Ein Ausdruck, der sich nicht übersetzen lässt, darf die Aufnahme nicht
     * anhalten — die übrigen Regeln greifen weiter.
     */
    public function test_an_unusable_rule_is_skipped_instead_of_breaking_the_run(): void
    {
        $broken = new Directive(ScrubRuleType::Pattern, '([unvollständig');

        $this->assertFalse($broken->isUsable());

        $result = self::scrub(Settings::of([$broken]), ['extra' => ['password' => 'x']]);

        $this->assertSame(self::MARKER, $result->data['extra']['password']);
    }

    /**
     * Was tiefer liegt als die Grenze, wird entfernt und nicht durchgelassen.
     * Ein Kennwort in der vierzigsten Ebene ist genauso wenig zu speichern wie
     * eines in der ersten.
     */
    public function test_everything_below_the_depth_limit_is_removed(): void
    {
        $deep = 'geheim';

        for ($level = 0; $level < Scrubber::MAX_DEPTH + 5; $level++) {
            $deep = ['tiefer' => $deep];
        }

        $result = self::scrub(Settings::of(), ['extra' => $deep]);

        $this->assertStringNotContainsString(
            'geheim',
            (string) json_encode($result->data),
            'Unter der Tiefengrenze darf nichts durchkommen.',
        );
        $this->assertTrue($result->changed());
    }

    public function test_the_report_names_the_paths_that_changed(): void
    {
        $result = self::scrub(Settings::of(), [
            'request' => ['data' => ['password' => 'x', 'betrag' => 1]],
        ]);

        $this->assertSame(['request.data.password'], $result->paths);
    }

    // ------------------------------------------------------- Schalter am Projekt

    public function test_the_ip_option_removes_the_address_everywhere_it_appears(): void
    {
        $settings = Settings::of(scrubIpAddresses: true);

        $result = self::scrub($settings, [
            'user' => ['id' => '7', 'ip_address' => '203.0.113.9'],
            'request' => [
                'env' => ['REMOTE_ADDR' => '203.0.113.9', 'SERVER_NAME' => 'shop'],
                'headers' => ['X-Forwarded-For' => '203.0.113.9'],
            ],
        ]);

        $this->assertSame(self::MARKER, $result->data['user']['ip_address']);
        $this->assertSame(self::MARKER, $result->data['request']['env']['REMOTE_ADDR']);
        $this->assertSame(self::MARKER, $result->data['request']['headers']['X-Forwarded-For']);

        // Die Kennung des Betroffenen bleibt: sie ist nicht die Adresse, und mit
        // ihr lässt sich weiter zählen, wie viele betroffen sind.
        $this->assertSame('7', $result->data['user']['id']);
        $this->assertSame('shop', $result->data['request']['env']['SERVER_NAME']);
    }

    public function test_the_user_option_removes_the_whole_section_including_unknown_fields(): void
    {
        $settings = Settings::of(scrubUserData: true);

        $result = self::scrub($settings, [
            'user' => ['id' => '7', 'email' => 'a@example.com', 'abteilung' => 'Einkauf'],
            'server_name' => 'shop',
        ]);

        $this->assertSame(
            ['id' => self::MARKER, 'email' => self::MARKER, 'abteilung' => self::MARKER],
            $result->data['user'],
        );
        $this->assertSame('shop', $result->data['server_name']);
    }

    // ------------------------------------------------------------------- Kette

    public function test_a_report_is_stored_without_the_values_the_rules_removed(): void
    {
        $key = self::key();

        $payload = self::accept($key, [
            'message' => 'Kaputt.',
            'request' => [
                'url' => 'https://example.com/kasse',
                'headers' => ['Authorization' => 'Bearer abc123def456'],
                'data' => ['password' => 'geheim123'],
            ],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $event = Event::query()->sole();

        $this->assertSame(self::MARKER, $event->request['headers']['Authorization']);
        $this->assertSame(self::MARKER, $event->request['data']['password']);
        $this->assertSame('https://example.com/kasse', $event->request['url']);
    }

    /**
     * Die eigentliche Zusage: **nirgends** in der Datenbank. Die Eingangsablage
     * daneben hält den Rumpf, wie er ankam — bliebe sie unangetastet, wäre die
     * Zusage für den ausgewerteten Datensatz eingelöst und daneben gebrochen.
     */
    public function test_the_raw_payload_is_rewritten_so_nothing_unscrubbed_stays_behind(): void
    {
        $key = self::key();

        $payload = self::accept($key, [
            'message' => 'Kaputt.',
            'request' => ['data' => ['password' => 'geheim123']],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $payload->refresh();

        $this->assertStringNotContainsString('geheim123', $payload->payload);
        $this->assertSame(self::MARKER, $payload->decoded()['request']['data']['password']);

        // Die angenommene Menge bleibt stehen: sie ist die Grundlage der
        // Abrechnung und nicht die Größe der Spalte.
        $this->assertGreaterThan(0, $payload->size_bytes);
        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
    }

    public function test_a_project_rule_and_an_organization_rule_both_apply(): void
    {
        $key = self::key();
        $project = $key->project;

        ScrubRule::factory()->create([
            'organization_id' => $project->organization_id,
            'expression' => 'kundennummer',
        ]);

        ScrubRule::factory()->forProject($project)->create(['expression' => 'filiale']);

        $payload = self::accept($key, [
            'message' => 'Kaputt.',
            'extra' => ['kundennummer' => 'K-1', 'filiale' => 'F-2', 'schritt' => 'kasse'],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $event = Event::query()->sole();

        $this->assertSame(self::MARKER, $event->extra['kundennummer']);
        $this->assertSame(self::MARKER, $event->extra['filiale']);
        $this->assertSame('kasse', $event->extra['schritt']);
    }

    /**
     * Eine Regel eines fremden Projekts derselben Organisation gilt hier nicht —
     * sonst wäre die Trennung der Projekte keine.
     */
    public function test_a_rule_of_another_project_does_not_apply(): void
    {
        $key = self::key();
        $project = $key->project;

        $other = Project::factory()->create(['organization_id' => $project->organization_id]);
        ScrubRule::factory()->forProject($other)->create(['expression' => 'filiale']);

        $payload = self::accept($key, ['message' => 'Kaputt.', 'extra' => ['filiale' => 'F-2']]);

        ProcessIngestPayload::dispatch($payload);

        $this->assertSame('F-2', Event::query()->sole()->extra['filiale']);
    }

    /**
     * Eine ausgeschaltete Regel greift nicht. Der Weg, eine Regel zu prüfen,
     * ohne sie zu löschen.
     */
    public function test_an_inactive_rule_does_not_apply(): void
    {
        $key = self::key();

        ScrubRule::factory()->forProject($key->project)->create([
            'expression' => 'filiale',
            'is_active' => false,
        ]);

        $payload = self::accept($key, ['message' => 'Kaputt.', 'extra' => ['filiale' => 'F-2']]);

        ProcessIngestPayload::dispatch($payload);

        $this->assertSame('F-2', Event::query()->sole()->extra['filiale']);
    }

    /**
     * „IP-Adresse nicht speichern" wirkt sofort — und ohne den Vermerk am
     * Datensatz, dass an dem Feld etwas kaputt gewesen sei.
     */
    public function test_the_ip_option_takes_effect_on_the_next_report(): void
    {
        $key = self::key();
        $key->project->update(['scrub_ip_addresses' => true]);

        $payload = self::accept($key, [
            'message' => 'Kaputt.',
            'user' => ['id' => '7', 'ip_address' => '203.0.113.9'],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $event = Event::query()->sole();

        $this->assertArrayNotHasKey('ip_address', $event->user ?? []);
        $this->assertSame('7', $event->user['id']);
        $this->assertNotContains(
            'user.ip_address',
            $event->notes['invalid'] ?? [],
            'Ein geschwärztes Feld ist kein kaputtes.',
        );
    }

    public function test_attachments_are_discarded_when_the_project_forbids_them(): void
    {
        $key = self::key();
        $key->project->update(['scrub_attachments' => true]);

        $attachment = IngestPayload::accept(
            key: $key,
            eventId: IngestPayload::freshEventId(),
            payload: "\x89PNG\r\n\x1a\n\x00",
            type: IngestType::Attachment,
        );

        ProcessIngestPayload::dispatch($attachment);

        $this->assertSame(ProcessingState::Dropped, $attachment->refresh()->processing_state);

        $discard = IngestDiscard::query()->sole();
        $this->assertSame(DiscardReason::Scrubbed->value, $discard->reason);
    }

    public function test_attachments_pass_when_the_project_allows_them(): void
    {
        $key = self::key();

        $attachment = IngestPayload::accept(
            key: $key,
            eventId: IngestPayload::freshEventId(),
            payload: "\x89PNG\r\n\x1a\n\x00",
            type: IngestType::Attachment,
        );

        ProcessIngestPayload::dispatch($attachment);

        $this->assertSame(ProcessingState::Processed, $attachment->refresh()->processing_state);
    }

    // ------------------------------------------------------------------ Helfer

    /**
     * @param  array<mixed>  $data
     */
    private static function scrub(Settings $settings, array $data): ScrubResult
    {
        return (new Scrubber($settings))->scrub($data);
    }

    private static function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function accept(ProjectKey $key, array $body): IngestPayload
    {
        return IngestPayload::accept(
            key: $key,
            eventId: IngestPayload::freshEventId(),
            payload: (string) json_encode($body),
        );
    }
}
