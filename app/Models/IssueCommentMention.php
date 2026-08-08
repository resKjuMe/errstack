<?php

namespace App\Models;

use App\Support\Issues\IssueComments;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Nennung in einem Kommentar: „@Anna Beck, kannst du dir das ansehen?"
 *
 * Sie hält zweierlei fest. Erstens **wer** gemeint war, als Verweis auf ein
 * Konto oder ein Team — daran hängt die Benachrichtigung, und daran hängt beim
 * Bearbeiten die Frage, wer sie schon bekommen hat. Zweitens **womit** genannt
 * wurde (`label`): der Text hinter dem `@`, so wie er im Rumpf steht. Er ist
 * das, woran der Server den Rumpf beim Anzeigen wieder in Text und Nennungen
 * zerlegt.
 *
 * Genau eines von `user_id` und `team_id` ist gesetzt. Erzwungen wird das nicht
 * von der Datenbank, sondern von der einzigen Stelle, die hier schreibt
 * ({@see IssueComments}) — eine Prüfbedingung dafür kennt
 * SQLite in älteren Fassungen nicht, und ein zweites Regelwerk, das nur auf
 * einer der beiden Datenbanken greift, wäre schlimmer als keines.
 *
 * @property int $id
 * @property int $issue_comment_id
 * @property int|null $user_id
 * @property int|null $team_id
 * @property string $label
 */
#[Fillable([
    'issue_comment_id',
    'user_id',
    'team_id',
    'label',
])]
class IssueCommentMention extends Model
{
    /**
     * @return BelongsTo<IssueComment, $this>
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(IssueComment::class, 'issue_comment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
