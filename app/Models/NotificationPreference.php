<?php

namespace App\Models;

use App\Enums\NotificationEventType;
use App\Enums\NotificationTransport;
use App\Notifications\PreferenceScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine ausdrückliche Entscheidung eines Nutzers: „Anlass X über Weg Y — in
 * diesem Bereich an (oder aus)".
 *
 * Es gibt keine Zeile für „ich habe mich nicht entschieden". Fehlt der
 * Eintrag, greift der nächstgröbere Bereich und zuletzt die Vorgabe des
 * Anlasses.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $organization_id
 * @property int|null $project_id
 * @property string $scope_key
 * @property NotificationEventType $event_type
 * @property NotificationTransport $transport
 * @property bool $enabled
 */
// Auch die Zuordnungs-Felder sind befüllbar: `updateOrCreate` legt das Modell
// über `fill()` an und ließe geschützte Felder stillschweigend weg — die Zeile
// hätte dann weder Nutzer noch Bereich. Geschrieben wird ohnehin nur über die
// beiden benannten Methoden unten, nie aus einer Anfrage heraus.
#[Fillable(['user_id', 'organization_id', 'project_id', 'scope_key', 'event_type', 'transport', 'enabled'])]
class NotificationPreference extends Model
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Setzt eine Entscheidung oder ändert sie. Der Bereich landet zugleich als
     * Kennung und als Fremdschlüssel in der Zeile — die Kennung für den
     * eindeutigen Index, die Fremdschlüssel fürs Aufräumen.
     */
    public static function put(
        User $user,
        PreferenceScope $scope,
        NotificationEventType $event,
        NotificationTransport $transport,
        bool $enabled,
    ): self {
        /** @var self $preference */
        $preference = self::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'scope_key' => $scope->key(),
                'event_type' => $event,
                'transport' => $transport,
            ],
            [
                'organization_id' => $scope->organizationId,
                'project_id' => $scope->projectId,
                'enabled' => $enabled,
            ],
        );

        return $preference;
    }

    /**
     * Nimmt die ausdrückliche Entscheidung zurück; ab dann erbt der Bereich
     * wieder.
     */
    public static function forget(
        User $user,
        PreferenceScope $scope,
        NotificationEventType $event,
        NotificationTransport $transport,
    ): void {
        self::query()
            ->where('user_id', $user->id)
            ->where('scope_key', $scope->key())
            ->where('event_type', $event)
            ->where('transport', $transport)
            ->delete();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => NotificationEventType::class,
            'transport' => NotificationTransport::class,
            'enabled' => 'boolean',
        ];
    }
}
