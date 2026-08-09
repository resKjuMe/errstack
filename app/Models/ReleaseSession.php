<?php

namespace App\Models;

use App\Enums\SessionStatus;
use App\Support\Releases\Health\SessionRecorder;
use App\Support\Releases\Health\SessionTally;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Eine einzelne Sitzung — und zwar nur, solange sie sich noch ändern kann.
 *
 * Diese Tabelle ist **kein Archiv**, sondern das Gedächtnis, das die Zusage
 * „keine Sitzung wird doppelt gezählt" überhaupt erst einlösbar macht. Ein SDK
 * meldet dieselbe Sitzung mehrfach: beim Start („läuft"), gelegentlich
 * zwischendurch, und am Ende mit ihrem Ausgang. Erst der Vergleich mit dem
 * zuletzt bekannten Ausgang sagt, ob eine Sitzung dazugekommen ist oder ob eine
 * vorhandene nur ihren Zustand gewechselt hat.
 *
 * Die Zahlen selbst stehen woanders ({@see ReleaseSessionCount},
 * {@see ReleaseSessionUser}); geschrieben wird hier ausschließlich über
 * {@see SessionRecorder}.
 *
 * **Gebündelte Sitzungen haben hier keine Zeile.** Ein `sessions`-Element
 * bringt fertige Zahlen ohne Sitzungsnummern mit — es gibt dort nichts
 * wiederzufinden. Das ist kein Mangel dieser Tabelle, sondern die Eigenschaft
 * des Formats: wer bündelt, hat die Zwischenstände schon selbst verrechnet.
 *
 * @property int $id
 * @property int $project_id
 * @property int $release_id
 * @property string $environment
 * @property string $sid
 * @property string|null $user_key
 * @property string $status
 * @property int $error_count
 * @property int $seq
 * @property CarbonImmutable $bucket_start
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $last_seen_at
 */
class ReleaseSession extends Model
{
    /**
     * Längstmögliche Sitzungsnummer (siehe Migration). Die Spezifikation sieht
     * eine UUID vor; gekürzt statt abgewiesen, damit eine ungewöhnlich
     * gebildete Nummer ihre Sitzung nicht verliert.
     */
    public const SID_LIMIT = 64;

    /**
     * Längster Zustandsname (siehe Migration) — bequem über dem längsten Wert
     * von {@see SessionStatus}.
     */
    public const STATUS_LIMIT = 16;

    /**
     * Die Sitzungsnummer in der Form, in der sie hier steht.
     *
     * Vereinheitlicht wie eine Versionsangabe, damit dieselbe Sitzung nicht an
     * einem Leerzeichen oder einer Großschreibung zu zweien wird — genau das
     * wäre eine doppelt gezählte Sitzung.
     */
    public static function normalizeSid(mixed $sid): ?string
    {
        if (! is_string($sid) && ! is_int($sid)) {
            return null;
        }

        $sid = strtolower(trim((string) $sid));

        // Manche SDKs schicken die UUID mit Bindestrichen, andere ohne. Beides
        // ist dieselbe Sitzung.
        $sid = str_replace('-', '', $sid);

        return $sid === '' ? null : Str::limit($sid, self::SID_LIMIT, '');
    }

    /**
     * Der Ausgang, mit dem diese Sitzung zuletzt gezählt wurde.
     *
     * Der Wert, gegen den die nächste Meldung derselben Sitzung verrechnet
     * wird. Er wird **nicht** neu hergeleitet, sondern aus dem gelesen, was
     * gespeichert ist: eine später geänderte Auslegung von „errored" darf die
     * bereits gezählten Sitzungen nicht rückwirkend umdeuten, sonst stimmten
     * Zähler und Einzelsitzungen nicht mehr überein.
     */
    public function tally(): SessionTally
    {
        return SessionStatus::fromPayload($this->status)->tally($this->error_count);
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'error_count' => 'integer',
            'seq' => 'integer',
            'bucket_start' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
