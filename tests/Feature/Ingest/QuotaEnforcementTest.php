<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Enums\NotificationEventType;
use App\Enums\OrganizationRole;
use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Jobs\DeliverPersonalNotification;
use App\Jobs\WarnAboutQuota;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\Quota;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Support\Ingest\Quotas\QuotaCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Kontingente und Ratenbegrenzung der Datenaufnahme (O1).
 *
 * Drei Zusagen stehen hier auf dem Prüfstand, und die dritte ist die, die im
 * Betrieb zählt: die Grenze greift (429 mit `Retry-After`), das Abgewiesene
 * wird nach Grund gezählt — und ein aufgebrauchtes Kontingent der einen
 * Datenart nimmt die andere in derselben Anfrage **nicht** mit.
 */
class QuotaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    /**
     * @return TestResponse<Response>
     */
    private function sendEvent(ProjectKey $key): TestResponse
    {
        return $this->call(
            'POST',
            "/api/{$key->project_id}/store/",
            server: $this->transformHeadersToServerVars([
                'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_key={$key->public_key}",
                'Content-Type' => 'application/json',
            ]),
            content: (string) json_encode(['message' => 'Etwas ist kaputt.']),
        );
    }

    /**
     * @return TestResponse<Response>
     */
    private function sendEnvelope(ProjectKey $key, string $body): TestResponse
    {
        return $this->call(
            'POST',
            "/api/{$key->project_id}/envelope/",
            server: $this->transformHeadersToServerVars([
                'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_key={$key->public_key}",
                'Content-Type' => 'application/x-sentry-envelope',
            ]),
            content: $body,
        );
    }

    /**
     * Der Fall aus der Aufgabenbeschreibung: zehn je Minute erlaubt, dreißig
     * geschickt — ab dem elften kommt eine 429, und die Statistik weist die
     * verworfenen als Rate-Limit aus.
     */
    public function test_a_key_rate_limit_answers_with_429_and_counts_the_discards(): void
    {
        // Eingefroren, weil die Zählung am Minutenfenster hängt: ein
        // Wechsel mitten im Durchlauf setzte sie zurück.
        $this->freezeTime();

        $key = $this->key();
        $key->update(['rate_limit_per_minute' => 10]);

        for ($i = 0; $i < 10; $i++) {
            $this->sendEvent($key)->assertStatus(200);
        }

        $rejected = $this->sendEvent($key);

        $rejected->assertStatus(429);
        $this->assertNotNull($rejected->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $rejected->headers->get('Retry-After'));

        // Abgelegt wurde nur, was durchkam.
        $this->assertSame(10, IngestPayload::query()->count());

        $discard = IngestDiscard::query()
            ->where('reason', DiscardReason::RateLimited->value)
            ->sole();

        $this->assertSame(DiscardOrigin::Server, $discard->origin);
        $this->assertSame($key->id, $discard->project_key_id);
        $this->assertSame(1, $discard->quantity);
    }

    /**
     * Was das Kontingent abgewiesen hat, wird nicht dagegen gezählt — sonst
     * bliebe ein Projekt, das einmal darüber war, bis zum Monatsersten stumm,
     * auch wenn die Grenze inzwischen angehoben wurde.
     */
    public function test_rejected_events_do_not_consume_the_quota(): void
    {
        // Eingefroren, weil die Zählung am Minutenfenster hängt: ein
        // Wechsel mitten im Durchlauf setzte sie zurück.
        $this->freezeTime();

        $key = $this->key();
        $key->update(['rate_limit_per_minute' => 1]);

        $this->sendEvent($key)->assertStatus(200);
        $this->sendEvent($key)->assertStatus(429);
        $this->sendEvent($key)->assertStatus(429);

        $this->assertSame(
            1,
            (new QuotaCounter)->minuteUsage(QuotaScope::Key, $key->id, null),
        );
    }

    /**
     * Das Monatskontingent einer Datenart: aufgebraucht heißt 429 bis zum
     * Monatsersten — und die Wartezeit sagt genau das.
     */
    public function test_a_monthly_quota_rejects_with_a_long_retry_after(): void
    {
        $key = $this->key();

        Quota::set(QuotaScope::Project, $key->project_id, QuotaCategory::Errors, 2, null);

        $this->sendEvent($key)->assertStatus(200);
        $this->sendEvent($key)->assertStatus(200);

        $rejected = $this->sendEvent($key);

        $rejected->assertStatus(429);
        // Deutlich mehr als eine Minute: die Grenze hält bis zum Monatsersten.
        $this->assertGreaterThan(60, (int) $rejected->headers->get('Retry-After'));

        $discard = IngestDiscard::query()
            ->where('reason', DiscardReason::QuotaExceeded->value)
            ->sole();

        $this->assertSame(QuotaCategory::Errors->value, $discard->category);
    }

    /**
     * Die Zusage, die den Unterschied macht: ein aufgebrauchtes
     * Transaktions-Kontingent hält die Fehlermeldung im selben Envelope nicht
     * auf.
     */
    public function test_an_exhausted_transaction_quota_does_not_block_errors(): void
    {
        $key = $this->key();

        Quota::set(QuotaScope::Project, $key->project_id, QuotaCategory::Transactions, 1, null);

        $envelope = implode("\n", [
            '{}',
            '{"type":"transaction"}',
            '{"type":"transaction","transaction":"GET /erste"}',
            '{"type":"transaction"}',
            '{"type":"transaction","transaction":"GET /zweite"}',
            '{"type":"event"}',
            '{"message":"Etwas ist kaputt."}',
        ])."\n";

        $this->sendEnvelope($key, $envelope)->assertStatus(200);

        $stored = IngestPayload::query()->pluck('type')->all();

        // Die erste Transaktion passt ins Kontingent, die zweite nicht — die
        // Fehlermeldung kommt trotzdem an.
        $this->assertSame(2, count($stored));
        $this->assertContains(IngestType::Event, $stored);

        $discard = IngestDiscard::query()
            ->where('reason', DiscardReason::QuotaExceeded->value)
            ->sole();

        $this->assertSame(QuotaCategory::Transactions->value, $discard->category);
    }

    /**
     * Die Grenze der Organisation steht über der des Projekts: ein Projekt ohne
     * eigenes Kontingent wird trotzdem abgewiesen, wenn die Organisation am
     * Ende ist.
     */
    public function test_the_organization_limit_applies_above_the_project(): void
    {
        $key = $this->key();
        $organizationId = Project::query()->whereKey($key->project_id)->value('organization_id');

        Quota::set(QuotaScope::Organization, (int) $organizationId, QuotaCategory::Errors, 1, null);

        $this->sendEvent($key)->assertStatus(200);
        $this->sendEvent($key)->assertStatus(429);
    }

    /**
     * Kein Kontingent heißt unbegrenzt — die Vorgabe darf niemanden bremsen.
     */
    public function test_without_a_quota_nothing_is_limited(): void
    {
        $key = $this->key();

        for ($i = 0; $i < 25; $i++) {
            $this->sendEvent($key)->assertStatus(200);
        }

        $this->assertSame(25, IngestPayload::query()->count());
        $this->assertSame(0, IngestDiscard::query()->count());
    }

    /**
     * Bei 80 % geht eine Warnung hinaus — genau einmal, auch wenn danach
     * weitere Meldungen über der Schwelle ankommen.
     */
    public function test_the_eighty_percent_warning_goes_out_once(): void
    {
        Queue::fake();

        $key = $this->key();

        Quota::set(QuotaScope::Project, $key->project_id, QuotaCategory::Errors, 10, null);

        for ($i = 0; $i < 8; $i++) {
            $this->sendEvent($key)->assertStatus(200);
        }

        Queue::assertPushed(WarnAboutQuota::class, 1);

        $this->sendEvent($key)->assertStatus(200);

        Queue::assertPushed(WarnAboutQuota::class, 1);

        $quota = Quota::query()->sole();

        $this->assertSame(80, $quota->warned_percent);
        $this->assertSame(now()->format('Y-m'), $quota->warned_period);
    }

    /**
     * Und bei 100 % eine zweite — sonst erführe die Verwaltung erst aus den
     * fehlenden Daten, dass nichts mehr ankommt.
     */
    public function test_the_hundred_percent_warning_follows_the_first(): void
    {
        Queue::fake();

        $key = $this->key();

        Quota::set(QuotaScope::Project, $key->project_id, QuotaCategory::Errors, 5, null);

        for ($i = 0; $i < 5; $i++) {
            $this->sendEvent($key)->assertStatus(200);
        }

        Queue::assertPushed(WarnAboutQuota::class, 2);

        $this->assertSame(100, Quota::query()->sole()->warned_percent);
    }

    /**
     * Die Warnung erreicht die Verwaltung der Organisation — und nur sie: wer
     * kein Kontingent ändern darf, bekommt eine Nachricht über eine Rechnung,
     * mit der er nichts anfangen kann.
     *
     * Geprüft wird am eingereihten Zustellauftrag und nicht an einem Doppel des
     * Verteilers: der Weg über die persönlichen Einstellungen ist Teil der
     * Zusage, und ein Doppel würde ihn gerade überspringen.
     */
    public function test_the_warning_reaches_the_administrators(): void
    {
        Queue::fake();

        $project = Project::factory()->create();
        /** @var Organization $organization */
        $organization = $project->organization;

        $admin = User::factory()->create();
        $member = User::factory()->create();

        $organization->setRole($admin, OrganizationRole::Admin);
        $organization->setRole($member, OrganizationRole::Member);

        $quota = Quota::set(QuotaScope::Project, $project->id, QuotaCategory::Errors, 100, null);
        $this->assertNotNull($quota);

        (new WarnAboutQuota($quota->id, 80, 80, 100))->handle(app(NotificationDispatcher::class));

        Queue::assertPushed(
            DeliverPersonalNotification::class,
            fn (DeliverPersonalNotification $job): bool => $job->user->id === $admin->id
                && $job->event === NotificationEventType::QuotaWarning
                && str_contains($job->message->title, '80'),
        );

        Queue::assertNotPushed(
            DeliverPersonalNotification::class,
            fn (DeliverPersonalNotification $job): bool => $job->user->id === $member->id,
        );
    }

    /**
     * Eine angehobene Grenze macht die Warnungen dieses Monats gegenstandslos:
     * wer von 10 auf 1.000 geht, will bei 800 wieder eine Nachricht.
     */
    public function test_raising_the_quota_clears_the_warning_mark(): void
    {
        $key = $this->key();

        Quota::set(QuotaScope::Project, $key->project_id, QuotaCategory::Errors, 10, null);
        Quota::claimWarning(Quota::query()->sole()->id, now()->format('Y-m'), 80);

        Quota::set(QuotaScope::Project, $key->project_id, QuotaCategory::Errors, 1_000, null);

        $quota = Quota::query()->sole();

        $this->assertNull($quota->warned_percent);
        $this->assertNull($quota->warned_period);
    }

    /**
     * Die grobe Bremse vor der Anmeldung: sie greift auch für Anfragen ohne
     * gültigen Schlüssel — sonst wäre das Durchprobieren unbegrenzt.
     */
    public function test_unauthenticated_attempts_are_throttled_before_the_key_is_resolved(): void
    {
        $this->freezeTime();

        config(['ingest.quotas.requests_per_minute' => 3]);

        $project = Project::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->call(
                'POST',
                "/api/{$project->id}/store/",
                server: $this->transformHeadersToServerVars([
                    'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_key=00000000000000000000000000000000',
                    'Content-Type' => 'application/json',
                ]),
                content: '{}',
            )->assertStatus(401);
        }

        $this->call(
            'POST',
            "/api/{$project->id}/store/",
            server: $this->transformHeadersToServerVars([
                'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_key=00000000000000000000000000000000',
                'Content-Type' => 'application/json',
            ]),
            content: '{}',
        )->assertStatus(429);
    }
}
