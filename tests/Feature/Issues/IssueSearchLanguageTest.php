<?php

namespace Tests\Feature\Issues;

use App\Enums\EventLevel;
use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Unit\Search\ParserTest;

/**
 * Die Suchsprache an der Fehlerliste: was ein Ausdruck findet und was er sagt,
 * wenn er nichts finden kann.
 *
 * Geprüft wird durchgehend über die **Seite** und nicht über den Zerleger — der
 * hat seine eigene Prüfung ({@see ParserTest}). Die Frage
 * hier ist die andere: kommt aus `is:unresolved browser:Chrome` tatsächlich die
 * Liste heraus, die jemand erwartet hat. Ein Ausdrucksbaum, der stimmt, und eine
 * Abfrage, die daneben greift, sähen in einer reinen Zerleger-Prüfung gleich
 * gut aus.
 */
class IssueSearchLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
    }

    /**
     * @return array{User, Project}
     */
    private function context(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user)->create();
        $project = Project::factory()->for($organization)->create();

        $user->switchOrganization($organization);

        return [$user, $project];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function issue(Project $project, string $title, array $attributes = []): Issue
    {
        return Issue::factory()->for($project)->create($attributes + [
            'title' => $title,
            'first_seen' => Carbon::now()->subHours(6),
            'last_seen' => Carbon::now()->subHour(),
        ]);
    }

    /**
     * Ein Merkmal am Eintrag, wie die Aufnahme es hinterlassen hätte.
     */
    private function tag(Project $project, Issue $issue, string $key, string $value): void
    {
        $now = Carbon::now();

        DB::table('issue_tags')->insert([
            'issue_id' => $issue->id,
            'project_id' => $project->id,
            'tag_key' => $key,
            'tag_value' => $value,
            'times_seen' => 1,
            'first_seen' => $now,
            'last_seen' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Eine Meldung mit Nutzer-Angaben unter einem Eintrag.
     *
     * @param  array<string, string>  $user
     */
    private function event(Project $project, Issue $issue, array $user): void
    {
        // Der Fingerabdruck kommt aus dem Eintrag, nicht aus der Fabrik: die
        // würfelt aus drei Fehlerarten, und zwei Gruppen in einem Projekt
        // stoßen so in einem Drittel der Läufe gegen den Eindeutigkeits-Index.
        $group = EventGroup::factory()->for($project)->create([
            'issue_id' => $issue->id,
            'fingerprint' => md5('nutzer-'.$issue->id),
        ]);

        Event::factory()->for($project)->create([
            'event_group_id' => $group->id,
            'user' => $user,
            'occurred_at' => Carbon::now()->subHour(),
            'received_at' => Carbon::now()->subHour(),
        ]);
    }

    /**
     * Die Titel der gefundenen Einträge — die knappste Form, eine Suche zu
     * prüfen.
     *
     * @return list<string>
     */
    private function found(User $user, string $query): array
    {
        $titles = [];

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => $query, 'status' => 'alle', 'period' => '90d']))
            ->assertInertia(function (AssertableInertia $page) use (&$titles): void {
                /** @var array<int, array<string, mixed>> $rows */
                $rows = $page->toArray()['props']['issues']['data'];

                $titles = array_map(fn (array $row): string => (string) $row['title'], $rows);
            });

        sort($titles);

        return $titles;
    }

    public function test_a_state_narrows_the_list(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Offen', ['status' => IssueStatus::Unresolved]);
        $this->issue($project, 'Erledigt', ['status' => IssueStatus::Resolved]);

        $this->assertSame(['Offen'], $this->found($user, 'is:unresolved'));
    }

    public function test_a_tag_narrows_the_list(): void
    {
        [$user, $project] = $this->context();

        $chrome = $this->issue($project, 'In Chrome');
        $firefox = $this->issue($project, 'In Firefox');

        $this->tag($project, $chrome, 'browser', 'Chrome 124');
        $this->tag($project, $firefox, 'browser', 'Firefox 125');

        $this->assertSame(['In Chrome'], $this->found($user, 'browser:"Chrome 124"'));
    }

    /**
     * Der Ausdruck aus der Aufgabenbeschreibung.
     */
    public function test_state_and_tag_together_are_an_and(): void
    {
        [$user, $project] = $this->context();

        $wanted = $this->issue($project, 'Offen in Chrome', ['status' => IssueStatus::Unresolved]);
        $closed = $this->issue($project, 'Erledigt in Chrome', ['status' => IssueStatus::Resolved]);
        $other = $this->issue($project, 'Offen in Firefox', ['status' => IssueStatus::Unresolved]);

        $this->tag($project, $wanted, 'browser', 'Chrome');
        $this->tag($project, $closed, 'browser', 'Chrome');
        $this->tag($project, $other, 'browser', 'Firefox');

        $this->assertSame(['Offen in Chrome'], $this->found($user, 'is:unresolved browser:Chrome'));
    }

    public function test_or_widens_the_list(): void
    {
        [$user, $project] = $this->context();

        $chrome = $this->issue($project, 'Chrome');
        $edge = $this->issue($project, 'Edge');
        $safari = $this->issue($project, 'Safari');

        $this->tag($project, $chrome, 'browser', 'Chrome');
        $this->tag($project, $edge, 'browser', 'Edge');
        $this->tag($project, $safari, 'browser', 'Safari');

        $this->assertSame(
            ['Chrome', 'Edge'],
            $this->found($user, 'browser:Chrome or browser:Edge'),
        );
    }

    public function test_brackets_group_an_or_inside_an_and(): void
    {
        [$user, $project] = $this->context();

        $chrome = $this->issue($project, 'Offen in Chrome', ['status' => IssueStatus::Unresolved]);
        $edge = $this->issue($project, 'Erledigt in Edge', ['status' => IssueStatus::Resolved]);
        $safari = $this->issue($project, 'Offen in Safari', ['status' => IssueStatus::Unresolved]);

        $this->tag($project, $chrome, 'browser', 'Chrome');
        $this->tag($project, $edge, 'browser', 'Edge');
        $this->tag($project, $safari, 'browser', 'Safari');

        $this->assertSame(
            ['Offen in Chrome'],
            $this->found($user, 'is:unresolved (browser:Chrome or browser:Edge)'),
        );
    }

    /**
     * Ein verneintes Merkmal heißt „trägt es nicht" — und findet damit auch die
     * Einträge, die dieses Merkmal gar nicht haben.
     */
    public function test_negation_also_finds_what_carries_no_such_tag(): void
    {
        [$user, $project] = $this->context();

        $chrome = $this->issue($project, 'Chrome');
        $edge = $this->issue($project, 'Edge');
        $this->issue($project, 'Ohne Browser');

        $this->tag($project, $chrome, 'browser', 'Chrome');
        $this->tag($project, $edge, 'browser', 'Edge');

        $this->assertSame(['Edge', 'Ohne Browser'], $this->found($user, '!browser:Chrome'));
    }

    public function test_a_whole_bracket_can_be_negated(): void
    {
        [$user, $project] = $this->context();

        $chrome = $this->issue($project, 'Chrome');
        $edge = $this->issue($project, 'Edge');
        $safari = $this->issue($project, 'Safari');

        $this->tag($project, $chrome, 'browser', 'Chrome');
        $this->tag($project, $edge, 'browser', 'Edge');
        $this->tag($project, $safari, 'browser', 'Safari');

        $this->assertSame(
            ['Safari'],
            $this->found($user, '!(browser:Chrome or browser:Edge)'),
        );
    }

    public function test_a_wildcard_matches_a_part_of_the_value(): void
    {
        [$user, $project] = $this->context();

        $one = $this->issue($project, 'Chrome 124');
        $two = $this->issue($project, 'Chrome 125');
        $three = $this->issue($project, 'Firefox 125');

        $this->tag($project, $one, 'browser', 'Chrome 124');
        $this->tag($project, $two, 'browser', 'Chrome 125');
        $this->tag($project, $three, 'browser', 'Firefox 125');

        $this->assertSame(['Chrome 124', 'Chrome 125'], $this->found($user, 'browser:Chrome*'));
    }

    /**
     * In Anführungszeichen ist der Stern ein Stern — sonst gäbe es keinen Weg,
     * nach einem Wert zu suchen, der einen enthält.
     */
    public function test_a_quoted_wildcard_is_a_literal_star(): void
    {
        [$user, $project] = $this->context();

        $star = $this->issue($project, 'Mit Stern');
        $plain = $this->issue($project, 'Ohne Stern');

        $this->tag($project, $star, 'route', 'GET /shop/*');
        $this->tag($project, $plain, 'route', 'GET /shop/checkout');

        $this->assertSame(['Mit Stern'], $this->found($user, 'route:"GET /shop/*"'));
    }

    public function test_a_percent_sign_in_a_value_is_no_wildcard(): void
    {
        [$user, $project] = $this->context();

        $exact = $this->issue($project, 'Rabatt');
        $other = $this->issue($project, 'Anderes');

        $this->tag($project, $exact, 'coupon', '20%');
        $this->tag($project, $other, 'coupon', '20ab');

        $this->assertSame(['Rabatt'], $this->found($user, 'coupon:20%'));
    }

    public function test_a_comparison_narrows_a_counter(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Häufig', ['times_seen' => 500]);
        $this->issue($project, 'Selten', ['times_seen' => 3]);

        $this->assertSame(['Häufig'], $this->found($user, 'timesSeen:>100'));
    }

    public function test_counters_and_negation_work_together(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Wichtig', ['times_seen' => 500, 'level' => EventLevel::Error]);
        $this->issue($project, 'Nebensache', ['times_seen' => 500, 'level' => EventLevel::Info]);
        $this->issue($project, 'Selten', ['times_seen' => 2, 'level' => EventLevel::Error]);

        $this->assertSame(['Wichtig'], $this->found($user, '!level:info timesSeen:>5'));
    }

    public function test_a_priority_narrows_the_list(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Dringend', ['priority' => IssuePriority::High]);
        $this->issue($project, 'Kann warten', ['priority' => IssuePriority::Low]);

        $this->assertSame(['Dringend'], $this->found($user, 'priority:high'));
    }

    public function test_a_day_means_the_whole_day(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Am Tag', ['first_seen' => Carbon::parse('2026-03-01 23:30:00')]);
        $this->issue($project, 'Tags darauf', ['first_seen' => Carbon::parse('2026-03-02 00:30:00')]);

        $this->assertSame(['Am Tag'], $this->found($user, 'firstSeen:2026-03-01'));
    }

    public function test_a_comparison_on_a_day_takes_its_edges(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Davor', ['first_seen' => Carbon::parse('2026-02-28 12:00:00')]);
        $this->issue($project, 'Am Tag', ['first_seen' => Carbon::parse('2026-03-01 23:30:00')]);
        $this->issue($project, 'Danach', ['first_seen' => Carbon::parse('2026-03-02 00:30:00')]);

        // „Bis zum 1. März" enthält den 1. März — alles andere ist die
        // Enttäuschung, wegen der niemand mehr Datumsfilter benutzt.
        $this->assertSame(['Am Tag', 'Davor'], $this->found($user, 'firstSeen:<=2026-03-01'));
        $this->assertSame(['Danach'], $this->found($user, 'firstSeen:>2026-03-01'));
    }

    public function test_a_span_says_its_direction_itself(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Frisch', ['last_seen' => Carbon::now()->subHours(2)]);
        $this->issue($project, 'Alt', ['last_seen' => Carbon::now()->subDays(9)]);

        $this->assertSame(['Frisch'], $this->found($user, 'lastSeen:-24h'));
        $this->assertSame(['Alt'], $this->found($user, 'lastSeen:+7d'));
    }

    public function test_a_release_narrows_the_list(): void
    {
        [$user, $project] = $this->context();

        $one = Release::factory()->for($project)->version('1.0.0')->create();
        $two = Release::factory()->for($project)->version('1.1.0')->create();

        $this->issue($project, 'Alt', ['first_release_id' => $one->id, 'last_release_id' => $one->id]);
        $this->issue($project, 'Neu', ['first_release_id' => $two->id, 'last_release_id' => $two->id]);

        $this->assertSame(['Neu'], $this->found($user, 'firstRelease:1.1.0'));
    }

    public function test_free_text_searches_title_and_culprit(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'TypeError beim Bezahlen');
        $this->issue($project, 'RangeError beim Suchen', ['culprit' => 'checkout (app/Cart.php)']);
        $this->issue($project, 'Ganz anderes');

        $this->assertSame(
            ['RangeError beim Suchen', 'TypeError beim Bezahlen'],
            $this->found($user, 'Error'),
        );

        $this->assertSame(['RangeError beim Suchen'], $this->found($user, 'checkout'));
    }

    public function test_an_environment_is_a_tag_like_any_other(): void
    {
        [$user, $project] = $this->context();

        $live = $this->issue($project, 'Im Betrieb');
        $test = $this->issue($project, 'Auf der Probe');

        $this->tag($project, $live, 'environment', 'production');
        $this->tag($project, $test, 'environment', 'staging');

        $this->assertSame(['Im Betrieb'], $this->found($user, 'environment:production'));
    }

    /**
     * Die einzige Abfrage der Liste, die Ereignisse liest — und die einzige, die
     * eine Frage beantwortet, die sonst gar nicht zu stellen wäre.
     */
    public function test_a_user_mail_finds_the_issues_of_one_customer(): void
    {
        [$user, $project] = $this->context();

        $hers = $this->issue($project, 'Ihr Fehler');
        $others = $this->issue($project, 'Fremder Fehler');

        $this->event($project, $hers, ['email' => 'ada@example.com', 'id' => '17']);
        $this->event($project, $others, ['email' => 'bob@example.com', 'id' => '18']);

        $this->assertSame(['Ihr Fehler'], $this->found($user, 'user.email:ada@example.com'));
        $this->assertSame(['Fremder Fehler'], $this->found($user, 'user.id:18'));
        $this->assertSame(['Ihr Fehler'], $this->found($user, 'user.email:ada@*'));
    }

    /**
     * Der Fall aus der Aufgabenbeschreibung: bewusst Unsinn eingeben.
     *
     * Die Liste bleibt stehen. Eine leere Seite mit einer Fehlermeldung wäre die
     * Sackgasse, aus der man nur durch Löschen der Adresszeile herausfindet.
     */
    public function test_a_broken_expression_explains_itself_and_keeps_the_list(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Irgendein Fehler');

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'is:']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('searchError.position', 3)
                ->where('list.q', 'is:')
                ->etc()
            );
    }

    public function test_an_unknown_state_names_the_possible_ones(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Irgendein Fehler');

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'is:blau']))
            ->assertInertia(function (AssertableInertia $page): void {
                $error = $page->toArray()['props']['searchError'];

                $this->assertIsArray($error);
                $this->assertStringContainsString('unresolved', (string) $error['message']);
            });
    }

    public function test_a_word_is_not_a_number(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Irgendein Fehler');

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'timesSeen:>viele']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('searchError')
                ->has('issues.data', 1)
                ->etc()
            );
    }

    /**
     * Ein Feld, das es in der Sprache gibt und in den Daten noch nicht,
     * schränkt nichts ein — und sagt das.
     */
    public function test_a_field_without_data_is_named_and_not_faked(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Eins');
        $this->issue($project, 'Zwei');

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'bookmarks:mir', 'status' => 'alle']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 2)
                ->where('unavailableTerms', ['bookmarks:mir'])
                ->where('searchError', null)
                ->etc()
            );
    }

    /**
     * „A oder etwas, das immer zutrifft" trifft immer zu. Die halbe Bedingung
     * still anzuwenden wäre die falsche Antwort — und die, die niemand bemerkt.
     */
    public function test_an_unavailable_half_of_an_or_disables_the_whole_condition(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Offen', ['status' => IssueStatus::Unresolved]);
        $this->issue($project, 'Erledigt', ['status' => IssueStatus::Resolved]);

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'is:unresolved or bookmarks:mir', 'status' => 'alle']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 2)
                ->where('unavailableTerms', ['bookmarks:mir'])
                ->etc()
            );
    }

    /**
     * `is:regressed` fragt nicht nach dem Zustand, sondern danach, wie der
     * Eintrag hineinkam: er ist offen wie jeder andere offene auch — und
     * trotzdem eine andere Nachricht.
     */
    public function test_regressed_finds_the_issues_that_came_back(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Offen', ['status' => IssueStatus::Unresolved]);
        $this->issue($project, 'Zurück', [
            'status' => IssueStatus::Unresolved,
            'regressed_at' => now()->subHour(),
        ]);

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'is:regressed', 'status' => 'alle']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 1)
                ->where('issues.data.0.title', 'Zurück')
                ->where('issues.data.0.regressed', true)
                ->where('unavailableTerms', [])
                ->etc()
            );
    }

    /**
     * Ohne Suchbegriff bleibt die Liste, was sie war.
     */
    public function test_without_a_term_nothing_is_filtered(): void
    {
        [$user, $project] = $this->context();

        $this->issue($project, 'Eins');
        $this->issue($project, 'Zwei');

        $this->actingAs($user)
            ->get(route('issues.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('issues.data', 2)
                ->where('searchError', null)
                ->where('unavailableTerms', [])
                ->etc()
            );
    }

    /**
     * Das Ergebnis ist teilbar: die Eingabe steht in der Adresszeile und kommt
     * unverändert zurück ins Feld.
     */
    public function test_the_expression_stays_in_the_address_bar(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)
            ->get(route('issues.index', ['q' => 'is:unresolved browser:"Chrome 124"']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('list.q', 'is:unresolved browser:"Chrome 124"')
                ->etc()
            );
    }
}
