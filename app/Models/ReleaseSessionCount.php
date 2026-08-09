<?php

namespace App\Models;

use App\Concerns\TalliesSessions;
use App\Support\Releases\Health\ReleaseHealth;
use App\Support\Releases\Health\SessionTally;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Die Sitzungszahlen je Version, Umgebung und Zeitfenster.
 *
 * Aus dieser Tabelle kommt die Zahl, um die es bei der Release-Gesundheit geht:
 * **wie viele Sitzungen einer Version sind absturzfrei geblieben.** Sie steht
 * hier vorberechnet und nicht als Auswertung über Einzelsitzungen, weil die
 * Antwort sonst mit jedem Tag Betrieb langsamer würde — und weil ein großer
 * Teil der Sitzungen schon gebündelt ankommt und einzeln gar nicht existiert.
 *
 * Geschrieben wird ausschließlich über {@see apply()}, gelesen über
 * {@see ReleaseHealth}.
 *
 * @property int $id
 * @property int $project_id
 * @property int $release_id
 * @property string $environment
 * @property CarbonImmutable $bucket_start
 * @property int $session_count
 * @property int $errored_count
 * @property int $crashed_count
 * @property int $abnormal_count
 */
class ReleaseSessionCount extends Model
{
    use TalliesSessions;

    /**
     * Die vier Zähler — in der Reihenfolge, in der sie gelesen werden.
     *
     * Als Liste und nicht als vier einzelne Angaben, weil sie an drei Stellen
     * gebraucht wird: in der Migration, beim Fortschreiben und beim Summieren.
     * Dreimal dieselbe Aufzählung wäre eine, die beim nächsten Zähler an zwei
     * Stellen ergänzt und an einer vergessen würde.
     *
     * @var list<string>
     */
    public const COUNTER_COLUMNS = [
        'session_count',
        'errored_count',
        'crashed_count',
        'abnormal_count',
    ];

    /**
     * Die Breite eines Zeitfensters in Sekunden.
     *
     * Eine Minute, aus zwei Gründen. Erstens kommen die gebündelten Sitzungen
     * ohnehin minutenweise an (`started` ist dort auf die Minute abgerundet) —
     * ein gröberes Raster würde eine Auflösung wegwerfen, die schon da ist.
     * Zweitens rechnen die Schwellwert-Alarme (A3) über Fenster von wenigen
     * Minuten; bei stundenweiser Ablage wäre die Crash-Free-Rate als Alarm
     * nicht zu gebrauchen, weil ein Fünf-Minuten-Fenster meist gar keine Zeile
     * träfe.
     */
    public const BUCKET_SECONDS = 60;

    /**
     * Der Anfang des Zeitfensters, in das ein Zeitpunkt fällt.
     *
     * Immer in UTC gerechnet: die Zeitzone der Anwendung darf die Rasterung
     * nicht verschieben, sonst lägen dieselben Sitzungen je nach Serverzeit in
     * verschiedenen Fenstern.
     */
    public static function bucket(DateTimeInterface $at): CarbonImmutable
    {
        $at = CarbonImmutable::parse($at)->utc();

        return $at->subSeconds($at->secondOfMinute % self::BUCKET_SECONDS)->startOfSecond();
    }

    /**
     * Der Schlüssel einer Zeile.
     *
     * @return array<string, mixed>
     */
    public static function keyFor(int $projectId, int $releaseId, string $environment, DateTimeInterface $bucket): array
    {
        return [
            'project_id' => $projectId,
            'release_id' => $releaseId,
            'environment' => $environment,
            'bucket_start' => self::bucket($bucket),
        ];
    }

    /**
     * Die Summen der vier Zähler als Ausdrücke für eine Abfrage.
     *
     * @return list<string>
     */
    public static function sumExpressions(): array
    {
        return array_map(
            static fn (string $column): string => 'sum('.$column.') as '.$column,
            self::COUNTER_COLUMNS,
        );
    }

    /**
     * Macht aus einer summierten Zeile wieder eine Strichliste.
     *
     * @param  array<string, mixed>  $row
     */
    public static function tallyFromRow(array $row): SessionTally
    {
        return new SessionTally(
            (int) ($row['session_count'] ?? 0),
            (int) ($row['errored_count'] ?? 0),
            (int) ($row['crashed_count'] ?? 0),
            (int) ($row['abnormal_count'] ?? 0),
        );
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
