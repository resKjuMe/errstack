<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use App\Enums\IntegrationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Der Zugang einer Organisation zu einem Anbieter (X1).
 *
 * Er ist das, was zwischen „ein Repository heißt acme/webshop" (R2) und
 * „hol dort die Commits" fehlt: ein Token, das einer Organisation gehört und
 * länger lebt als die Anfrage, in der es entstand.
 *
 * **Das Token steht verschlüsselt in `credentials` und wird nirgends
 * ausgegeben.** Weder die Oberfläche noch das Änderungsprotokoll noch eine
 * Fehlermeldung bekommen es zu sehen — was hinausgeht, ist der Kontoname, unter
 * dem verbunden wurde. Deshalb ist `credentials` auch nicht `fillable`: ein
 * Massen-Zuweisen aus einer Anfrage ist genau der Weg, auf dem so etwas
 * versehentlich hineingerät.
 *
 * @property int $id
 * @property int $organization_id
 * @property IntegrationProvider $provider
 * @property string|null $account
 * @property string|null $external_id
 * @property array<string, mixed>|null $credentials
 * @property IntegrationStatus $status
 * @property string|null $last_error
 * @property CarbonImmutable|null $last_error_at
 * @property CarbonImmutable|null $last_synced_at
 * @property int|null $connected_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory;

    /**
     * Länge einer gespeicherten Fehlermeldung (siehe Migration).
     */
    public const ERROR_LIMIT = 500;

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
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_id');
    }

    /**
     * Die Repositories, die über diese Anbindung hereingekommen sind.
     *
     * Nicht alle Repositories der Organisation: die von Hand eingetragenen
     * bleiben daneben bestehen, und sie sollen es auch — eine Bauumgebung ohne
     * Anbindung übergibt ihre Commits weiterhin selbst.
     *
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /**
     * Das Zugriffstoken — der einzige Weg daran.
     *
     * Als Methode und nicht als Eigenschaft, damit an jeder Aufrufstelle
     * sichtbar bleibt, dass hier ein Geheimnis geholt wird. Ein
     * `$integration->token` verschwindet in einer Ausgabe, ohne dass es
     * jemandem auffällt.
     */
    public function token(): ?string
    {
        $token = $this->credentials['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Ob die Anbindung benutzbar ist.
     *
     * Beides zusammen: ein Zugang ohne Token ist keiner, und einer, dessen
     * Token abgelehnt wurde, ist keiner mehr. Die Aufrufer prüfen damit **eine**
     * Bedingung statt zwei, die auseinanderlaufen können.
     */
    public function isUsable(): bool
    {
        return $this->status === IntegrationStatus::Connected && $this->token() !== null;
    }

    /**
     * Ein gelungener Aufruf.
     *
     * Er räumt die alte Fehlermeldung weg — sonst stünde auf der Seite noch
     * wochenlang, woran es einmal gescheitert ist, obwohl längst wieder alles
     * geht.
     */
    public function markSynced(): void
    {
        $this->forceFill([
            'status' => IntegrationStatus::Connected,
            'last_error' => null,
            'last_error_at' => null,
            'last_synced_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Der Zugang wurde abgelehnt.
     *
     * Nur für den abgelehnten Zugang gedacht, nicht für jeden Fehlschlag: ein
     * Netzfehler geht vorbei, ein zurückgezogenes Token nicht. Wer beides gleich
     * behandelt, macht aus einer kurzen Störung eine Anzeige, die jemand von
     * Hand wegklicken muss.
     */
    public function markDisconnected(string $reason): void
    {
        $this->forceFill([
            'status' => IntegrationStatus::Disconnected,
            'last_error' => Str::limit($reason, self::ERROR_LIMIT, ''),
            'last_error_at' => CarbonImmutable::now(),
        ])->save();
    }

    /**
     * Die Anbindung dieser Organisation an einen Anbieter — oder keine.
     */
    public static function forOrganization(
        Organization|int $organization,
        IntegrationProvider $provider = IntegrationProvider::GitHub,
    ): ?self {
        return self::query()
            ->where('organization_id', $organization instanceof Organization ? $organization->id : $organization)
            ->where('provider', $provider->value)
            ->first();
    }

    /**
     * `credentials` fehlt mit Absicht — siehe den Kommentar an der Klasse.
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'account',
        'external_id',
        'status',
        'connected_by_id',
    ];

    /**
     * Was nie in einer Ausgabe landen darf. Der Doppelschutz zum fehlenden
     * `fillable`: das eine hält es aus der Anfrage heraus, das andere aus der
     * Antwort.
     *
     * @var list<string>
     */
    protected $hidden = [
        'credentials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'status' => IntegrationStatus::class,
            'credentials' => 'encrypted:array',
            'last_error_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
