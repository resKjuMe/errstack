<?php

namespace Tests\Feature\Ingest;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Enums\ProcessingState;
use App\Jobs\ProcessIngestPayload;
use App\Models\IngestDiscard;
use App\Models\IngestPayload;
use App\Models\ProcessedEvent;
use App\Models\Project;
use App\Models\ProjectKey;
use App\Models\SamplingRule;
use App\Models\Transaction;
use App\Models\TransactionAggregate;
use App\Support\Ingest\Sampling\Sampler;
use App\Support\Ingest\Sampling\SampleTarget;
use App\Support\Ingest\Sampling\SamplingDecision;
use App\Support\Ingest\Sampling\WindowCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Performance\TransactionPayload;
use Tests\TestCase;

/**
 * Die Stichprobe der Antwortzeiten: welche Messungen behalten werden, und wie aus
 * den behaltenen wieder der tatsächliche Verkehr wird.
 *
 * Zwei Zusagen stehen hier auf dem Spiel, und die zweite ist die unauffälligere.
 * Die erste: es wird weniger gespeichert. Die zweite: die Auswertungen zeigen
 * trotzdem die richtigen Zahlen. Ohne die zweite ist die erste kein Gewinn,
 * sondern ein Fehler — einer, den niemand an den Daten sehen könnte, weil an den
 * gespeicherten Messungen nichts fehlt.
 *
 * Der Wurf ist in diesen Prüfungen festgelegt ({@see withDraw()}). Eine
 * Stichprobe, die man nur statistisch prüfen kann, prüft niemand — und ein Test,
 * der in einem von hundert Läufen rot wird, wird abgeschaltet statt gelesen.
 */
class TransactionSamplingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Die Mindestquote gilt je Zeitfenster, und das Fenster ist eine Minute.
        // Ohne feste Uhr könnte eine Prüfung, die hundert Meldungen einspeist,
        // zufällig eine Fenstergrenze überschreiten — dann wäre eine zweite
        // Messung garantiert behalten, und der Test wäre in wenigen Läufen von
        // hundert rot. Das ist genau die Sorte Test, die abgeschaltet wird.
        $this->freezeTime();
    }

    private function key(): ProjectKey
    {
        return Project::factory()->create()->keys()->firstOrFail();
    }

    /**
     * Legt den Wurf fest, mit dem die Stichprobe entscheidet.
     *
     * `0.0` behält alles, was die Quote überhaupt zulässt, `0.999` nichts außer
     * dem, was die Mindestquote sichert.
     */
    private function withDraw(float $draw): void
    {
        $this->app->instance(
            Sampler::class,
            new Sampler(new WindowCounter, static fn (): float => $draw),
        );
    }

    /**
     * Nimmt eine Transaktions-Meldung an und lässt sie verarbeiten — denselben
     * Weg, den eine Meldung aus dem Envelope-Endpunkt nimmt.
     *
     * @param  array<string, mixed>  $body
     */
    private function ingest(array $body, ProjectKey $key): IngestPayload
    {
        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->body($body, IngestType::Transaction)
            ->create();

        ProcessIngestPayload::dispatch($payload);

        return $payload->refresh();
    }

    /**
     * Die Entscheidung für einen Aufruf, ohne den Weg über die Warteschlange.
     *
     * @param  array<string, mixed>  $body
     */
    private function decide(Project $project, array $body): SamplingDecision
    {
        return $this->app->make(Sampler::class)->decide($project, SampleTarget::fromPayload($body));
    }

    public function test_without_a_rule_everything_is_kept(): void
    {
        $key = $this->key();

        // Der Wurf liegt so hoch, dass er jede Quote unter eins reißen würde.
        // Behalten wird die Messung trotzdem: ohne Regel wird gar nicht
        // gewürfelt.
        $this->withDraw(0.999);

        $payload = $this->ingest(TransactionPayload::make(), $key);

        $this->assertSame(ProcessingState::Processed, $payload->processing_state);
        $this->assertSame(1, Transaction::query()->count());

        $transaction = Transaction::query()->sole();

        $this->assertNull($transaction->client_sample_rate);
        $this->assertSame(1.0, $transaction->server_sample_rate);
        $this->assertSame(1.0, $transaction->sampleWeight());
    }

    public function test_a_rule_discards_what_falls_outside_the_sample(): void
    {
        $key = $this->key();

        SamplingRule::factory()->for($key->project)->withoutMinimum()->create([
            'transaction_name' => 'GET /health',
            'sample_rate' => 0.01,
        ]);

        $this->withDraw(0.5);

        $payload = $this->ingest(TransactionPayload::make(['transaction' => 'GET /health']), $key);

        $this->assertSame(ProcessingState::Dropped, $payload->processing_state);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, TransactionAggregate::query()->count());
    }

    /**
     * Die Zusage des Vertrags: eine ausgesiebte Messung ist als solche gezählt
     * und **nicht** als Fehler.
     */
    public function test_a_discarded_measurement_is_counted_as_sampled(): void
    {
        $key = $this->key();

        SamplingRule::factory()->for($key->project)->withoutMinimum()->create([
            'transaction_name' => 'GET /health',
            'sample_rate' => 0.01,
        ]);

        $this->withDraw(0.5);

        $this->ingest(TransactionPayload::make(['transaction' => 'GET /health']), $key);

        $discard = IngestDiscard::query()->sole();

        $this->assertSame(DiscardReason::Sampled->value, $discard->reason);
        $this->assertSame(IngestType::Transaction->value, $discard->category);
        $this->assertSame(1, $discard->quantity);
    }

    /**
     * Der eigentliche Prüffall der Aufgabe: von hundert Aufrufen bleibt fast
     * nichts stehen, und was steht, ist nachrechenbar.
     *
     * Der Wurf trifft die Quote nie — behalten wird ausschließlich die erste
     * Meldung, weil die Mindestquote sie sichert. Damit ist die Rechnung
     * nachprüfbar statt statistisch: eine garantierte Messung wiegt 1, die
     * übrigen 99 sind gezählt und nicht gespeichert.
     */
    public function test_a_low_rate_keeps_almost_nothing(): void
    {
        $key = $this->key();

        SamplingRule::factory()->for($key->project)->create([
            'transaction_name' => 'GET /health',
            'sample_rate' => 0.01,
            'minimum_per_window' => 1,
        ]);

        $this->withDraw(0.999);

        for ($i = 0; $i < 100; $i++) {
            $this->ingest(TransactionPayload::make(['transaction' => 'GET /health']), $key);
        }

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(99, (int) IngestDiscard::query()->sum('quantity'));

        $aggregate = TransactionAggregate::query()->sole();

        $this->assertSame(1, $aggregate->transaction_count);
        $this->assertSame(1.0, $aggregate->extrapolated_count);
    }

    /**
     * Die andere Hälfte derselben Rechnung: fällt der Wurf in die Quote, steht die
     * behaltene Messung für hundert Aufrufe. Das ist die Zahl, die die Übersicht
     * anzeigen soll — der Durchsatz bleibt realistisch, obwohl fast nichts
     * gespeichert wurde.
     */
    public function test_a_kept_measurement_stands_for_the_whole_sample(): void
    {
        $key = $this->key();

        SamplingRule::factory()->for($key->project)->withoutMinimum()->create([
            'transaction_name' => 'GET /health',
            'sample_rate' => 0.01,
        ]);

        $this->withDraw(0.0);

        $this->ingest(TransactionPayload::make(['transaction' => 'GET /health']), $key);

        $transaction = Transaction::query()->sole();

        $this->assertEqualsWithDelta(0.01, $transaction->server_sample_rate, 0.000001);
        $this->assertEqualsWithDelta(100.0, $transaction->sampleWeight(), 0.0001);

        $aggregate = TransactionAggregate::query()->sole();

        $this->assertSame(1, $aggregate->transaction_count);
        $this->assertEqualsWithDelta(100.0, $aggregate->extrapolated_count, 0.0001);
    }

    /**
     * Die Zusage an die seltenen Vorgänge: was höchstens `minimum_per_window` mal
     * je Fenster vorkommt, bleibt vollständig sichtbar — auch bei 1 % Quote.
     */
    public function test_rare_operations_keep_a_guaranteed_share(): void
    {
        $key = $this->key();

        SamplingRule::factory()->for($key->project)->create([
            'transaction_name' => 'nightly-import',
            'sample_rate' => 0.01,
            'minimum_per_window' => 2,
        ]);

        $this->withDraw(0.999);

        for ($i = 0; $i < 3; $i++) {
            $this->ingest(TransactionPayload::make(['transaction' => 'nightly-import']), $key);
        }

        // Zwei garantiert behalten, die dritte am Wurf gescheitert.
        $this->assertSame(2, Transaction::query()->count());
        $this->assertSame(1, (int) IngestDiscard::query()->sum('quantity'));

        // Garantiert behalten heißt Gewicht 1: die Messung steht für sich selbst
        // und nicht für hundert. Sonst wiese die Übersicht für einen nächtlichen
        // Import zweihundert Läufe aus.
        foreach (Transaction::query()->get() as $transaction) {
            $this->assertSame(1.0, $transaction->server_sample_rate);
            $this->assertSame(1.0, $transaction->sampleWeight());
        }

        $this->assertSame(2.0, TransactionAggregate::query()->sole()->extrapolated_count);
    }

    /**
     * Die Mindestquote gilt je Vorgang und nicht je Regel — sonst wäre die Zusage
     * für alle Namen zusammen gemeint, und der eine seltene Import bliebe genauso
     * verloren wie ohne sie.
     */
    public function test_the_guarantee_is_counted_per_operation(): void
    {
        $key = $this->key();

        SamplingRule::factory()->for($key->project)->create([
            // Ein Muster über beide Namen: dieselbe Regel, zwei Vorgänge.
            'transaction_name' => 'GET /*',
            'sample_rate' => 0.01,
            'minimum_per_window' => 1,
        ]);

        $this->withDraw(0.999);

        $this->ingest(TransactionPayload::make(['transaction' => 'GET /health']), $key);
        $this->ingest(TransactionPayload::make(['transaction' => 'GET /status']), $key);

        $this->assertSame(
            ['GET /health', 'GET /status'],
            Transaction::query()->orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * Die Quote des SDK zählt mit, auch ohne eigene Regel — sonst wäre eine
     * Anwendung mit `traces_sample_rate: 0.1` in jeder Übersicht um den Faktor
     * zehn zu leise.
     */
    public function test_the_rate_of_the_sdk_is_stored_and_extrapolated(): void
    {
        $key = $this->key();

        $this->ingest($this->withClientRate(TransactionPayload::make(), 0.1), $key);

        $transaction = Transaction::query()->sole();

        $this->assertEqualsWithDelta(0.1, $transaction->client_sample_rate, 0.000001);
        $this->assertEqualsWithDelta(10.0, $transaction->sampleWeight(), 0.0001);

        $aggregate = TransactionAggregate::query()->sole();

        $this->assertSame(1, $aggregate->transaction_count);
        $this->assertEqualsWithDelta(10.0, $aggregate->extrapolated_count, 0.0001);
    }

    /**
     * Beide Quoten wirken nacheinander: 10 % beim Absender und 10 % bei uns
     * ergeben 1 %.
     */
    public function test_both_rates_multiply(): void
    {
        $key = $this->key();

        SamplingRule::factory()->for($key->project)->withoutMinimum()->create([
            'transaction_name' => 'GET /health',
            'sample_rate' => 0.1,
        ]);

        $this->withDraw(0.0);

        $body = $this->withClientRate(TransactionPayload::make(['transaction' => 'GET /health']), 0.1);

        $this->ingest($body, $key);

        $transaction = Transaction::query()->sole();

        $this->assertEqualsWithDelta(0.1, $transaction->client_sample_rate, 0.000001);
        $this->assertEqualsWithDelta(0.1, $transaction->server_sample_rate, 0.000001);
        $this->assertEqualsWithDelta(100.0, $transaction->sampleWeight(), 0.0001);
    }

    /**
     * Die Zusage der Aufgabe: Fehler sind von den Transaktionsregeln nicht
     * betroffen. Ein Absturz ist ein Einzelfall, und ein Einzelfall lässt sich
     * nicht hochrechnen.
     */
    public function test_errors_are_never_sampled(): void
    {
        $key = $this->key();

        // Eine Regel, die auf alles zutrifft und fast nichts behält — und deren
        // Bedingung damit auch auf den Transaktionsnamen dieses Fehlers passen
        // würde.
        SamplingRule::factory()->for($key->project)->catchAll()->create(['sample_rate' => 0.01]);

        $this->withDraw(0.999);

        $payload = IngestPayload::factory()
            ->viaKey($key)
            ->body([
                'event_id' => IngestPayload::freshEventId(),
                'platform' => 'php',
                'level' => 'error',
                'environment' => 'production',
                'transaction' => 'GET /health',
                'message' => 'Kaputt',
            ])
            ->create();

        ProcessIngestPayload::dispatch($payload);

        $this->assertSame(ProcessingState::Processed, $payload->refresh()->processing_state);
        $this->assertSame(0, IngestDiscard::query()->count());
    }

    /**
     * Die erste zutreffende Regel gewinnt, und „zutreffend" heißt: alle
     * gesetzten Bedingungen. Eine abgeschaltete Regel greift nicht.
     */
    public function test_the_first_matching_rule_wins(): void
    {
        $project = $this->key()->project;

        SamplingRule::factory()->for($project)->at(0)->inactive()->create([
            'name' => 'abgeschaltet',
            'transaction_name' => null,
            'sample_rate' => 0.5,
        ]);
        SamplingRule::factory()->for($project)->at(1)->create([
            'name' => 'andere Umgebung',
            'transaction_name' => 'GET /health',
            'environment' => 'staging',
            'sample_rate' => 0.25,
        ]);
        // Ohne Mindestquote: sonst wäre die erste Messung des Fensters garantiert
        // behalten, und die geprüfte Quote wäre die der Garantie (1) statt die der
        // Regel.
        SamplingRule::factory()->for($project)->at(2)->withoutMinimum()->create([
            'name' => 'greift',
            'transaction_name' => 'GET /*',
            'environment' => 'production',
            'sample_rate' => 0.2,
        ]);
        SamplingRule::factory()->for($project)->at(3)->catchAll()->create([
            'name' => 'käme auch in Frage',
            'sample_rate' => 0.75,
        ]);

        $this->withDraw(0.0);

        $decision = $this->decide($project, TransactionPayload::make(['transaction' => 'GET /health']));

        $this->assertSame('greift', $decision->rule?->name);
        $this->assertEqualsWithDelta(0.2, $decision->serverRate, 0.000001);
    }

    /**
     * Eine gesetzte Bedingung kann an einem Aufruf ohne diese Angabe nicht
     * zutreffen — eine Regel für „Version 2" gilt nicht für einen Aufruf ohne
     * Version.
     */
    public function test_a_condition_needs_the_value_it_asks_for(): void
    {
        $project = $this->key()->project;

        SamplingRule::factory()->for($project)->create([
            'transaction_name' => null,
            'release' => 'errstack@2.*',
            'sample_rate' => 0.01,
        ]);

        $this->withDraw(0.0);

        $body = TransactionPayload::make();
        unset($body['release']);

        $decision = $this->decide($project, $body);

        $this->assertNull($decision->rule);
        $this->assertSame(1.0, $decision->serverRate);
        $this->assertTrue($decision->keep);
    }

    /**
     * Ein zweiter Durchlauf derselben Rohdaten darf die Hochrechnung nicht ein
     * zweites Mal erhöhen — sonst wäre eine wiederholte Verarbeitung in der
     * Übersicht doppelter Verkehr.
     */
    public function test_reprocessing_does_not_extrapolate_twice(): void
    {
        $key = $this->key();

        $payload = $this->ingest($this->withClientRate(TransactionPayload::make(), 0.1), $key);

        // Wie nach einer Verbesserung an einem Schritt: der Anspruch auf die
        // Nummer wird freigegeben und dieselbe Meldung läuft noch einmal durch
        // die ganze Kette.
        ProcessedEvent::release($payload);
        $payload->resetProcessing();

        ProcessIngestPayload::dispatch($payload->fresh());

        $this->assertSame(1, Transaction::query()->count());

        $aggregate = TransactionAggregate::query()->sole();

        $this->assertSame(1, $aggregate->transaction_count);
        $this->assertEqualsWithDelta(10.0, $aggregate->extrapolated_count, 0.0001);
    }

    /**
     * Setzt die Quote des SDK dort, wo die neueren Fassungen sie schreiben.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function withClientRate(array $body, float $rate): array
    {
        $body['contexts']['trace']['data'] = ['sentry.sample_rate' => $rate];

        return $body;
    }
}
