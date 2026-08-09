<?php

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use App\Models\UptimeMonitor;
use App\Support\Uptime\StatusExpectation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Angaben zu einem überwachten Ziel.
 *
 * Die Kennung (`slug`) ist bewusst nicht dabei: sie entsteht aus dem Namen und
 * steht danach in Adressen und in der Verknüpfung zum Fehler-Eintrag. Ließe man
 * sie ändern, bekäme dasselbe Ziel nach einer Umbenennung einen zweiten
 * Eintrag, dessen Zählung bei eins beginnt.
 *
 * **Die Adresse ist die heikelste Angabe hier.** Ein Monitor lässt die
 * Anwendung in festem Takt eine fremde Adresse aufrufen; ohne Einschränkung
 * wäre das ein Werkzeug, um aus dem Netz des Servers heraus interne Dienste
 * abzuklopfen. Deshalb nur `http`/`https` und keine anderen Verfahren — alles
 * Weitere (interne Adressbereiche) gehört in die Betriebsumgebung und nicht in
 * eine Eingabeprüfung, die sich mit einem DNS-Eintrag umgehen ließe.
 */
class UptimeMonitorRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'url' => ['required', 'string', 'max:2048', 'url:http,https'],

            'method' => ['required', Rule::enum(HttpMethod::class)],

            // Kopfzeilen als Liste von Paaren: die Reihenfolge bleibt erhalten,
            // und derselbe Name darf zweimal vorkommen.
            'headers' => ['nullable', 'array', 'max:20'],
            'headers.*.name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9\-_]+$/'],
            'headers.*.value' => ['nullable', 'string', 'max:1024'],

            'body' => ['nullable', 'string', 'max:65535'],

            'expected_status_codes' => [
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (is_string($value) && ! StatusExpectation::isValid($value)) {
                        $fail(__('uptime.validation.status_codes'));
                    }
                },
            ],

            'expected_content' => ['nullable', 'string', 'max:255'],

            // Die Untergrenze ist keine Vorsicht, sondern eine Tatsache: der
            // Zeitplan der Anwendung löst minütlich aus, und ein feinerer Takt
            // wäre eine Zusage, die niemand halten kann. Nach oben ein Tag —
            // seltener geprüft ist keine Überwachung mehr.
            'interval_seconds' => [
                'required',
                'integer',
                'min:'.UptimeMonitor::MINIMUM_INTERVAL_SECONDS,
                'max:86400',
            ],

            // Die Zeitgrenze muss unter dem Takt bleiben: eine Prüfung, die
            // länger warten darf als bis zur nächsten, überholt sich selbst.
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],

            'confirmation_retries' => ['required', 'integer', 'min:0', 'max:5'],
            'confirmation_delay_seconds' => ['required', 'integer', 'min:0', 'max:60'],

            'failure_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'recovery_threshold' => ['required', 'integer', 'min:1', 'max:100'],

            'follow_redirects' => ['boolean'],
            'verify_tls' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Zwei Zusammenhänge, die keine einzelne Regel prüfen kann.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $timeout = (int) $this->input('timeout_seconds');
            $interval = (int) $this->input('interval_seconds');
            $retries = (int) $this->input('confirmation_retries');
            $delay = (int) $this->input('confirmation_delay_seconds');

            // Eine Prüfung samt Bestätigung muss in einen Takt passen. Sonst
            // steht die nächste an, bevor die laufende fertig ist — die Sperre
            // im Job verwirft sie dann, und der eingestellte Takt gälte
            // stillschweigend nicht mehr.
            $worstCase = ($retries + 1) * $timeout + $retries * $delay;

            if ($interval > 0 && $worstCase > $interval) {
                $validator->errors()->add('timeout_seconds', __('uptime.validation.timeout_fits_interval', [
                    'seconds' => (string) $worstCase,
                ]));
            }

            // Ein `HEAD` überträgt keinen Rumpf; eine Inhaltsprüfung daneben
            // würde bei jedem Lauf scheitern und das Ziel dauerhaft als
            // ausgefallen melden.
            $method = HttpMethod::tryFrom((string) $this->input('method'));
            $content = (string) $this->input('expected_content', '');

            if ($content !== '' && $method !== null && ! $method->hasResponseBody()) {
                $validator->errors()->add('expected_content', __('uptime.validation.content_needs_body'));
            }
        });
    }

    /**
     * Leere Kopfzeilen aus dem Formular fallen heraus.
     *
     * Die Oberfläche hält immer eine leere Zeile zum Ausfüllen bereit; ohne das
     * hier käme sie als Kopfzeile ohne Namen an und wäre ein Eingabefehler, den
     * niemand gemacht hat.
     */
    protected function prepareForValidation(): void
    {
        $headers = $this->input('headers');

        if (! is_array($headers)) {
            return;
        }

        $this->merge([
            'headers' => array_values(array_filter(
                $headers,
                static fn (mixed $header): bool => is_array($header) && trim((string) ($header['name'] ?? '')) !== '',
            )),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('uptime.name'),
            'url' => __('uptime.url'),
            'method' => __('uptime.method'),
            'body' => __('uptime.body'),
            'expected_status_codes' => __('uptime.expected_status_codes'),
            'expected_content' => __('uptime.expected_content'),
            'interval_seconds' => __('uptime.interval'),
            'timeout_seconds' => __('uptime.timeout'),
            'confirmation_retries' => __('uptime.confirmation_retries'),
            'confirmation_delay_seconds' => __('uptime.confirmation_delay'),
            'failure_threshold' => __('uptime.failure_threshold'),
            'recovery_threshold' => __('uptime.recovery_threshold'),
        ];
    }
}
