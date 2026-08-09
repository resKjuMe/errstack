<?php

namespace App\Models;

use App\Concerns\TalliesSessions;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Die Nutzer-Seite der Release-Gesundheit: je Nutzer, Version, Umgebung und
 * Zeitfenster eine Zeile.
 *
 * Sie beantwortet die beiden Fragen, die {@see ReleaseSessionCount} nicht
 * beantworten kann, weil dort bewusst kein Nutzer im Schlüssel steht:
 *
 *   **Wie vielen Menschen ist es passiert?** „Fünfhundert abgestürzte
 *   Sitzungen" ist eine andere Nachricht, je nachdem, ob dahinter fünfhundert
 *   Betroffene stehen oder einer mit einer Neustart-Schleife.
 *
 *   **Wie verbreitet ist die Version?** Die Verbreitung („Adoption") ist der
 *   Anteil der Menschen, die diese Version benutzen, an allen, die überhaupt
 *   unterwegs sind — eine Zahl über Nutzer, nicht über Sitzungen.
 *
 * Gezählt wird über den eindeutigen Schlüssel (`count(distinct user_key)`),
 * nicht über die Sitzungen: dieselbe Abwägung wie bei den Nutzer-Zahlen der
 * Antwortzeiten ({@see TransactionUserAggregate}), und aus demselben Grund.
 *
 * @property int $id
 * @property int $project_id
 * @property int $release_id
 * @property string $environment
 * @property CarbonImmutable $bucket_start
 * @property string $user_key
 * @property int $session_count
 * @property int $errored_count
 * @property int $crashed_count
 * @property int $abnormal_count
 */
class ReleaseSessionUser extends Model
{
    use TalliesSessions;

    /**
     * Die Nutzerkennung in der Form, in der sie hier steht.
     *
     * Gehasht, weil in dieser Tabelle nur gezählt und nie angezeigt wird — und
     * weil eine Kennung, die aus der überwachten Anwendung stammt, alles Mögliche
     * enthalten kann: eine Kundennummer, eine Adresse, einen Namen. Wer
     * betroffen ist, steht am Fehler; hier genügt ein Wert, der sich mit sich
     * selbst vergleichen lässt.
     *
     * Derselbe Hash wie bei den Antwortzeiten ({@see TransactionUserAggregate}),
     * damit „ein Nutzer" in beiden Auswertungen dasselbe heißt.
     */
    public static function keyFor(string $identifier): string
    {
        return substr(hash('sha256', $identifier), 0, TransactionUserAggregate::KEY_LENGTH);
    }

    /**
     * Die gehashte Kennung einer gemeldeten Nutzerangabe — oder `null`, wenn
     * keine brauchbare dabei war.
     *
     * Der Regelfall bei Server-SDKs und bei Anwendungen, die keine Nutzerdaten
     * senden dürfen. Dann gibt es hier nichts zu zählen, und das ist kein
     * Mangel: die Sitzungszahlen stehen trotzdem, nur die Nutzerzahlen fehlen.
     */
    public static function keyForIdentifier(mixed $identifier): ?string
    {
        if (! is_string($identifier) && ! is_int($identifier)) {
            return null;
        }

        $identifier = trim((string) $identifier);

        return $identifier === '' ? null : self::keyFor(Str::limit($identifier, 255, ''));
    }

    /**
     * Der Schlüssel einer Zeile.
     *
     * @return array<string, mixed>
     */
    public static function keyForUser(
        int $projectId,
        int $releaseId,
        string $environment,
        DateTimeInterface $bucket,
        string $userKey,
    ): array {
        return ReleaseSessionCount::keyFor($projectId, $releaseId, $environment, $bucket) + ['user_key' => $userKey];
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
            'bucket_start' => 'immutable_datetime',
            'session_count' => 'integer',
            'errored_count' => 'integer',
            'crashed_count' => 'integer',
            'abnormal_count' => 'integer',
        ];
    }
}
