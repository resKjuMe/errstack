<?php

namespace App\Models;

use App\Support\Filters\RememberedFilter;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * „Womit ging es zuletzt weiter": der Stand der globalen Filterleiste, den ein
 * Konto in einer Organisation zuletzt benutzt hat.
 *
 * Gelesen und geschrieben wird er ausschließlich über
 * {@see RememberedFilter} — dort steht auch, wann er gilt und wann die
 * Adresszeile ihn übergeht. Die Begründung für die eigene Tabelle steht in der
 * Migration.
 *
 * @property int $id
 * @property int $user_id
 * @property int $organization_id
 * @property list<string> $projects
 * @property string|null $environment
 * @property string $period
 * @property Carbon|null $custom_from
 * @property Carbon|null $custom_to
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'user_id',
    'organization_id',
    'projects',
    'environment',
    'period',
    'custom_from',
    'custom_to',
])]
class FilterPreference extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'projects' => 'array',
            'custom_from' => 'date',
            'custom_to' => 'date',
        ];
    }
}
