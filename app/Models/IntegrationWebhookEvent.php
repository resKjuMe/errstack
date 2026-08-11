<?php

namespace App\Models;

use App\Enums\IntegrationProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Eine eingegangene Meldung des Anbieters (X1) — das Eingangsbuch.
 *
 * Es ist der Grund, dass die Verarbeitung **wiederholbar** ist, ohne dass jeder
 * einzelne Verarbeitungsschritt es für sich sein müsste: GitHub stellt dieselbe
 * Meldung erneut zu, wenn die Antwort ausbleibt, und wiederholt sie auf
 * Knopfdruck. Der eindeutige Index über `(provider, delivery_id)` entscheidet,
 * wer die erste ist; alle weiteren finden ihre Zeile vor und tun nichts.
 *
 * Die rohe Nutzlast bleibt stehen. Nicht aus Sammelwut: sie ist beim Einrichten
 * die einzige Stelle, an der sich „es kommt nichts an" von „es kommt an und
 * passt zu nichts" unterscheiden lässt — und genau diese beiden Fälle sehen in
 * der Oberfläche sonst gleich aus.
 *
 * @property int $id
 * @property IntegrationProvider $provider
 * @property int|null $organization_id
 * @property string $delivery_id
 * @property string $event
 * @property string|null $action
 * @property string|null $repository
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 * @property string|null $result
 * @property CarbonImmutable|null $created_at
 */
class IntegrationWebhookEvent extends Model
{
    /**
     * Länge eines gespeicherten Ergebnisses (siehe Migration).
     */
    public const RESULT_LIMIT = 200;

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Hält fest, was die Verarbeitung ergeben hat.
     *
     * Auch — und gerade — wenn nichts dabei herauskam: „kein verknüpfter
     * Fehler" ist im Betrieb der häufigste Ausgang und die Antwort auf die
     * Frage, warum sich nichts getan hat.
     */
    public function markProcessed(string $result): void
    {
        $this->forceFill([
            'processed_at' => CarbonImmutable::now(),
            'result' => Str::limit($result, self::RESULT_LIMIT, ''),
        ])->save();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'organization_id',
        'delivery_id',
        'event',
        'action',
        'repository',
        'payload',
    ];

    /**
     * Kein `updated_at`: eine eingegangene Meldung ändert sich nicht. Was sich
     * ändert, ist der Vermerk über ihre Verarbeitung, und der hat seine eigene
     * Spalte.
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => IntegrationProvider::class,
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
