<?php

namespace App\Support\Discover\Datasets;

use App\Enums\SpanStatus;
use App\Models\Transaction;
use App\Support\Discover\Dataset;
use App\Support\Discover\DiscoverLimits;
use App\Support\Discover\FieldDefinition;
use App\Support\Discover\FieldType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Felder der einzelnen Antwortzeit-Messungen.
 *
 * Diese Quelle beantwortet die Fragen, die die vorberechneten Fenster
 * ({@see TransactionWindowFields}) nicht können, weil sie die Dimension gar nicht
 * tragen: „welcher Browser ist langsam", „welcher Nutzer wartet", „wie verhält sich
 * dieser eine Aufruf". Der Preis steht in der Aufgabenbeschreibung der Quelle: eine
 * Abfrage, die mit der Datenmenge wächst — und deshalb die Grenzen aus
 * {@see DiscoverLimits} braucht.
 *
 * **Die Stichprobe ist hier sichtbar und wird nicht wegdefiniert.** Bei aktiver
 * Stichprobe (I9) ist die Zahl der gespeicherten Messungen nicht die Zahl der
 * Aufrufe; `count()` zählt deshalb Messungen. Wer den Durchsatz meint, fragt die
 * Fenster — dort steht die hochgerechnete Zahl. Ein `count()`, das hier stillschweigend
 * hochrechnete, wäre eine Zahl, die zu keiner Zeile passt, die man aufklappen kann.
 */
final class TransactionFields extends AbstractDatasetFields
{
    public function dataset(): Dataset
    {
        return Dataset::Transactions;
    }

    public function query(): Builder
    {
        return Transaction::query();
    }

    public function timeColumn(): string
    {
        return 'transactions.started_at';
    }

    protected function freeTextColumns(): array
    {
        return ['transactions.name'];
    }

    protected function durationField(): string
    {
        return 'duration';
    }

    protected function failureExpression(): string
    {
        // Ein Status, den wir nicht kennen, zählt **nicht** als Fehlschlag —
        // dieselbe Entscheidung wie in {@see SpanStatus::isFailureValue()}, und aus
        // demselben Grund: ein neuer Sentry-Status würde sonst die Fehlerquote
        // aller Seiten auf 100 % springen lassen.
        $values = array_map(
            static fn (SpanStatus $status): string => '\''.$status->value.'\'',
            array_values(array_filter(SpanStatus::cases(), static fn (SpanStatus $status): bool => $status->isFailure())),
        );

        return $this->wrap('transactions.status').' in ('.implode(', ', $values).')';
    }

    /**
     * @return array<string, FieldDefinition>
     */
    protected function definitions(): array
    {
        $fields = [];

        // `browser` trägt hier nur den Namen und nicht die Fassung — so hat die
        // Aufnahme es abgelegt (siehe `add_client_context_to_transactions_table`),
        // weil eine Aufschlüsselung „Safari 17.4.1, Safari 17.4, Safari 17.3 …"
        // dieselbe Frage in zwanzig Zeilen zerlegt. Deshalb gibt es hier auch kein
        // zusammengesetztes `browser` wie bei den Fehlermeldungen: es wäre ein
        // Feld, hinter dem keine Fassung steht.
        foreach (['name', 'op', 'source', 'status', 'platform', 'environment', 'release', 'browser', 'device', 'country', 'user_identifier'] as $column) {
            $fields[] = $this->text($column, 'transactions.'.$column);
        }

        $fields[] = $this->text('trace_id', 'transactions.trace_id');
        $fields[] = $this->text('event_id', 'transactions.event_id', groupable: false);

        $fields[] = $this->number('duration', 'transactions.duration_us', FieldType::Duration);
        $fields[] = $this->number('span_count', 'transactions.span_count');

        $fields[] = $this->timestamp('started_at', 'transactions.started_at');
        $fields[] = $this->timestamp('finished_at', 'transactions.finished_at');

        return $this->keyed($fields);
    }
}
