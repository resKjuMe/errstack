<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Die persönlichen Einstellungen eines Nutzers, die für alle Anlässe
 * zugleich gelten: Ruhezeiten und die pauschale Abmeldung.
 *
 * Beides betrifft nur nicht-kritische Meldungen. Ein Alarm kommt auch nachts
 * und auch nach dem Abbestellen an — sonst wäre die Bereitschaft mit einem
 * Klick auf einen Abmelde-Link stillgelegt, ohne dass es jemandem auffällt.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $quiet_hours_enabled
 * @property string $quiet_from
 * @property string $quiet_until
 * @property string $timezone
 * @property bool $digest_enabled
 * @property Carbon|null $unsubscribed_at
 */
#[Fillable(['quiet_hours_enabled', 'quiet_from', 'quiet_until', 'timezone', 'digest_enabled'])]
class NotificationSetting extends Model
{
    public const DEFAULT_FROM = '22:00';

    public const DEFAULT_UNTIL = '07:00';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Liegt der Zeitpunkt in der Ruhezeit dieses Nutzers?
     *
     * Gerechnet wird in seiner eigenen Zeitzone — „ab 22 Uhr" meint die Uhr an
     * seiner Wand, nicht die des Servers. Eine Ruhezeit über Mitternacht
     * (22:00 bis 07:00) ist der Normalfall und deshalb ausdrücklich behandelt.
     */
    public function isQuietAt(?Carbon $at = null): bool
    {
        if (! $this->quiet_hours_enabled) {
            return false;
        }

        $from = $this->minutes($this->quiet_from);
        $until = $this->minutes($this->quiet_until);

        // Gleiche Zeiten ergeben keine sinnvolle Spanne. Sie als „ganzer Tag"
        // zu lesen wäre die gefährlichere Auslegung: sie legte den Versand
        // dauerhaft still. Die Prüfung der Eingabe verhindert den Fall bereits.
        if ($from === $until) {
            return false;
        }

        $now = $this->minutes(($at ?? Date::now())->copy()->setTimezone($this->timezone)->format('H:i'));

        return $from < $until
            ? $now >= $from && $now < $until
            : $now >= $from || $now < $until;
    }

    /**
     * Ende der laufenden Ruhezeit — die Auskunft, die in der Übersicht steht
     * („wieder ab 07:00"). Null, wenn gerade keine Ruhezeit ist.
     */
    public function quietUntilLabel(?Carbon $at = null): ?string
    {
        return $this->isQuietAt($at) ? substr($this->quiet_until, 0, 5) : null;
    }

    public function isUnsubscribed(): bool
    {
        return $this->unsubscribed_at !== null;
    }

    /**
     * Vorgabe für ein Konto, das noch nichts eingestellt hat. Bewusst ein
     * nicht gespeichertes Modell: eine Zeile entsteht erst, wenn jemand
     * tatsächlich etwas entscheidet.
     */
    public static function defaultsFor(User $user): self
    {
        $setting = new self([
            'quiet_hours_enabled' => false,
            'quiet_from' => self::DEFAULT_FROM,
            'quiet_until' => self::DEFAULT_UNTIL,
            'timezone' => (string) config('app.timezone', 'UTC'),
            // Bündelung an: die Vorgabe muss die des Projekts durchlassen,
            // sonst wäre dessen Einstellung wirkungslos, bis jeder Empfänger
            // sie einzeln bestätigt hat (A6).
            'digest_enabled' => true,
        ]);

        $setting->user_id = $user->id;

        return $setting;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quiet_hours_enabled' => 'boolean',
            'digest_enabled' => 'boolean',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * Uhrzeit als Minuten seit Mitternacht. Die Spalte ist ein `time`-Feld und
     * kommt je nach Datenbank als `22:00` oder `22:00:00` zurück.
     */
    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map(intval(...), explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }
}
