<?php

namespace App\Models;

use App\Enums\UptimeCheckOutcome;
use App\Support\Uptime\ProbeResult;
use Database\Factories\UptimeCheckFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Eine einzelne Messung: ein Aufruf des Ziels und was dabei herauskam.
 *
 * Der Verlauf ist die Grundlage für zwei Angaben, die man nur aus vielen
 * Messungen bekommt — die Verfügbarkeitsquote und den Antwortzeit-Verlauf. Die
 * Einzelmessung selbst sieht sich fast nie jemand an; sie ist die Zeile, aus
 * der die Kurve besteht.
 *
 * **Die Zeile wird auch bei Erfolg geschrieben.** Nur Fehlschläge zu speichern
 * wäre sparsamer und machte die Quote unmöglich: ohne den Nenner ist „drei
 * Ausfälle" keine Aussage über Verfügbarkeit.
 *
 * @property int $id
 * @property int $uptime_monitor_id
 * @property int $project_id
 * @property UptimeCheckOutcome $outcome
 * @property int|null $http_status
 * @property int|null $response_time_ms
 * @property string|null $error
 * @property int $attempts
 * @property Carbon $checked_at
 * @property-read UptimeMonitor $monitor
 */
class UptimeCheck extends Model
{
    /** @use HasFactory<UptimeCheckFactory> */
    use HasFactory;

    /**
     * So lang darf der Fehlertext werden. Passt zur Spalte; was länger ist,
     * wird gekürzt statt abgewiesen — ein zu langer Text einer Gegenstelle darf
     * die Messung nicht verlieren.
     */
    public const ERROR_LIMIT = 500;

    /**
     * Schreibt das Ergebnis einer Prüfung in den Verlauf.
     */
    public static function record(UptimeMonitor $monitor, ProbeResult $result, Carbon $at): self
    {
        // Kein `Fillable`, wie beim Cronjob-Verlauf: die Angaben kommen aus der
        // Messung und nie aus einer Anfrage. Eine Zuweisungsliste wäre hier eine
        // Erlaubnis für etwas, das gar nicht vorkommt — geschrieben wird Feld
        // für Feld.
        $check = new self;

        $check->uptime_monitor_id = $monitor->id;
        $check->project_id = $monitor->project_id;
        $check->outcome = $result->outcome;
        $check->http_status = $result->httpStatus;
        $check->response_time_ms = $result->responseTimeMs;
        $check->error = $result->error === null ? null : Str::limit($result->error, self::ERROR_LIMIT, '');
        $check->attempts = $result->attempts;
        $check->checked_at = $at;

        $check->save();

        return $check;
    }

    /**
     * Messungen ab einem Zeitpunkt — der Zugriff für Quote und Verlauf.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSince(Builder $query, Carbon $since): Builder
    {
        return $query->where('checked_at', '>=', $since);
    }

    /**
     * @return BelongsTo<UptimeMonitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(UptimeMonitor::class, 'uptime_monitor_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => UptimeCheckOutcome::class,
            'http_status' => 'integer',
            'response_time_ms' => 'integer',
            'attempts' => 'integer',
            'checked_at' => 'datetime',
        ];
    }
}
