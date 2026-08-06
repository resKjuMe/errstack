<?php

namespace App\Models;

use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein eingerichteter Benachrichtigungsweg einer Organisation: welcher Kanal
 * (`type`), unter welchem Namen und mit welchen Zugangsdaten (`config`).
 *
 * Was in `config` steht, weiß nur der Treiber des jeweiligen Kanals — das
 * Modell behandelt es als undurchsichtige Ablage und verschlüsselt sie, weil
 * dort Webhook-URLs und Token liegen.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $type
 * @property string $name
 * @property array<string, mixed> $config
 * @property bool $is_active
 */
#[Fillable(['type', 'name', 'config', 'is_active'])]
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    /**
     * Einzelner Wert aus der Kanal-Konfiguration.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }
}
