<?php

namespace Tests\Unit;

use App\Enums\EventLevel;
use App\Models\Event;
use App\Support\Tags\EventTags;
use Tests\TestCase;

/**
 * Was aus einer Meldung ein Merkmal wird — und was ausdrücklich nicht.
 */
class EventTagsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = []): Event
    {
        $event = new Event;

        $event->forceFill([
            'level' => EventLevel::Error,
            'platform' => 'javascript',
            ...$attributes,
        ]);

        return $event;
    }

    public function test_the_fixed_fields_of_a_report_become_tags(): void
    {
        $tags = EventTags::forEvent($this->event([
            'environment' => 'production',
            'release' => '3.4.1',
            'server_name' => 'web-07',
            'transaction' => 'GET /kasse',
            'logger' => 'app',
        ]));

        $this->assertSame('error', $tags['level']);
        $this->assertSame('javascript', $tags['platform']);
        $this->assertSame('production', $tags['environment']);
        $this->assertSame('3.4.1', $tags['release']);
        $this->assertSame('web-07', $tags['server_name']);
        $this->assertSame('GET /kasse', $tags['transaction']);
        $this->assertSame('app', $tags['logger']);
    }

    public function test_browser_and_operating_system_come_with_and_without_version(): void
    {
        $tags = EventTags::forEvent($this->event([
            'contexts' => [
                'browser' => ['name' => 'Chrome', 'version' => '124.0'],
                'os' => ['name' => 'Windows', 'version' => '11'],
            ],
        ]));

        // Beide Formen werden gebraucht: „tritt das nur in Chrome 124 auf?" und
        // „tritt das in allen Chrome-Fassungen auf?" sind zwei Fragen.
        $this->assertSame('Chrome 124.0', $tags['browser']);
        $this->assertSame('Chrome', $tags['browser.name']);
        $this->assertSame('Windows 11', $tags['os']);
        $this->assertSame('Windows', $tags['os.name']);
    }

    public function test_a_context_without_a_version_keeps_the_bare_name(): void
    {
        $tags = EventTags::forEvent($this->event([
            'contexts' => ['browser' => ['name' => 'Firefox']],
        ]));

        $this->assertSame('Firefox', $tags['browser']);
        $this->assertSame('Firefox', $tags['browser.name']);
    }

    public function test_the_query_string_is_cut_off_the_url(): void
    {
        // Sonst wäre jede Adresse einzigartig und das Merkmal eine Liste mit
        // einer Zeile je Aufruf.
        $tags = EventTags::forEvent($this->event([
            'request' => ['url' => 'https://shop.example/kasse?id=4711'],
        ]));

        $this->assertSame('https://shop.example/kasse', $tags['url']);
    }

    public function test_own_tags_are_taken_over_but_do_not_overwrite_the_fixed_fields(): void
    {
        $tags = EventTags::forEvent($this->event([
            'release' => '3.4.1',
            'tags' => ['mandant' => 'acme', 'release' => 'von-hand'],
        ]));

        $this->assertSame('acme', $tags['mandant']);
        $this->assertSame('3.4.1', $tags['release']);
    }

    public function test_empty_values_do_not_become_tags(): void
    {
        // Ein erfundenes „unbekannt" wäre in der Verteilung ein Balken und in
        // der Suche ein Treffer — eine Aussage, die niemand gemacht hat.
        $tags = EventTags::forEvent($this->event([
            'environment' => null,
            'release' => '   ',
            'tags' => ['leer' => ''],
        ]));

        $this->assertArrayNotHasKey('environment', $tags);
        $this->assertArrayNotHasKey('release', $tags);
        $this->assertArrayNotHasKey('leer', $tags);
    }

    public function test_personal_data_of_the_affected_user_never_becomes_a_tag(): void
    {
        $tags = EventTags::forEvent($this->event([
            'user' => ['id' => '4711', 'email' => 'kunde@example.com', 'ip_address' => '203.0.113.7'],
        ]));

        $this->assertSame(
            [],
            array_intersect_key($tags, array_flip(['user', 'user.id', 'user.email', 'user.ip_address'])),
        );
    }

    public function test_the_sdk_is_a_tag_in_its_short_form(): void
    {
        $tags = EventTags::forEvent($this->event([
            'sdk' => ['name' => 'sentry.javascript.browser', 'version' => '8.0.0'],
        ]));

        $this->assertSame('sentry.javascript.browser/8.0.0', $tags['sdk']);
    }
}
