<?php

namespace Tests\Feature\Notifications;

use App\Jobs\DeliverNotification;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Notifications\ChannelRegistry;
use App\Notifications\Drivers\WebhookDriver;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Der Webhook ist der einzige Kanal, dessen Gegenstelle wir nicht kennen —
 * deshalb prüft dieser Test genau das, was in docs/webhooks.md zugesagt wird.
 */
class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_delivery_is_signed_the_way_the_documentation_says(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();

        $organization = Organization::factory()->create(['name' => 'Acme']);
        $channel = NotificationChannel::factory()->for($organization)->create([
            'config' => ['url' => 'https://example.com/errstack', 'secret' => 'streng-geheim-geheim'],
        ]);

        $delivery = app(NotificationDispatcher::class)->sendTo(
            $channel,
            NotificationMessage::test($organization->name),
            isTest: true,
        );

        (new DeliverNotification($delivery))->handle(app(ChannelRegistry::class));

        Http::assertSent(function (Request $request) use ($delivery): bool {
            $timestamp = (int) $request->header('X-Errstack-Timestamp')[0];

            $expected = WebhookDriver::signature(
                'streng-geheim-geheim',
                $timestamp,
                $request->body(),
            );

            $this->assertSame($expected, $request->header('X-Errstack-Signature')[0]);
            $this->assertSame('notification', $request->header('X-Errstack-Event')[0]);
            $this->assertSame((string) $delivery->id, $request->header('X-Errstack-Delivery')[0]);
            $this->assertStringStartsWith('v1=', $request->header('X-Errstack-Signature')[0]);

            return true;
        });
    }

    public function test_the_signature_covers_the_body_that_is_actually_sent(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();

        $channel = NotificationChannel::factory()->for(Organization::factory())->create([
            'config' => ['url' => 'https://example.com/errstack', 'secret' => 'streng-geheim-geheim'],
        ]);

        $delivery = app(NotificationDispatcher::class)->sendTo(
            $channel,
            new NotificationMessage(
                title: 'TypeError in Kasse',
                body: 'Cannot read properties of undefined',
                url: 'https://errstack.example.com/issues/1234',
                context: ['Projekt' => 'Kasse'],
                reference: 'KASSE-1234',
                occurredAt: now(),
            ),
        );

        (new DeliverNotification($delivery))->handle(app(ChannelRegistry::class));

        Http::assertSent(function (Request $request): bool {
            $payload = json_decode($request->body(), true);

            // Ein Empfänger rechnet über den rohen Rumpf. Deshalb muss dieser
            // Rumpf für sich stimmen — und die Unterschrift auf ihn passen.
            $this->assertSame('notification', $payload['event']);
            $this->assertSame('TypeError in Kasse', $payload['message']['title']);
            $this->assertSame('KASSE-1234', $payload['message']['reference']);
            $this->assertFalse($payload['delivery']['test']);
            $this->assertSame('Kasse', $payload['message']['context']['Projekt']);

            $signature = WebhookDriver::signature(
                'streng-geheim-geheim',
                (int) $request->header('X-Errstack-Timestamp')[0],
                $request->body(),
            );

            $this->assertSame($signature, $request->header('X-Errstack-Signature')[0]);

            return true;
        });
    }

    public function test_a_repeated_delivery_keeps_its_identifier(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Queue::fake();

        $channel = NotificationChannel::factory()->for(Organization::factory())->create();

        $delivery = app(NotificationDispatcher::class)->sendTo(
            $channel,
            NotificationMessage::test('Acme'),
            isTest: true,
        );

        $seen = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                (new DeliverNotification($delivery))->handle(app(ChannelRegistry::class));
            } catch (RuntimeException) {
                // Erwartet: das Ziel antwortet mit 500 — der Worker versucht es
                // später erneut, mit derselben Kennung.
            }
        }

        Http::assertSentCount(2);

        Http::assertSent(function (Request $request) use (&$seen): bool {
            $seen[] = $request->header('X-Errstack-Delivery')[0];

            return true;
        });

        $this->assertSame([(string) $delivery->id, (string) $delivery->id], $seen);
    }
}
