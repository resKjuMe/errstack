<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Enums\InboundFilterKind;
use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Jobs\ProcessIngestPayload;
use App\Models\Event;
use App\Models\InboundFilterRule;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Support\Ingest\Filtering\InboundFilter;
use App\Support\Ingest\Filtering\Settings;
use App\Support\Ingest\Filtering\Verdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Eingangsfilter: was das Projekt gar nicht erst sehen will.
 *
 * Zwei Gruppen, und die Trennung ist dieselbe wie beim Scrubbing. Die erste
 * arbeitet ohne Datenbank am Filter selbst — dort geht es darum, *was*
 * getroffen wird, und das muss sich an einem Feld-Baum zeigen lassen. Die
 * zweite lässt eine echte Meldung durch die Kette und prüft die eigentliche
 * Zusage: dass die Meldung nicht in der Liste steht, dass sie trotzdem gezählt
 * wird, und dass die Zählung sagt, *welcher* Filter sie genommen hat.
 */
class InboundFilterTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Filter

    public function test_nothing_is_filtered_while_every_switch_is_off(): void
    {
        $verdict = self::filter(Settings::of(), [
            'message' => 'Kaputt.',
            'request' => ['url' => 'http://localhost:5173/kasse'],
        ]);

        $this->assertNull($verdict);
    }

    public function test_a_frame_from_a_browser_extension_is_recognised(): void
    {
        $verdict = self::filter(
            Settings::of([InboundFilterKind::BrowserExtension]),
            [
                'exception' => ['values' => [[
                    'type' => 'TypeError',
                    'stacktrace' => ['frames' => [
                        ['abs_path' => 'https://example.com/app.js'],
                        ['abs_path' => 'chrome-extension://abcdef/content.js'],
                    ]],
                ]]],
            ],
        );

        $this->assertSame(InboundFilterKind::BrowserExtension, $verdict?->kind);
    }

    /**
     * Manche Erweiterungen hinterlassen überhaupt keinen Stapelrahmen — sie sind
     * nur an ihrem Fehlertext zu erkennen. Ohne diesen zweiten Weg bliebe genau
     * der Teil übrig, der am häufigsten stört.
     */
    public function test_a_known_extension_message_is_recognised_without_a_stacktrace(): void
    {
        $verdict = self::filter(
            Settings::of([InboundFilterKind::BrowserExtension]),
            ['message' => 'conduitPage is not defined'],
        );

        $this->assertSame(InboundFilterKind::BrowserExtension, $verdict?->kind);
    }

    public function test_an_ordinary_frontend_error_survives_the_extension_filter(): void
    {
        $verdict = self::filter(
            Settings::of([InboundFilterKind::BrowserExtension]),
            [
                'message' => 'Zahlung fehlgeschlagen.',
                'exception' => ['values' => [[
                    'stacktrace' => ['frames' => [['abs_path' => 'https://example.com/assets/app.js']]],
                ]]],
            ],
        );

        $this->assertNull($verdict);
    }

    public function test_the_browser_threshold_decides(): void
    {
        $settings = Settings::of([InboundFilterKind::LegacyBrowser]);

        $cases = [
            // Der Internet Explorer ist in jeder Fassung veraltet.
            ['IE', '11.0', true],
            ['Safari', '5.1.7', true],
            ['Safari', '16.4', false],
            // Opera Mini zählt eigenständig: Fassung 9 ist dort aktuell und
            // darf nicht an der Grenze von Opera 15 scheitern.
            ['Opera Mini', '9', false],
            // Browser, die sich selbst aktuell halten, stehen auf keiner Liste.
            ['Chrome', '4', false],
            // Ohne lesbare Fassung wird nicht geraten.
            ['Safari', '', false],
        ];

        foreach ($cases as [$name, $version, $expected]) {
            $verdict = self::filter($settings, [
                'message' => 'Kaputt.',
                'contexts' => ['browser' => ['name' => $name, 'version' => $version]],
            ]);

            $this->assertSame($expected, $verdict !== null, "{$name} {$version}");
        }
    }

    public function test_an_own_browser_entry_replaces_the_defaults(): void
    {
        $settings = Settings::of(
            [InboundFilterKind::LegacyBrowser],
            [InboundFilterKind::LegacyBrowser->value => ['chrome:100']],
        );

        // Die Vorgabe hätte Safari 5 genommen; der eigene Eintrag ersetzt sie
        // und nicht ergänzt sie.
        $this->assertNull(self::filter($settings, [
            'contexts' => ['browser' => ['name' => 'Safari', 'version' => '5']],
        ]));

        $this->assertSame(InboundFilterKind::LegacyBrowser, self::filter($settings, [
            'contexts' => ['browser' => ['name' => 'Chrome', 'version' => '99.0.1']],
        ])?->kind);
    }

    public function test_local_development_is_recognised_from_url_host_and_server_name(): void
    {
        $settings = Settings::of([InboundFilterKind::Localhost]);

        foreach ([
            ['request' => ['url' => 'http://localhost:5173/kasse']],
            ['request' => ['headers' => ['Host' => 'shop.test:8000']]],
            ['server_name' => 'entwicklung.localhost'],
        ] as $data) {
            $this->assertSame(
                InboundFilterKind::Localhost,
                self::filter($settings, $data)?->kind,
                'Nicht erkannt: '.json_encode($data),
            );
        }

        $this->assertNull(self::filter($settings, ['request' => ['url' => 'https://example.com/kasse']]));
    }

    public function test_a_crawler_is_recognised_from_its_user_agent(): void
    {
        $settings = Settings::of([InboundFilterKind::Crawler]);

        $crawlers = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            // Die Unterarten mit Bindestrich sind der Grund, warum das
            // Trennzeichen in der Liste steht — sie kommen in Mengen.
            'Googlebot-Image/1.0',
            'Mozilla/5.0 (compatible; DuckDuckBot-Https/1.1; https://duckduckgo.com/duckduckbot)',
            // Und der Fall ohne alles dahinter.
            'AhrefsBot',
            'facebookexternalhit/1.1',
            'curl/8.4.0',
        ];

        foreach ($crawlers as $agent) {
            $this->assertSame(
                InboundFilterKind::Crawler,
                self::filter($settings, ['request' => ['headers' => ['user-agent' => $agent]]])?->kind,
                "Nicht erkannt: {$agent}",
            );
        }

        $this->assertNull(self::filter($settings, [
            'request' => ['headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120']],
        ]));
    }

    /**
     * Der Fehlertext wird in mehreren Formen gehalten — als Meldung, als
     * Ausnahmetext und als beides zusammen. Ein Muster, das nur eine davon
     * träfe, ginge bei der Hälfte der SDKs ins Leere.
     */
    public function test_a_pattern_matches_message_and_exception_alike(): void
    {
        $settings = Settings::of(
            [InboundFilterKind::MessagePattern],
            [InboundFilterKind::MessagePattern->value => ['*ResizeObserver loop*']],
        );

        $this->assertNotNull(self::filter($settings, [
            'message' => 'ResizeObserver loop limit exceeded',
        ]));

        $this->assertNotNull(self::filter($settings, [
            'exception' => ['values' => [[
                'type' => 'Error',
                'value' => 'ResizeObserver loop completed with undelivered notifications.',
            ]]],
        ]));

        $this->assertNull(self::filter($settings, ['message' => 'Zahlung fehlgeschlagen.']));
    }

    /**
     * Ohne Platzhalter wird der ganze Text verglichen. Das ist strenger als
     * bequem — aber eine Sperre `1.2` dürfte nicht `21.2.5` mitnehmen.
     */
    public function test_a_pattern_without_wildcards_matches_the_whole_text(): void
    {
        $settings = Settings::of(
            [InboundFilterKind::MessagePattern],
            [InboundFilterKind::MessagePattern->value => ['Script error.']],
        );

        $this->assertNotNull(self::filter($settings, ['message' => 'Script error.']));
        $this->assertNull(self::filter($settings, ['message' => 'Script error. Details folgen.']));
    }

    public function test_an_address_is_matched_exactly_and_by_network(): void
    {
        $settings = Settings::of(
            [InboundFilterKind::IpAddress],
            [InboundFilterKind::IpAddress->value => ['203.0.113.0/24', '2001:db8::1']],
        );

        $this->assertNotNull(self::filter($settings, ['user' => ['ip_address' => '203.0.113.42']]));
        $this->assertNotNull(self::filter($settings, ['request' => ['env' => ['REMOTE_ADDR' => '203.0.113.1']]]));
        $this->assertNotNull(self::filter($settings, ['user' => ['ip_address' => '2001:0db8:0000::0001']]));

        $this->assertNull(self::filter($settings, ['user' => ['ip_address' => '203.0.114.42']]));
        $this->assertNull(self::filter($settings, ['user' => ['ip_address' => '2001:db8::2']]));
    }

    /**
     * Eine weitergereichte Kopfzeile ist frei wählbar. Hörte die Sperrliste auf
     * sie, könnte sich jeder Absender in die Sperre eines anderen schreiben —
     * oder sich aus seiner eigenen heraus.
     */
    public function test_a_forwarded_header_does_not_count_as_the_sender(): void
    {
        $settings = Settings::of(
            [InboundFilterKind::IpAddress],
            [InboundFilterKind::IpAddress->value => ['203.0.113.42']],
        );

        $this->assertNull(self::filter($settings, [
            'request' => ['headers' => ['X-Forwarded-For' => '203.0.113.42']],
        ]));
    }

    public function test_a_release_is_blocked_with_wildcards(): void
    {
        $settings = Settings::of(
            [InboundFilterKind::Release],
            [InboundFilterKind::Release->value => ['1.4.*']],
        );

        $this->assertNotNull(self::filter($settings, ['release' => '1.4.7']));
        $this->assertNull(self::filter($settings, ['release' => '1.5.0']));
    }

    /**
     * Ein Ausnahmetext hat bei PHP, Python und Java regelmäßig mehrere Zeilen.
     * Ohne den passenden Modifikator ginge `*Foo*` an ihm vorbei — und zwar
     * still, was hier der teurere Fehler ist als ein Treffer zu viel.
     */
    public function test_a_pattern_reaches_across_line_breaks(): void
    {
        $settings = Settings::of(
            [InboundFilterKind::MessagePattern],
            [InboundFilterKind::MessagePattern->value => ['*Verbindung verweigert*']],
        );

        $this->assertNotNull(self::filter($settings, [
            'exception' => ['values' => [[
                'type' => 'PDOException',
                'value' => 'SQLSTATE[HY000] Verbindung verweigert
  in Datenbank.php:42
  Stapel …',
            ]]],
        ]));
    }

    /**
     * Die eingebauten Listen dürfen keinen echten Verkehr treffen. Jeder Eintrag
     * hier hat schon einmal jemandem die Fehlerliste geleert.
     */
    public function test_the_built_in_lists_leave_ordinary_traffic_alone(): void
    {
        // `*bot*` als nackter Teiltreffer nähme diese Android-Telefone mit —
        // die Marke heißt Cubot, und der Modellname steht im User-Agent, mal
        // mit Unterstrichen und mal mit Leerzeichen.
        foreach ([
            'Mozilla/5.0 (Linux; Android 10; CUBOT_NOTE_7) Chrome/83.0',
            'Mozilla/5.0 (Linux; Android 11; CUBOT NOTE 20 Build/RP1A) Chrome/94.0',
        ] as $agent) {
            $this->assertNull(
                self::filter(Settings::of([InboundFilterKind::Crawler]), [
                    'request' => ['headers' => ['User-Agent' => $agent]],
                ]),
                "Fehltreffer: {$agent}",
            );
        }

        // `*.local` ist nicht nur das Heimnetz: so heißen Kubernetes-Dienste und
        // Maschinen in Windows-Netzen.
        $this->assertNull(self::filter(Settings::of([InboundFilterKind::Localhost]), [
            'server_name' => 'kasse-7d9f.produktion.svc.cluster.local',
        ]));

        // `*/extensions/*` gegen die Rahmenpfade nähme jede Anwendung mit, die
        // ein solches Verzeichnis oder eine solche Route hat.
        $this->assertNull(self::filter(Settings::of([InboundFilterKind::BrowserExtension]), [
            'transaction' => 'GET /admin/extensions/list',
            'exception' => ['values' => [[
                'stacktrace' => ['frames' => [['abs_path' => '/var/www/app/extensions/Foo.php']]],
            ]]],
        ]));
    }

    /**
     * Eine IPv4-Adresse im IPv6-Kleid ist dieselbe Maschine. Ohne die
     * Rückführung ginge eine Sperre auf einem Anschluss, der beide Familien
     * bedient, unbemerkt ins Leere.
     */
    public function test_an_ipv4_address_in_ipv6_clothing_still_matches(): void
    {
        $settings = Settings::of(
            [InboundFilterKind::IpAddress],
            [InboundFilterKind::IpAddress->value => ['203.0.113.0/24']],
        );

        $this->assertNotNull(self::filter($settings, [
            'request' => ['env' => ['REMOTE_ADDR' => '::ffff:203.0.113.5']],
        ]));

        $this->assertNull(self::filter($settings, [
            'request' => ['env' => ['REMOTE_ADDR' => '::ffff:203.0.114.5']],
        ]));

        // Und in der Gegenrichtung: wer das Netz im IPv6-Kleid einträgt, meint
        // dasselbe. Die Länge zählt dann in 128 Bit und muss beim Zurückführen
        // mitwandern — sonst stünde am Ende eine Länge von 120 an einer
        // Adresse mit 32 Bit, und der Eintrag träfe nie.
        $mapped = Settings::of(
            [InboundFilterKind::IpAddress],
            [InboundFilterKind::IpAddress->value => ['::ffff:203.0.113.0/120']],
        );

        $this->assertNotNull(self::filter($mapped, ['user' => ['ip_address' => '203.0.113.5']]));
        $this->assertNull(self::filter($mapped, ['user' => ['ip_address' => '203.0.114.5']]));
    }

    /**
     * Ein Release aus `git rev-parse HEAD` trägt den Zeilenumbruch der Ausgabe
     * mit sich. Verglichen wird auf den ganzen Wert — ohne Kürzen entschiede
     * dieser eine Umbruch darüber, ob die Sperre greift.
     */
    public function test_a_release_is_compared_without_surrounding_whitespace(): void
    {
        $settings = Settings::of(
            [InboundFilterKind::Release],
            [InboundFilterKind::Release->value => ['kanarienvogel-17']],
        );

        $this->assertNotNull(self::filter($settings, ['release' => "kanarienvogel-17\n"]));
    }

    /**
     * Bei IPv6 besteht die Adresse selbst aus Doppelpunkten — ein blindes
     * Abschneiden hinter dem letzten macht aus `::1` ein `:`, und der
     * Vorgabe-Eintrag `::1` griffe nie.
     */
    public function test_the_host_header_survives_an_ipv6_address(): void
    {
        $settings = Settings::of([InboundFilterKind::Localhost]);

        foreach (['[::1]:5173', '[::1]', '::1', 'localhost:8000'] as $header) {
            $this->assertNotNull(
                self::filter($settings, ['request' => ['headers' => ['Host' => $header]]]),
                "Nicht erkannt: {$header}",
            );
        }
    }

    // -------------------------------------------------------------------- Kette

    public function test_a_filtered_report_never_reaches_the_issue_list(): void
    {
        $key = self::key();
        $key->project->update(['filter_localhost' => true]);

        $payload = self::accept($key, [
            'message' => 'Kaputt.',
            'request' => ['url' => 'http://localhost:5173/kasse'],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $this->assertSame(0, Event::query()->count());
        $this->assertSame(ProcessingState::Dropped, $payload->refresh()->processing_state);
    }

    /**
     * Die zweite Hälfte der Zusage: verworfen wird sie, verschwiegen nicht. Die
     * Zählung trägt die Filterart als Merkmal — sonst stünde da nur, dass
     * *irgendetwas* gefiltert wurde.
     */
    public function test_a_filtered_report_is_counted_by_filter_kind(): void
    {
        $key = self::key();
        $key->project->update(['filter_crawlers' => true]);

        $payload = self::accept($key, [
            'message' => 'Kaputt.',
            'request' => ['headers' => ['User-Agent' => 'Googlebot/2.1']],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $discard = IngestDiscard::query()->sole();

        $this->assertSame(DiscardOrigin::Server, $discard->origin);
        $this->assertSame(DiscardReason::Filtered->value, $discard->reason);
        $this->assertSame(InboundFilterKind::Crawler->value, $discard->category);
        $this->assertSame(1, $discard->quantity);
        $this->assertSame($key->project_id, $discard->project_id);
    }

    public function test_an_inactive_entry_does_not_filter(): void
    {
        $key = self::key();
        $key->project->update(['filter_message_patterns' => true]);

        InboundFilterRule::factory()
            ->forProject($key->project)
            ->of(InboundFilterKind::MessagePattern, '*Kaputt*')
            ->create(['is_active' => false]);

        ProcessIngestPayload::dispatch(self::accept($key, ['message' => 'Kaputt.']));

        $this->assertSame(1, Event::query()->count());
    }

    /**
     * Der Eintrag eines fremden Projekts derselben Organisation gilt hier
     * nicht — sonst wäre die Trennung der Projekte keine, und zwar an der
     * Stelle, an der sie am teuersten wäre.
     */
    public function test_an_entry_of_another_project_does_not_apply(): void
    {
        $key = self::key();
        $key->project->update(['filter_message_patterns' => true]);

        $other = Project::factory()->create(['organization_id' => $key->project->organization_id]);

        InboundFilterRule::factory()
            ->forProject($other)
            ->of(InboundFilterKind::MessagePattern, '*Kaputt*')
            ->create();

        ProcessIngestPayload::dispatch(self::accept($key, ['message' => 'Kaputt.']));

        $this->assertSame(1, Event::query()->count());
    }

    /**
     * Ein eingeschalteter Filter ohne Eintrag tut nichts. Das ist die
     * naheliegende Falle: „Muster-Filter an" klingt nach einer Wirkung, und die
     * Liste ist leer.
     */
    public function test_an_enabled_pattern_filter_without_entries_keeps_everything(): void
    {
        $key = self::key();
        $key->project->update(['filter_message_patterns' => true]);

        ProcessIngestPayload::dispatch(self::accept($key, ['message' => 'Kaputt.']));

        $this->assertSame(1, Event::query()->count());
    }

    /**
     * Ein Anhang wird nicht gefiltert — an ihm gibt es keine Felder, an denen
     * sich etwas erkennen ließe. Er darf deshalb auch nicht als „gefiltert"
     * gezählt werden.
     */
    public function test_an_attachment_passes_the_filter_untouched(): void
    {
        $key = self::key();
        $key->project->update(['filter_localhost' => true, 'filter_crawlers' => true]);

        $attachment = IngestPayload::accept(
            key: $key,
            eventId: IngestPayload::freshEventId(),
            payload: "\x89PNG\r\n\x1a\n\x00",
            type: IngestType::Attachment,
        );

        ProcessIngestPayload::dispatch($attachment);

        $this->assertSame(ProcessingState::Processed, $attachment->refresh()->processing_state);
        $this->assertSame(0, IngestDiscard::query()->count());
    }

    /**
     * Die Reihenfolge in der Kette ist die Zusage „Filter wirken vor der
     * aufwändigen Auswertung". Prüfbar ist sie an dem, was danach fehlt: keine
     * Fehlergruppe, kein Ereignis, keine Umgebung.
     */
    public function test_a_filtered_report_costs_no_grouping_and_no_environment(): void
    {
        $key = self::key();
        $key->project->update(['filter_releases' => true]);

        InboundFilterRule::factory()
            ->forProject($key->project)
            ->of(InboundFilterKind::Release, 'kanarienvogel-*')
            ->create();

        $payload = self::accept($key, [
            'message' => 'Kaputt.',
            'release' => 'kanarienvogel-17',
            'environment' => 'produktion',
            'exception' => ['values' => [[
                'type' => 'TypeError',
                'stacktrace' => ['frames' => [['abs_path' => 'https://example.com/app.js', 'lineno' => 12]]],
            ]]],
        ]);

        ProcessIngestPayload::dispatch($payload);

        $this->assertSame(0, Event::query()->count());
        $this->assertSame(0, $key->project->eventGroups()->count());
        $this->assertSame(0, $key->project->environments()->count());
    }

    /**
     * Nicht nur Fehler: eine Transaktion von localhost ist genauso wenig
     * interessant, und die Antwortzeiten-Auswertung ist der teurere der beiden
     * Schritte, die dahinter kämen.
     */
    public function test_a_transaction_is_filtered_like_an_error(): void
    {
        $key = self::key();
        $key->project->update(['filter_localhost' => true]);

        $payload = IngestPayload::accept(
            key: $key,
            eventId: IngestPayload::freshEventId(),
            payload: (string) json_encode([
                'type' => 'transaction',
                'transaction' => 'GET /kasse',
                'request' => ['url' => 'http://localhost:5173/kasse'],
                'start_timestamp' => 1000.0,
                'timestamp' => 1001.0,
            ]),
            type: IngestType::Transaction,
        );

        ProcessIngestPayload::dispatch($payload);

        $this->assertSame(ProcessingState::Dropped, $payload->refresh()->processing_state);
        $this->assertSame(0, $key->project->transactions()->count());
    }

    // ------------------------------------------------------------------ Helfer

    /**
     * @param  array<mixed>  $data
     */
    private static function filter(Settings $settings, array $data): ?Verdict
    {
        return (new InboundFilter($settings))->verdict($data);
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
