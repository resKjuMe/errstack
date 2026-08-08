<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CommitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Eine Änderung im Repository — die kleinste Einheit dessen, was ausgeliefert
 * wurde.
 *
 * Sie gehört zu genau einem Repository und kann in beliebig vielen
 * Auslieferungen stecken (siehe {@see releases()}). Der Autor steht doppelt
 * darin: als Name und Adresse, wie das Repository ihn führt, und — wenn sich
 * die Adresse zuordnen ließ — als Verweis auf ein Konto. Die Zeichenketten
 * bleiben dabei immer stehen, auch wenn der Verweis fehlt: die meisten Commits
 * eines Projekts stammen von Personen, die hier nie ein Konto hatten, und ein
 * Commit ohne Autor wäre für die Frage „wer hat das gemacht?" wertlos.
 *
 * @property int $id
 * @property int $repository_id
 * @property string $sha
 * @property string|null $message
 * @property string|null $author_name
 * @property string|null $author_email
 * @property int|null $author_id
 * @property CarbonImmutable|null $committed_at
 * @property CarbonImmutable|null $created_at
 */
class Commit extends Model
{
    /** @use HasFactory<CommitFactory> */
    use HasFactory;

    /**
     * Längstmöglicher Hash (siehe Migration): SHA-256 in Hexadezimal.
     */
    public const SHA_LIMIT = 64;

    /**
     * Die erste Zeile der Nachricht — die Überschrift der Änderung.
     *
     * In der Liste steht nur sie, weil eine Commit-Nachricht nach der ersten
     * Zeile die Begründung enthält und die eine Liste unlesbar macht. Der Rest
     * ist nicht verloren: {@see body()} gibt ihn heraus.
     */
    public function title(): string
    {
        $message = trim((string) $this->message);

        if ($message === '') {
            return '';
        }

        return trim(Str::before($message, "\n"));
    }

    /**
     * Was nach der ersten Zeile kommt, ohne die Leerzeile dazwischen.
     */
    public function body(): string
    {
        $message = trim((string) $this->message);

        if (! str_contains($message, "\n")) {
            return '';
        }

        return trim(Str::after($message, "\n"));
    }

    /**
     * Der Hash, wie er angezeigt wird: die ersten sieben Stellen.
     *
     * Dieselbe Länge, die Git selbst wählt — und die, unter der ein Mensch ihn
     * wiedererkennt. Der vollständige Hash steht am Link.
     */
    public function shortSha(): string
    {
        return substr($this->sha, 0, 7);
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * Das Konto, dem die Autoren-Adresse zugeordnet werden konnte — oder
     * keines.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<CommitFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(CommitFile::class);
    }

    /**
     * Die Auslieferungen, in denen dieser Commit steckt.
     *
     * Mehrzahl, und das ist der Kern der Ablage: derselbe Commit steht in
     * `1.2.0` und in dem Nachzügler `1.2.1`, und in einer Anwendung mit
     * mehreren Projekten aus demselben Repository ohnehin in den Versionen von
     * jedem.
     *
     * @return BelongsToMany<Release, $this>
     */
    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class, 'release_commit')
            ->withPivot('position');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'repository_id',
        'sha',
        'message',
        'author_name',
        'author_email',
        'author_id',
        'committed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'committed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
