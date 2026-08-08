<?php

namespace App\Models;

use App\Enums\CommitFileChange;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Datei, die ein Commit angefasst hat.
 *
 * Eine eigene Tabelle und keine Liste am Commit, weil auf dieser Spalte
 * gesucht wird: der verdächtige Commit (R4) entsteht aus dem Vergleich der
 * Pfade eines Stacktrace mit genau diesen Zeilen. Als entpackte Liste am
 * Commit wäre das eine Schleife über alle Commits einer Auslieferung.
 *
 * Kein `timestamps()`: die Zeile entsteht mit ihrem Commit und ändert sich
 * danach nicht mehr.
 *
 * @property int $id
 * @property int $commit_id
 * @property string $path
 * @property CommitFileChange $change_type
 * @property list<array{int, int}>|null $line_ranges
 */
class CommitFile extends Model
{
    public $timestamps = false;

    /**
     * Liegt diese Zeile in einem der geänderten Bereiche?
     *
     * `false` auch dann, wenn die Bereiche unbekannt sind — die Frage ist in
     * dem Fall nicht mit „nein" beantwortet, sondern gar nicht, und der
     * Abgleich muss beides unterscheiden. Deshalb steht daneben
     * {@see hasLineRanges()}: wer hier `false` bekommt, sieht dort nach, ob das
     * eine Aussage war.
     */
    public function touchesLine(?int $line): bool
    {
        if ($line === null || $this->line_ranges === null) {
            return false;
        }

        foreach ($this->line_ranges as $range) {
            if ($line >= $range[0] && $line <= $range[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Weiß diese Zeile überhaupt, welche Zeilen angefasst wurden?
     *
     * Bei sentry-cli ist die Antwort nein: dessen `patch_set` nennt nur Pfad
     * und Art der Änderung. Eine **leere** Liste ist dagegen eine Auskunft —
     * „an dieser Datei wurde keine Zeile geändert", also eine Umbenennung.
     */
    public function hasLineRanges(): bool
    {
        return $this->line_ranges !== null;
    }

    /**
     * @return BelongsTo<Commit, $this>
     */
    public function commit(): BelongsTo
    {
        return $this->belongsTo(Commit::class);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'commit_id',
        'path',
        'change_type',
        'line_ranges',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'change_type' => CommitFileChange::class,
            'line_ranges' => 'array',
        ];
    }
}
