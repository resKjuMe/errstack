<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardReason;
use App\Enums\IssueActivityType;
use App\Enums\IssueIgnoreMode;
use App\Enums\IssueStatus;
use App\Jobs\ProcessIngestPayload;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueDiscard;
use App\Models\Project;
use App\Support\Issues\IssueActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Die beiden Aktionen aus S6, die in die Aufnahme hineinwirken: die
 * Stummschaltung mit Bedingung und das künftige Verwerfen.
 *
 * Beide lassen sich nicht am Formular prüfen. Ob eine Stummschaltung „bis 100
 * weitere Ereignisse" wirklich bei 100 endet und ob ein verworfener Fehler
 * tatsächlich nicht mehr entsteht, entscheidet sich in der Kette — und genau
 * dort ist der Fehler teuer: eine Bedingung, die zu früh auslöst, weckt nachts
 * jemanden; eine, die nie auslöst, verschweigt einen Ausfall.
 */
class IssueMutingAndDiscardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<mixed>  $data
     */
    private function ingest(Project $project, array $data, ?Carbon $at = null): Event
    {
        $eventId = IngestPayload::freshEventId();

        $payload = IngestPayload::factory()->create([
            'project_id' => $project->id,
            'event_id' => $eventId,
            'payload' => (string) json_encode($data + [
                'event_id' => $eventId,
                'timestamp' => ($at ?? now())->toIso8601String(),
                'platform' => 'php',
            ]),
        ]);

        ProcessIngestPayload::dispatch($payload);

        return Event::query()->where('ingest_payload_id', $payload->id)->sole();
    }

    /**
     * @return array<mixed>
     */
    private function crash(?string $userId = null): array
    {
        $data = [
            'exception' => ['values' => [[
                'type' => 'RuntimeException',
                'value' => 'Rechnung konnte nicht erstellt werden',
                'stacktrace' => ['frames' => [[
                    'filename' => 'app/Http/Controllers/InvoiceController.php',
                    'function' => 'store',
                    'lineno' => 42,
                    'in_app' => true,
                ]]],
            ]]],
        ];

        return $userId === null ? $data : $data + ['user' => ['id' => $userId]];
    }

    /**
     * Der Fall aus der Testanleitung: „bis 100 weitere Ereignisse"
     * stummgeschaltet, danach fünf Ereignisse — der Eintrag bleibt still.
     */
    public function test_five_of_a_hundred_events_do_not_end_the_muting(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, $this->crash());

        $issue = Issue::query()->sole();

        (new IssueActions)->ignore(
            Issue::query()->whereKey($issue->id),
            IssueIgnoreMode::UntilCount,
            count: 100,
        );

        for ($i = 0; $i < 5; $i++) {
            $this->ingest($project, $this->crash());
        }

        $issue->refresh();

        $this->assertSame(IssueStatus::Ignored, $issue->status);
        // Die Zähler laufen dabei weiter — ein stummgeschalteter Eintrag, der
        // nicht mehr zählt, wäre nach vier Wochen nicht mehr zu beurteilen.
        $this->assertSame(6, $issue->times_seen);
    }

    public function test_the_muting_ends_when_the_threshold_is_reached(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, $this->crash());

        $issue = Issue::query()->sole();

        (new IssueActions)->ignore(
            Issue::query()->whereKey($issue->id),
            IssueIgnoreMode::UntilCount,
            count: 3,
        );

        $this->ingest($project, $this->crash());
        $this->ingest($project, $this->crash());

        $this->assertSame(IssueStatus::Ignored, $issue->refresh()->status);

        $this->ingest($project, $this->crash());

        $issue->refresh();

        $this->assertSame(IssueStatus::Unresolved, $issue->status);
        // Die Bedingung ist verbraucht: sie stehen zu lassen hieße, dass sie
        // beim nächsten Stummschalten aus einer alten Aktion nachwirkt.
        $this->assertNull($issue->ignore_count);
        $this->assertNull($issue->ignored_at);

        $activity = IssueActivity::query()
            ->where('issue_id', $issue->id)
            ->where('type', IssueActivityType::IgnoreExpired)
            ->sole();

        // Beim Ablauf steht niemand daneben — der Vermerk trägt deshalb keinen
        // Namen und keine Kennung.
        $this->assertNull($activity->user_id);
        $this->assertNull($activity->actor_name);
    }

    public function test_the_user_threshold_counts_affected_users_and_not_events(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, $this->crash('kunde-1'));

        $issue = Issue::query()->sole();

        (new IssueActions)->ignore(
            Issue::query()->whereKey($issue->id),
            IssueIgnoreMode::UntilUsers,
            count: 2,
        );

        // Zehn Ereignisse desselben Nutzers: viel Lärm, ein Betroffener.
        for ($i = 0; $i < 10; $i++) {
            $this->ingest($project, $this->crash('kunde-1'));
        }

        $this->assertSame(IssueStatus::Ignored, $issue->refresh()->status);

        $this->ingest($project, $this->crash('kunde-2'));
        $this->assertSame(IssueStatus::Ignored, $issue->refresh()->status);

        $this->ingest($project, $this->crash('kunde-3'));
        $this->assertSame(IssueStatus::Unresolved, $issue->refresh()->status);
    }

    /**
     * „Löschen und künftig verwerfen": gleichartige Meldungen legen keinen neuen
     * Eintrag mehr an. Ohne diesen Vermerk wäre die Aktion wirkungslos — die
     * nächste Meldung rechnet denselben Fingerabdruck und begänne von vorn.
     */
    public function test_discarding_keeps_the_same_error_from_coming_back(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, $this->crash());

        $issue = Issue::query()->sole();
        $fingerprint = EventGroup::query()->where('issue_id', $issue->id)->sole()->fingerprint;

        (new IssueActions)->delete(Issue::query()->whereKey($issue->id), discard: true);

        $this->assertNull(Issue::query()->find($issue->id));
        $this->assertTrue(IssueDiscard::query()
            ->where('project_id', $project->id)
            ->where('fingerprint', $fingerprint)
            ->exists());

        $this->ingest($project, $this->crash());

        // Kein neuer Eintrag: das Verwerfen greift vor dem Anlegen, sonst
        // legte es genau das wieder an, was es verhindern soll.
        $this->assertSame(0, Issue::query()->count());

        // Die Gruppe von vorhin bleibt dagegen stehen — an ihr hängen die
        // Ereignisse, und ein gelöschter Eintrag ist die Aussage „will ich
        // nicht mehr sehen", nicht „das ist nie passiert" (siehe den
        // Fremdschlüssel event_groups.issue_id). Geprüft gehört deshalb, dass
        // keine ZWEITE entsteht und die alte ohne Eintrag dasteht.
        $this->assertSame(1, EventGroup::query()->count());
        $this->assertNull(EventGroup::query()->sole()->issue_id);

        // Und es wird gezählt — mit einem eigenen Grund, damit „warum kommt der
        // nicht mehr an?" beantwortbar bleibt.
        $this->assertTrue(IngestDiscard::query()
            ->where('reason', DiscardReason::Discarded->value)
            ->exists());
    }

    /**
     * Der Rückweg: das Verwerfen lässt sich aufheben, und danach entsteht der
     * Fehler wieder. Der gelöschte Eintrag kommt dabei nicht zurück — er ist
     * weg, und das ist der Teil der Aktion, der endgültig ist.
     */
    public function test_lifting_the_discard_lets_the_error_appear_again(): void
    {
        $project = Project::factory()->create();

        $this->ingest($project, $this->crash());

        $issue = Issue::query()->sole();
        $fingerprint = EventGroup::query()->where('issue_id', $issue->id)->sole()->fingerprint;

        $actions = new IssueActions;
        $actions->delete(Issue::query()->whereKey($issue->id), discard: true);
        $actions->undiscard([['project' => $project->id, 'fingerprint' => $fingerprint]]);

        $this->ingest($project, $this->crash());

        $fresh = Issue::query()->sole();

        $this->assertNotSame($issue->id, $fresh->id);
        $this->assertSame(1, $fresh->times_seen);
    }
}
