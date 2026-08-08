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
 */
class CommitFile extends Model
{
    public $timestamps = false;

    /**
     * Der Dateiname ohne Verzeichnis — was in einer Liste zuerst gelesen wird.
     */
    public function basename(): string
    {
        $path = rtrim($this->path, '/');
        $slash = strrpos($path, '/');

        return $slash === false ? $path : substr($path, $slash + 1);
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'change_type' => CommitFileChange::class,
        ];
    }
}
