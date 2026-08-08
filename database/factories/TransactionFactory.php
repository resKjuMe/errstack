<?php

namespace Database\Factories;

use App\Models\IngestPayload;
use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subSeconds(fake()->numberBetween(1, 600));

        // Zwischen 5 ms und 2 s — der Bereich, in dem sich echte Antwortzeiten
        // bewegen. Erzeugte Messungen sollen in einer Verteilung plausibel
        // aussehen, sonst prüft ein Test die Anzeige gegen Zahlen, die es nie
        // gibt.
        $durationUs = fake()->numberBetween(5_000, 2_000_000);

        return [
            'project_id' => Project::factory(),
            'ingest_payload_id' => null,
            'event_id' => IngestPayload::freshEventId(),
            'trace_id' => IngestPayload::freshEventId(),
            'span_id' => substr(IngestPayload::freshEventId(), 0, 16),
            'parent_span_id' => null,
            'name' => 'GET /'.fake()->slug(2),
            'op' => 'http.server',
            'source' => 'route',
            'status' => 'ok',
            'platform' => 'php',
            // Die Voreinstellung ist eine serverseitige Messung, und die kommt
            // ohne Browser, Gerät und Land — dort gibt es keine.
            'browser' => null,
            'device' => null,
            'country' => null,
            'environment' => 'production',
            'release' => null,
            'user_identifier' => null,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addMicroseconds($durationUs),
            'duration_us' => $durationUs,
            'span_count' => 0,
            'measurements' => null,
        ];
    }

    /**
     * Eine Messung mit festgelegter Dauer — für Tests, die über Mittelwerte,
     * Grenzwerte oder Perzentile rechnen.
     */
    public function lasting(int $durationUs): static
    {
        return $this->state(fn (array $attributes): array => [
            'duration_us' => $durationUs,
            // `Carbon::parse`, weil der Anfang auch als Text übergeben werden
            // kann — eine Zustandsmethode darf nicht davon abhängen, in welcher
            // Form der Aufrufer ihn gesetzt hat.
            'finished_at' => Carbon::parse($attributes['started_at'])->addMicroseconds($durationUs),
        ]);
    }

    /**
     * Ein Aufruf, der nicht erfolgreich war.
     */
    public function failed(string $status = 'internal_error'): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ]);
    }

    /**
     * Ein Seitenaufruf im Browser: Ladevorgang samt Gerät, Browser und Land.
     *
     * Die Messwerte werden in der Form abgelegt, in der die Aufnahme sie
     * hinterlässt — Wert und Einheit —, und nicht als nackte Zahl. Ein Test, der
     * die Aufschlüsselung prüft, soll denselben Feld-Baum vor sich haben wie die
     * Auswertung im Betrieb; sonst prüft er eine Form, die es nicht gibt.
     *
     * @param  array<string, float|int>  $vitals  Messwert-Schlüssel → Wert in
     *                                            Millisekunden, beim
     *                                            Verschiebungswert als Punktzahl.
     */
    public function inBrowser(
        array $vitals = [],
        ?string $browser = 'Chrome',
        ?string $device = 'Pixel 8',
        ?string $country = 'DE',
    ): static {
        return $this->state(fn (): array => [
            'op' => 'pageload',
            'platform' => 'javascript',
            'browser' => $browser,
            'device' => $device,
            'country' => $country,
            // `array_combine`, weil `array_map` über zwei Felder die Schlüssel
            // wegwirft — heraus käme eine Liste `[0 => …]`, und die Messwerte
            // hätten ihre Namen verloren.
            'measurements' => $vitals === [] ? null : array_combine(
                array_keys($vitals),
                array_map(
                    static fn (string $name, float|int $value): array => [
                        'value' => (float) $value,
                        // Der Verschiebungswert hat keine Einheit; alles andere
                        // ist eine Dauer in Millisekunden — genau so, wie die
                        // SDKs es melden.
                        'unit' => $name === 'cls' ? '' : 'millisecond',
                    ],
                    array_keys($vitals),
                    array_values($vitals),
                ),
            ),
        ]);
    }
}
