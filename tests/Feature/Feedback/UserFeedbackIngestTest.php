<?php

namespace Tests\Feature\Feedback;

use App\Enums\IngestType;
use App\Enums\UserReportSource;
use App\Enums\UserReportStatus;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\UserReport;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingPipeline;
use App\Support\Ingest\Processing\Steps\DecodePayload;
use App\Support\Ingest\Processing\Steps\RecordUserReport;
use App\Support\Ingest\Processing\Steps\ScrubEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Die Annahme einer Rückmeldung: über den eigenen Endpunkt, über das Envelope-
 * Element und in beiden Formen, die im Umlauf sind.
 *
 * Der Nachweis, auf den es hier ankommt, ist der Bezug zum Ereignis. Eine
 * Rückmeldung ohne ihn ist ein Zettel ohne Adresse — und weil sie **vor** ihrem
 * Ereignis eintreffen kann, wird der Bezug auch nachträglich noch hergestellt.
 */
class UserFeedbackIngestTest extends TestCase
{
    use RefreshDatabase;

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    private function url(ProjectKey $key, string $path = 'user-feedback'): string
    {
        return "/api/{$key->project_id}/{$path}/";
    }

    /**
     * @return array<string, string>
     */
    private function auth(ProjectKey $key): array
    {
        return $this->transformHeadersToServerVars([
            'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_client=sentry.javascript/8.0.0, sentry_key={$key->public_key}",
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Lässt die abgelegten Rohdaten durch die Kette laufen — dieselbe, die im
     * Betrieb der Job anstößt.
     */
    private function process(): void
    {
        $pipeline = ProcessingPipeline::fromConfig();

        // Nur die Rückmeldungen: die Belege, die eine Test-Meldung nebenbei
        // anlegt, gehören einer anderen Kette und würden hier ihr eigenes
        // Ereignis überschreiben.
        $payloads = IngestPayload::query()
            ->whereIn('type', [IngestType::UserReport, IngestType::Feedback])
            ->get();

        foreach ($payloads as $payload) {
            $pipeline->process(new ProcessingContext($payload));
        }
    }

    public function test_the_endpoint_accepts_a_crash_report_and_links_it_to_its_event(): void
    {
        Queue::fake();

        $key = $this->key();
        $event = $this->event($key->project_id);

        $this->call('POST', $this->url($key), server: $this->auth($key), content: (string) json_encode([
            'event_id' => $event->event_id,
            'name' => 'Anna Beispiel',
            'email' => 'anna@example.test',
            'comments' => "Ich wollte bezahlen.\nDann kam die weiße Seite.",
        ]))->assertStatus(200)->assertJsonPath('id', $event->event_id);

        $this->assertSame(
            IngestType::UserReport,
            IngestPayload::query()->where('type', IngestType::UserReport)->sole()->type,
        );

        $this->process();

        $report = UserReport::query()->sole();

        $this->assertSame('Anna Beispiel', $report->name);
        $this->assertSame('anna@example.test', $report->email);
        $this->assertStringContainsString('weiße Seite', $report->comments);
        $this->assertSame($event->id, $report->event_id);
        $this->assertSame($event->group?->issue_id, $report->issue_id);
        $this->assertSame(UserReportSource::CrashReport, $report->source());
        $this->assertSame(UserReportStatus::New, $report->status);
    }

    /**
     * Der Weg des Widgets: ein abgeschicktes Formular ohne Ereignisnummer.
     */
    public function test_the_endpoint_accepts_a_standalone_report_sent_as_a_form(): void
    {
        Queue::fake();

        $key = $this->key();

        $this->post($this->url($key).'?sentry_key='.$key->public_key, [
            'comments' => 'Der Warenkorb ist auf dem Handy leer.',
            'url' => 'https://shop.example/warenkorb',
        ])->assertStatus(200);

        $this->process();

        $report = UserReport::query()->sole();

        $this->assertNull($report->event_reference);
        $this->assertSame(UserReportSource::Standalone, $report->source());
        $this->assertSame('https://shop.example/warenkorb', $report->url);
    }

    /**
     * Die neue Form: der Text steht unter `contexts.feedback`, und die Nummer
     * **oben** im Element gehört der Rückmeldung selbst. Wer sie für die des
     * Fehlers hält, verknüpft jede Zuschrift mit sich selbst.
     */
    public function test_the_new_shape_uses_the_associated_event_and_not_its_own_id(): void
    {
        Queue::fake();

        $key = $this->key();
        $event = $this->event($key->project_id);
        $ownId = IngestPayload::freshEventId();

        $this->call('POST', $this->url($key), server: $this->auth($key), content: (string) json_encode([
            'event_id' => $ownId,
            'contexts' => [
                'feedback' => [
                    'message' => 'Der Knopf tut nichts.',
                    'name' => 'Bert Beispiel',
                    'contact_email' => 'bert@example.test',
                    'associated_event_id' => $event->event_id,
                    'url' => 'https://shop.example/kasse',
                ],
            ],
        ]))->assertStatus(200);

        $this->process();

        $report = UserReport::query()->sole();

        $this->assertSame($event->event_id, $report->event_reference);
        $this->assertSame($event->id, $report->event_id);
        $this->assertSame('bert@example.test', $report->email);
    }

    /**
     * Der Bezug wird auch dann noch hergestellt, wenn die Rückmeldung vor ihrem
     * Ereignis eintraf — der Regelfall, wenn die Auswertung des Fehlers noch in
     * der Warteschlange steckt.
     */
    public function test_a_report_that_arrived_before_its_event_is_linked_afterwards(): void
    {
        $project = Project::factory()->create();
        $reference = IngestPayload::freshEventId();

        $report = UserReport::factory()->for($project)->create(['event_reference' => $reference]);

        $this->assertNull($report->event_id);

        $event = $this->event($project->id, $reference);

        UserReport::link(UserReport::all());

        $report->refresh();

        $this->assertSame($event->id, $report->event_id);
        $this->assertSame($event->group?->issue_id, $report->issue_id);
    }

    /**
     * Eine Zuschrift ohne Text ist keine — und der Absender ist ein Mensch, der
     * auf eine Antwort wartet. Er bekommt sie sofort und nicht als Schweigen aus
     * der Warteschlange.
     */
    public function test_a_report_without_text_is_rejected_right_away(): void
    {
        $key = $this->key();

        $this->call('POST', $this->url($key), server: $this->auth($key), content: (string) json_encode([
            'name' => 'Nur ein Name',
        ]))->assertStatus(400);

        $this->assertSame(0, IngestPayload::query()->count());
    }

    /**
     * Spam-Schutz: gezählt wird je Absender-Adresse und Projekt.
     */
    public function test_the_endpoint_is_rate_limited_per_address(): void
    {
        Queue::fake();
        config()->set('ingest.feedback.max_per_minute', 2);

        $key = $this->key();

        foreach (range(1, 2) as $attempt) {
            $this->call('POST', $this->url($key), server: $this->auth($key), content: (string) json_encode([
                'comments' => "Zuschrift {$attempt}",
            ]))->assertStatus(200);
        }

        $this->call('POST', $this->url($key), server: $this->auth($key), content: (string) json_encode([
            'comments' => 'Und noch eine',
        ]))->assertStatus(429);
    }

    /**
     * Der Weg heutiger SDKs: die Rückmeldung liegt als Element im Envelope,
     * neben der Meldung, zu der sie gehört. Beide Element-Typen kommen vor.
     */
    public function test_a_report_inside_an_envelope_is_recorded(): void
    {
        Queue::fake();

        $key = $this->key();
        $event = $this->event($key->project_id);

        $body = implode("\n", [
            (string) json_encode(['event_id' => $event->event_id]),
            (string) json_encode(['type' => 'user_report']),
            (string) json_encode([
                'event_id' => $event->event_id,
                'name' => 'Dora Beispiel',
                'comments' => 'Nach dem Klick war die Seite weg.',
            ]),
            (string) json_encode(['type' => 'feedback']),
            (string) json_encode([
                'event_id' => IngestPayload::freshEventId(),
                'contexts' => ['feedback' => ['message' => 'Und hier noch etwas.']],
            ]),
        ])."\n";

        $this->call(
            'POST',
            "/api/{$key->project_id}/envelope/",
            server: $this->transformHeadersToServerVars([
                'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_key={$key->public_key}",
                'Content-Type' => 'application/x-sentry-envelope',
            ]),
            content: $body,
        )->assertStatus(200);

        $this->process();

        $this->assertSame(2, UserReport::query()->count());

        $linked = UserReport::query()->where('name', 'Dora Beispiel')->sole();
        $this->assertSame($event->id, $linked->event_id);

        // Das `feedback`-Element bringt seine eigene Nummer mit und nennt kein
        // fremdes Ereignis: es steht für sich.
        $standalone = UserReport::query()->where('comments', 'Und hier noch etwas.')->sole();
        $this->assertNull($standalone->event_reference);
    }

    /**
     * Die ältere Schreibweise der Adresse gilt weiter — einzelne SDKs nennen sie
     * noch, und eine 404 dort ist eine Rückmeldung, die niemand je liest.
     */
    public function test_the_older_address_works_too(): void
    {
        Queue::fake();

        $key = $this->key();

        $this->call('POST', $this->url($key, 'user-report'), server: $this->auth($key), content: (string) json_encode([
            'comments' => 'Auf dem alten Weg.',
        ]))->assertStatus(200);

        $this->assertSame(
            IngestType::UserReport,
            IngestPayload::query()->where('type', IngestType::UserReport)->sole()->type,
        );
    }

    /**
     * Derselbe Beleg zweimal durch die Kette ergibt **eine** Zeile. Die
     * Warteschlange darf einen Job erneut ausliefern; ohne diese Zusage stünde
     * die Zuschrift danach doppelt in der Liste.
     */
    public function test_processing_the_same_payload_twice_creates_one_report(): void
    {
        Queue::fake();

        $key = $this->key();

        $this->call('POST', $this->url($key), server: $this->auth($key), content: (string) json_encode([
            'comments' => 'Einmal geschrieben.',
        ]))->assertStatus(200);

        $this->process();
        $this->process();

        $this->assertSame(1, UserReport::query()->count());
    }

    /**
     * Das Schwärzen lässt Rückmeldungen in Ruhe. Es ist gegen personenbezogene
     * Daten gerichtet, die ein SDK nebenbei einsammelt — die Antwortadresse
     * dagegen hat die Person selbst angegeben, damit man sich meldet.
     */
    public function test_scrubbing_leaves_the_contact_details_alone(): void
    {
        Queue::fake();

        $key = $this->key();
        $key->project->forceFill(['scrub_user_data' => true, 'scrub_ip_addresses' => true])->save();

        $this->call('POST', $this->url($key), server: $this->auth($key), content: (string) json_encode([
            'email' => 'clara@example.test',
            'comments' => 'Bitte melden Sie sich.',
        ]))->assertStatus(200);

        $this->process();

        $this->assertSame('clara@example.test', UserReport::query()->sole()->email);
    }

    /**
     * Die Zusage aus der Aufgabe: eine Rückmeldung ist kein Ereignis und zählt
     * nicht gegen das Kontingent (O1).
     */
    public function test_feedback_does_not_count_toward_the_event_quota(): void
    {
        $this->assertFalse(IngestType::UserReport->countsTowardEventQuota());
        $this->assertFalse(IngestType::Feedback->countsTowardEventQuota());
        $this->assertTrue(IngestType::Event->countsTowardEventQuota());
    }

    /**
     * Die Kette hat den Schritt, ohne den nichts von alldem passiert — und das
     * Schwärzen steht davor.
     */
    public function test_the_pipeline_contains_the_step(): void
    {
        $steps = config('ingest.processing.steps');

        $this->assertContains(RecordUserReport::class, $steps);
        $this->assertLessThan(
            array_search(RecordUserReport::class, $steps, true),
            array_search(DecodePayload::class, $steps, true),
        );
        $this->assertContains(ScrubEvent::class, $steps);
    }

    private function event(int $projectId, ?string $eventId = null): Event
    {
        $issue = Issue::factory()->create([
            'project_id' => $projectId,
            'first_seen' => Carbon::now()->subHour(),
            'last_seen' => Carbon::now(),
        ]);

        $group = EventGroup::factory()->create([
            'project_id' => $projectId,
            'issue_id' => $issue->id,
        ]);

        return Event::factory()->create([
            'project_id' => $projectId,
            'event_group_id' => $group->id,
            'event_id' => $eventId ?? IngestPayload::freshEventId(),
        ]);
    }
}
