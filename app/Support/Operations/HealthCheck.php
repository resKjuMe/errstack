<?php

namespace App\Support\Operations;

use App\Enums\HealthState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prüft die vier Bestandteile, ohne die von dieser Installation nichts
 * funktioniert: Datenbank, Zwischenspeicher, Warteschlange und Dateiablage.
 *
 * Jede Prüfung tut das, was der Betrieb später auch tut — sie schreibt und
 * liest wieder. Ein reiner Verbindungsaufbau würde die Fälle übersehen, die im
 * Ernstfall vorkommen: eine Ablage, die voll ist, ein Zwischenspeicher, der
 * annimmt und nichts behält, eine Datenbank, die nur noch lesend antwortet.
 *
 * Was hier **nicht** geprüft wird, ist der Rückstand. Er sagt nichts darüber,
 * ob die Installation arbeitsfähig ist, sondern nur, wie viel Arbeit anliegt —
 * und eine beschäftigte Installation aus dem Ladeverteiler zu nehmen, nimmt ihr
 * die Arbeiter weg, die den Rückstand gerade abbauen. Dafür sind
 * {@see OperationsMetrics} und {@see BacklogWatch} zuständig.
 */
final class HealthCheck
{
    /** Namen der Prüfungen, in der Reihenfolge der Antwort. */
    public const CHECKS = ['database', 'cache', 'queue', 'storage'];

    /**
     * Führt alle Prüfungen aus.
     *
     * @return array<string, array{state: HealthState, duration_ms: int}>
     */
    public function run(): array
    {
        return [
            'database' => $this->measure(fn () => $this->database()),
            'cache' => $this->measure(fn () => $this->cache()),
            'queue' => $this->measure(fn () => $this->queue()),
            'storage' => $this->measure(fn () => $this->storage()),
        ];
    }

    /**
     * Der Zustand der ganzen Installation: so gut wie ihr schlechtester Teil.
     *
     * @param  array<string, array{state: HealthState, duration_ms: int}>  $checks
     */
    public static function overall(array $checks): HealthState
    {
        foreach ($checks as $check) {
            if (! $check['state']->isOk()) {
                return HealthState::Failed;
            }
        }

        return HealthState::Ok;
    }

    /**
     * Führt eine Prüfung aus und misst sie.
     *
     * Zwei Wege, auf denen eine Prüfung scheitert: sie wirft, oder sie
     * antwortet zu spät. Der zweite ist der häufigere und wird sonst übersehen
     * — eine Datenbank, die für `select 1` Sekunden braucht, ist für die
     * Fehlerannahme so gut wie weg, wirft aber nichts.
     *
     * Die Ausnahme selbst wird bewusst verschluckt und nicht weitergereicht:
     * ihre Meldung enthält Verbindungsdaten, und die Antwort auf `/health` ist
     * öffentlich. Wer den Grund braucht, findet ihn im Log der Anwendung.
     *
     * @param  callable(): void  $check
     * @return array{state: HealthState, duration_ms: int}
     */
    private function measure(callable $check): array
    {
        $started = hrtime(true);

        try {
            $check();
            $state = HealthState::Ok;
        } catch (Throwable) {
            $state = HealthState::Failed;
        }

        $duration = (int) round((hrtime(true) - $started) / 1_000_000);

        if ($duration > self::slowMs()) {
            $state = HealthState::Failed;
        }

        return ['state' => $state, 'duration_ms' => $duration];
    }

    private function database(): void
    {
        DB::select('select 1');
    }

    /**
     * Schreiben und wieder lesen, nicht nur schreiben: ein Zwischenspeicher,
     * dessen Gegenstelle weg ist, nimmt bei manchen Treibern klaglos an und
     * liefert danach nichts.
     */
    private function cache(): void
    {
        $key = 'health:'.Str::random(12);
        $value = Str::random(12);

        Cache::put($key, $value, 10);

        try {
            if (Cache::get($key) !== $value) {
                throw new HealthCheckFailed('Der Zwischenspeicher hat den Probewert nicht behalten.');
            }
        } finally {
            Cache::forget($key);
        }
    }

    /**
     * Die Länge einer Warteschlange abfragen — die einzige Auskunft, die sich
     * holen lässt, ohne einen Job einzureihen. Einen Probe-Job einzureihen
     * wäre die gründlichere Prüfung und zugleich die schlechtere: bei jedem
     * Aufruf der Adresse liefe einer, und `/health` wird im Sekundentakt
     * abgefragt.
     */
    private function queue(): void
    {
        Queue::size();
    }

    /**
     * Schreiben, lesen, löschen. Die Ablage ist der Bestandteil, der als
     * einziger von selbst kaputtgeht, ohne dass jemand etwas ändert — sie
     * läuft voll.
     */
    private function storage(): void
    {
        $disk = Storage::disk(config('operations.health.disk'));
        $path = 'health/'.Str::random(12).'.txt';
        $value = Str::random(12);

        try {
            $disk->put($path, $value);

            if ($disk->get($path) !== $value) {
                throw new HealthCheckFailed('Die Dateiablage hat den Probewert nicht zurückgegeben.');
            }
        } finally {
            $disk->delete($path);
        }
    }

    private static function slowMs(): int
    {
        return max(1, (int) config('operations.health.slow_ms', 2000));
    }
}
