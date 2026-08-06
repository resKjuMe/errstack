<?php

namespace Tests\Feature\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\OrganizationRole;
use App\Enums\QueueName;
use App\Jobs\DeliverNotification;
use App\Mail\NotificationMail;
use App\Models\NotificationChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ChannelRegistry;
use App\Notifications\DeliveryResult;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_test_message_is_queued_and_not_sent_in_the_request(): void
    {
        Queue::fake();
        Http::preventStrayRequests();

        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $channel = NotificationChannel::factory()->for($organization)->slack()->create();

        $this->actingAs($admin)
            ->post("/benachrichtigungen/{$channel->id}/test")
            ->assertSessionHasNoErrors();

        // Kein einziger Aufruf nach außen im Web-Request — genau das ist die
        // Zusage: die Zustellung läuft ausschließlich in der Warteschlange.
        Queue::assertPushedOn(QueueName::Notifications->value, DeliverNotification::class);

        $delivery = NotificationDelivery::query()->firstOrFail();

        $this->assertTrue($delivery->is_test);
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertSame(0, $delivery->attempts);
    }

    public function test_members_may_not_send_a_test_message(): void
    {
        Queue::fake();

        $member = User::factory()->create();
        $organization = Organization::factory()->withMember($member, OrganizationRole::Member)->create();
        $channel = NotificationChannel::factory()->for($organization)->create();

        $this->actingAs($member)
            ->post("/benachrichtigungen/{$channel->id}/test")
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_the_job_delivers_and_writes_the_result_into_the_log(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $channel = NotificationChannel::factory()
            ->for(Organization::factory())
            ->slack()
            ->create();

        $delivery = $this->deliver($channel);

        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->response_code);
        $this->assertNull($delivery->error);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_a_rejected_delivery_is_logged_and_tried_again(): void
    {
        Http::fake(['*' => Http::response('channel_not_found', 404)]);

        $channel = NotificationChannel::factory()
            ->for(Organization::factory())
            ->slack()
            ->create();

        $delivery = $this->deliver($channel, expectFailure: true);

        // Noch nicht `Failed`: die Warteschlange versucht es erneut. Der Grund
        // steht trotzdem schon im Protokoll.
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(404, $delivery->response_code);
        $this->assertStringContainsString('channel_not_found', (string) $delivery->error);
    }

    public function test_after_the_last_attempt_the_delivery_counts_as_failed(): void
    {
        $channel = NotificationChannel::factory()->for(Organization::factory())->slack()->create();
        $delivery = $this->pending($channel);

        (new DeliverNotification($delivery))->failed(new RuntimeException('Ziel dauerhaft weg.'));

        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame('Ziel dauerhaft weg.', $delivery->error);
    }

    public function test_a_switched_off_channel_does_not_deliver(): void
    {
        Http::preventStrayRequests();

        $channel = NotificationChannel::factory()
            ->for(Organization::factory())
            ->slack()
            ->inactive()
            ->create();

        $delivery = $this->deliver($channel);

        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(0, $delivery->attempts);
    }

    public function test_a_failed_delivery_can_be_repeated_by_hand(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $channel = NotificationChannel::factory()->for($organization)->slack()->create();

        $delivery = $this->pending($channel);
        $delivery->markFailed('Ziel war weg.');

        $this->actingAs($admin)
            ->post("/zustellungen/{$delivery->id}/wiederholen")
            ->assertSessionHasNoErrors();

        Queue::assertPushed(DeliverNotification::class);

        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertNull($delivery->error);
    }

    public function test_a_delivered_message_is_not_repeated(): void
    {
        Queue::fake();

        $admin = User::factory()->create();
        $organization = Organization::factory()->withMember($admin, OrganizationRole::Admin)->create();
        $channel = NotificationChannel::factory()->for($organization)->slack()->create();

        $delivery = $this->pending($channel);
        $delivery->recordAttempt(DeliveryResult::success(200));

        $this->actingAs($admin)
            ->post("/zustellungen/{$delivery->id}/wiederholen")
            ->assertSessionHasNoErrors();

        Queue::assertNothingPushed();
    }

    public function test_the_dispatcher_reaches_every_active_channel_of_the_organization(): void
    {
        Queue::fake();

        $organization = Organization::factory()->create();
        NotificationChannel::factory()->for($organization)->slack()->create();
        NotificationChannel::factory()->for($organization)->discord()->create();
        NotificationChannel::factory()->for($organization)->teams()->inactive()->create();
        // Fremde Organisation: nichts davon geht sie an.
        NotificationChannel::factory()->for(Organization::factory())->slack()->create();

        $deliveries = app(NotificationDispatcher::class)->send(
            $organization,
            NotificationMessage::test($organization->name),
        );

        $this->assertCount(2, $deliveries);
        Queue::assertPushed(DeliverNotification::class, 2);
    }

    public function test_the_mail_channel_writes_to_every_recipient(): void
    {
        Mail::fake();

        $channel = NotificationChannel::factory()
            ->for(Organization::factory())
            ->mail('team@example.com', 'bereitschaft@example.com')
            ->create();

        $delivery = $this->deliver($channel);

        $this->assertSame(DeliveryStatus::Sent, $delivery->status);

        Mail::assertSent(
            NotificationMail::class,
            fn (NotificationMail $mail): bool => $mail->hasTo('team@example.com')
                && $mail->hasTo('bereitschaft@example.com'),
        );
    }

    public function test_teams_gets_a_card_with_a_summary(): void
    {
        Http::fake(['*' => Http::response('1', 200)]);

        $channel = NotificationChannel::factory()->for(Organization::factory())->teams()->create();

        $this->deliver($channel);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return $payload['@type'] === 'MessageCard'
                && $payload['summary'] === 'Testnachricht aus Errstack';
        });
    }

    public function test_discord_gets_the_colour_as_a_number(): void
    {
        Http::fake(['*' => Http::response('', 204)]);

        $channel = NotificationChannel::factory()->for(Organization::factory())->discord()->create();

        $this->deliver($channel);

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            return is_int($payload['embeds'][0]['color'])
                && $payload['embeds'][0]['title'] === 'Testnachricht aus Errstack';
        });
    }

    /**
     * Legt einen Protokolleintrag an, ohne ihn einzureihen — die Tests führen
     * den Job selbst aus.
     */
    private function pending(NotificationChannel $channel): NotificationDelivery
    {
        Queue::fake();

        return app(NotificationDispatcher::class)->sendTo(
            $channel,
            NotificationMessage::test($channel->organization->name),
            isTest: true,
        );
    }

    /**
     * Führt den Zustell-Job aus, wie es der Worker täte.
     */
    private function deliver(NotificationChannel $channel, bool $expectFailure = false): NotificationDelivery
    {
        $delivery = $this->pending($channel);

        try {
            (new DeliverNotification($delivery))->handle(app(ChannelRegistry::class));

            $this->assertFalse($expectFailure, 'Die Zustellung sollte fehlschlagen, tat es aber nicht.');
        } catch (RuntimeException $exception) {
            $this->assertTrue($expectFailure, "Unerwarteter Fehlschlag: {$exception->getMessage()}");
        }

        return $delivery->refresh();
    }
}
