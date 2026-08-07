<?php

namespace App\Models;

use App\Enums\ApiScope;
use App\Enums\ApiTokenKind;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Ein API-Token. Erweitert das Token-Modell von Sanctum um die Organisation, für
 * die es gilt, und um den Aussteller.
 *
 * Der Klartext-Wert wird nie gespeichert — in der Tabelle steht nur sein
 * SHA-256-Abdruck. Deshalb kann ihn auch niemand nachträglich anzeigen: er ist
 * genau einmal zu sehen, direkt nach dem Anlegen (siehe issue()).
 *
 * @property int $id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property int $organization_id
 * @property int|null $created_by_id
 * @property string $name
 * @property list<string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 */
class ApiToken extends PersonalAccessToken
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'created_by_id',
        'name',
        'token',
        'abilities',
        'expires_at',
    ];

    /**
     * Stellt ein Token aus und gibt es samt Klartext-Wert zurück. Bewusst hier
     * statt über Sanctums createToken(): das kennt `organization_id` und
     * `created_by_id` nicht und würde ein Token ohne Organisation anlegen.
     *
     * @param  User|Organization  $tokenable  Nutzer für ein persönliches, Organisation für ein organisationsweites Token
     * @param  list<ApiScope>  $scopes
     */
    public static function issue(
        User|Organization $tokenable,
        Organization $organization,
        ?User $createdBy,
        string $name,
        array $scopes,
        ?DateTimeInterface $expiresAt = null,
    ): NewAccessToken {
        $plainText = $tokenable->generateTokenString();

        /** @var self $token */
        $token = $tokenable->tokens()->create([
            'organization_id' => $organization->id,
            'created_by_id' => $createdBy?->id,
            'name' => $name,
            'token' => hash('sha256', $plainText),
            'abilities' => array_map(fn (ApiScope $scope): string => $scope->value, $scopes),
            'expires_at' => $expiresAt,
        ]);

        // Der Wert, den der Aufrufer als `Authorization: Bearer …` schickt. Die
        // laufende Nummer vorneweg erspart Sanctum die Suche über die ganze
        // Tabelle.
        return new NewAccessToken($token, $token->getKey().'|'.$plainText);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function kind(): ApiTokenKind
    {
        return $this->tokenable_type === (new Organization)->getMorphClass()
            ? ApiTokenKind::Organization
            : ApiTokenKind::Personal;
    }

    /**
     * Die Geltungsbereiche als Aufzählungswerte. Namen, die es nicht (mehr)
     * gibt, werden übergangen statt zu einem Fehler zu führen — ein alter Token
     * mit einem entfernten Bereich soll die Liste nicht sprengen.
     *
     * @return list<ApiScope>
     */
    public function scopes(): array
    {
        return array_values(array_filter(array_map(
            fn (string $ability): ?ApiScope => ApiScope::tryFrom($ability),
            array_filter($this->abilities ?? [], 'is_string'),
        )));
    }

    /**
     * Deckt dieses Token den verlangten Geltungsbereich ab?
     *
     * Gegenüber Sanctum kommt die Rangfolge innerhalb einer Ressource hinzu:
     * `project:write` deckt `project:read` mit ab, damit nicht jeder Aufrufer
     * beide Bereiche einzeln anfordern muss.
     *
     * @param  string  $ability
     */
    public function can($ability): bool
    {
        if (in_array('*', $this->abilities ?? [], true)) {
            return true;
        }

        $needed = ApiScope::tryFrom($ability);

        if ($needed === null) {
            // Unbekannter Name: nur die wortgleiche Angabe zählt. Sonst würde
            // ein Tippfehler in einer Route stillschweigend durchgehen.
            return in_array($ability, $this->abilities ?? [], true);
        }

        foreach ($this->scopes() as $granted) {
            if ($granted->covers($needed)) {
                return true;
            }
        }

        return false;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
