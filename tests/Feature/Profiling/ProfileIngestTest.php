<?php

namespace Tests\Feature\Profiling;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\Transaction;
use App\Support\Profiling\ProfileFrame;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Performance\TransactionPayload;
use Tests\Support\Profiling\ProfilePayload;
use Tests\TestCase;

/**
 * Die Aufnahme von Sample-Profilen, vom angenommenen Envelope-Element bis zur
 * abgelegten Zeile.
 *
 * Geprüft wird, was die Anzeige voraussetzt: dass ein Profil an seiner
 * Transaktion landet, dass der Aufrufbaum die Verschachtelung behält, dass
 * Selbst- und Gesamtzeit auseinandergehalten werden — und dass ein Profil ohne
 * Transaktion verworfen und gezählt wird, statt als Messung ohne Bezug liegen
 * zu bleiben.
 */
class ProfileIngestTest extends TestCase
{
    use RefreshDatabase;

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    /**
     * Legt eine Transaktion ab und gibt ihre Ereignis-Nummer zurück — die
     * Nummer, über die ein Profil sie später findet.
     */
    private function transaction(ProjectKey $key): Transaction
    {
        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->body(TransactionPayload::make(), IngestType::Transaction)
            ->create();

        ProcessIngestPayload::dispatch($payload);

        return Transaction::query()->sole();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function ingestProfile(array $body, ProjectKey $key): IngestPayload
    {
        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->body($body, IngestType::Profile)
            ->create();

        ProcessIngestPayload::dispatch($payload);

        return $payload->refresh();
    }

    public function test_a_profile_is_stored_at_its_transaction(): void
    {
        $key = $this->key();
        $transaction = $this->transaction($key);

        $payload = $this->ingestProfile(
            ProfilePayload::make($transaction->event_id, [
                ['handle', 'query'],
                ['handle', 'query'],
                ['handle', 'render'],
            ]),
            $key,
        );

        $profile = Profile::query()->sole();

        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
        $this->assertSame($transaction->id, $profile->transaction_id);
        $this->assertSame($transaction->project_id, $profile->project_id);
        $this->assertSame($payload->id, $profile->ingest_payload_id);
        $this->assertSame($transaction->trace_id, $profile->trace_id);
        $this->assertSame($transaction->name, $profile->transaction_name);
        $this->assertSame('production', $profile->environment);
        $this->assertSame('errstack@1.2.3', $profile->release);
        $this->assertSame('1', $profile->thread_id);
        $this->assertSame(3, $profile->sample_count);
    }

    public function test_the_call_tree_keeps_the_nesting_and_separates_self_from_total(): void
    {
        $key = $this->key();
        $transaction = $this->transaction($key);

        $this->ingestProfile(
            ProfilePayload::make($transaction->event_id, [
                ['handle', 'query'],
                ['handle', 'query'],
                ['handle', 'render'],
            ]),
            $key,
        );

        $tree = Profile::query()->sole()->callTree();
        $functions = collect($tree->functions())->keyBy(
            fn ($function): string => $function->frame->function,
        );

        // Drei Stichproben à 10 ms: `handle` hat nichts selbst verbraucht, aber
        // alles unter sich; `query` zwei Drittel, `render` ein Drittel.
        $interval = ProfilePayload::INTERVAL_NS;

        $this->assertSame(3 * $interval, $tree->totalNs);
        $this->assertSame(0, $functions['handle']->selfNs);
        $this->assertSame(3 * $interval, $functions['handle']->totalNs);
        $this->assertSame(2 * $interval, $functions['query']->selfNs);
        $this->assertSame($interval, $functions['render']->selfNs);
    }

    public function test_a_profile_without_a_transaction_is_discarded_and_counted(): void
    {
        $key = $this->key();

        // Kein `transaction()` davor: das Profil verweist auf eine Nummer, die
        // es in dieser Anwendung nicht gibt.
        $payload = $this->ingestProfile(
            ProfilePayload::make(str_repeat('a', 32), [['handle']]),
            $key,
        );

        $this->assertSame(0, Profile::query()->count());
        $this->assertSame(ProcessingState::Dropped, $payload->processing_state);

        $discard = IngestDiscard::query()
            ->where('reason', DiscardReason::Orphaned->value)
            ->sole();

        $this->assertSame(IngestType::Profile->value, $discard->category);
        $this->assertSame(1, $discard->quantity);
    }

    public function test_only_the_active_thread_is_evaluated(): void
    {
        $key = $this->key();
        $transaction = $this->transaction($key);

        $body = ProfilePayload::make($transaction->event_id, [
            ['handle', 'query'],
            ['handle', 'query'],
        ]);

        // Eine dritte Stichprobe aus einem anderen Strang. Sie darf das Bild
        // nicht verwässern: der übrige Strang wartet üblicherweise, und seine
        // Wartezeit im selben Flamegraph rechnete den Anteil des rechnenden
        // Codes klein.
        $body['profile']['samples'][] = [
            'stack_id' => 0,
            'thread_id' => '2',
            'elapsed_since_start_ns' => 5_000_000,
        ];

        $this->ingestProfile($body, $key);

        $this->assertSame(2, Profile::query()->sole()->sample_count);
    }

    public function test_a_profile_in_another_format_is_discarded(): void
    {
        $key = $this->key();
        $transaction = $this->transaction($key);

        $payload = $this->ingestProfile(
            ProfilePayload::make($transaction->event_id, [['handle']], ['version' => '2']),
            $key,
        );

        $this->assertSame(0, Profile::query()->count());
        $this->assertSame(ProcessingState::Dropped, $payload->processing_state);
    }

    public function test_processing_the_same_payload_twice_updates_the_row(): void
    {
        $key = $this->key();
        $transaction = $this->transaction($key);
        $body = ProfilePayload::make($transaction->event_id, [['handle', 'query']]);

        $this->ingestProfile($body, $key);
        $first = Profile::query()->sole();

        // Derselbe Rumpf ein zweites Mal — der Fall nach einem gescheiterten
        // Job oder einer erneuten Auswertung der Rohdaten.
        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->body($body, IngestType::Profile)
            ->create();

        ProcessIngestPayload::dispatch($payload);

        $this->assertSame(1, Profile::query()->count());
        $this->assertSame($first->id, Profile::query()->sole()->id);
    }

    public function test_a_frame_without_a_name_keeps_its_place_in_the_stack(): void
    {
        $key = $this->key();
        $transaction = $this->transaction($key);

        $body = ProfilePayload::make($transaction->event_id, [['handle', 'query']]);
        // Der äußere Rahmen verliert seinen Namen. Sein Platz muss bleiben:
        // fiele er heraus, wäre `query` plötzlich die Wurzel des Baums.
        unset($body['profile']['frames'][0]['function']);

        $this->ingestProfile($body, $key);

        $tree = Profile::query()->sole()->callTree();
        $root = $tree->roots[array_key_first($tree->roots)];

        $this->assertSame(ProfileFrame::UNKNOWN, $tree->frames[$root->frame]->function);
        $this->assertCount(1, $root->children);
    }
}
