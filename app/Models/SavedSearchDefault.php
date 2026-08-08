<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * „Womit dieses Projekt für mich aufgeht": die Suche, mit der die Fehlerliste
 * startet, wenn genau ein Projekt gewählt ist.
 *
 * Der Eintrag hängt an **Konto und Projekt**, nicht an der Suche — deshalb die
 * eigene Tabelle (die Begründung steht in der Migration). Eine freigegebene
 * Suche kann damit für den einen der Einstieg sein und für den anderen nur ein
 * Eintrag in der Liste.
 *
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property int $saved_search_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'user_id',
    'project_id',
    'saved_search_id',
])]
class SavedSearchDefault extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<SavedSearch, $this>
     */
    public function savedSearch(): BelongsTo
    {
        return $this->belongsTo(SavedSearch::class);
    }
}
