<?php

namespace App\Support\Operations;

use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Die Fehlerablage der Warteschlange, so weit der Betrieb sie braucht:
 * nachsehen, was liegengeblieben ist, es erneut starten oder wegwerfen.
 *
 * Ein eigener Zugang und kein direkter Griff in die Tabelle `failed_jobs`:
 * welcher Treiber dahintersteht, sagt `config/queue.php` — `database-uuids` ist
 * die Vorgabe, es kann aber auch DynamoDB oder eine Datei sein. Laravel legt
 * für genau diesen Zweck eine Schnittstelle davor.
 *
 * Das erneute Starten geht bewusst über `queue:retry` und nicht über eine
 * eigene Nachbildung: dort steckt die Arbeit, die leicht zu übersehen ist —
 * die Nutzlast wird um die Kennung des Wiederholungslaufs ergänzt, der Eintrag
 * erst nach erfolgreichem Einreihen gelöscht, und Batches werden mitgezählt.
 *
 * Nicht zu verwechseln mit `ingest:retry`: dort geht es um gescheiterte
 * **Meldungen**, hier um gescheiterte **Jobs**. Der Unterschied zählt, sobald
 * ein Schritt der Kette repariert wurde — dann gibt es keinen Job mehr, den man
 * wiederholen könnte, die Rohdaten liegen aber noch da.
 */
final class FailedJobs
{
    public function __construct(private readonly FailedJobProviderInterface $failer) {}

    /**
     * Wie viele Jobs endgültig gescheitert sind.
     *
     * Die Schnittstelle kann nicht zählen, nur auflisten — deshalb wird
     * gezählt, was sie liefert. Vertretbar, weil die Ablage im gesunden
     * Betrieb leer ist und `queue:prune-failed` sie wöchentlich kürzt
     * ({@see routes/console.php}); wächst sie so weit, dass das Zählen
     * auffällt, ist genau das die Auskunft, um die es hier geht.
     */
    public function count(): int
    {
        return count($this->failer->all());
    }

    /**
     * Die jüngsten Fehlschläge, aufbereitet für die Ansicht.
     *
     * @return list<array{id: string, queue: string, name: string, failedAt: string|null, exception: string}>
     */
    public function recent(int $limit = 50): array
    {
        $jobs = array_slice($this->failer->all(), 0, max(1, $limit));

        return array_values(array_map(self::present(...), $jobs));
    }

    /**
     * Startet einen einzelnen Job erneut.
     *
     * Der Rückgabewert sagt, ob es einen Eintrag mit dieser Kennung gab — wer
     * eine Ansicht offen hat, während jemand anderes aufräumt, soll eine
     * ehrliche Antwort bekommen und keine Erfolgsmeldung ins Leere.
     */
    public function retry(string $id): bool
    {
        if ($this->failer->find($id) === null) {
            return false;
        }

        Artisan::call('queue:retry', ['id' => [$id]]);

        return true;
    }

    /**
     * Startet alle gescheiterten Jobs erneut und sagt, wie viele es waren.
     */
    public function retryAll(): int
    {
        $count = $this->count();

        if ($count > 0) {
            Artisan::call('queue:retry', ['id' => ['all']]);
        }

        return $count;
    }

    /**
     * Wirft einen Eintrag weg, ohne ihn erneut zu starten.
     */
    public function forget(string $id): bool
    {
        return $this->failer->forget($id);
    }

    /**
     * @param  object{id?: mixed, uuid?: mixed, queue?: mixed, payload?: mixed, exception?: mixed, failed_at?: mixed}  $job
     * @return array{id: string, queue: string, name: string, failedAt: string|null, exception: string}
     */
    private static function present(object $job): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) ($job->payload ?? ''), true) ?: [];

        return [
            // `database-uuids` führt die Kennung in `uuid`, die alte
            // `database`-Ablage in `id`. `queue:retry` nimmt beide.
            'id' => (string) ($job->uuid ?? $job->id ?? ''),
            'queue' => (string) ($job->queue ?? '—'),
            'name' => self::name($payload),
            'failedAt' => self::failedAt($job),
            // Nur die erste Zeile: sie nennt Klasse und Meldung, der Rest ist
            // der Aufrufweg und gehört nicht in eine Tabellenzelle.
            'exception' => Str::limit(strtok((string) ($job->exception ?? ''), "\n") ?: '—', 200),
        ];
    }

    /**
     * Der Name des Jobs, wie ihn ein Mensch sucht.
     *
     * `displayName` steht in jeder von Laravel erzeugten Nutzlast und ist
     * bereits der sprechende Name — bei einem Listener oder einer
     * Benachrichtigung also nicht die Hülle, sondern das, was tatsächlich
     * ausgeführt werden sollte.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function name(array $payload): string
    {
        $name = $payload['displayName'] ?? $payload['job'] ?? null;

        return is_string($name) && $name !== '' ? $name : '—';
    }

    private static function failedAt(object $job): ?string
    {
        $value = $job->failed_at ?? null;

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)->toIso8601String()
            : null;
    }
}
