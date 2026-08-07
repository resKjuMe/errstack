<?php

namespace App\Models;

use App\Enums\IngestType;
use Database\Factories\IngestPayloadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Eine angenommene, noch nicht ausgewertete Meldung.
 *
 * Der Rumpf bleibt genau so liegen, wie das SDK ihn geschickt hat (nur
 * entpackt) — die Auswertung kommt mit der Verarbeitungskette. Wird an einem
 * Verarbeitungsschritt später etwas geändert, lassen sich die Rohdaten erneut
 * durchlaufen; hätten wir nur das Ergebnis, wäre das nicht mehr möglich.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $project_key_id
 * @property string $event_id
 * @property IngestType $type
 * @property string|null $sdk
 * @property string $payload
 * @property int $size_bytes
 */
class IngestPayload extends Model
{
    /** @use HasFactory<IngestPayloadFactory> */
    use HasFactory;

    /**
     * Nimmt eine Meldung in die Ablage auf.
     *
     * Benannter Konstruktor wie bei Projekt und Schlüssel: der Aufrufer soll
     * nicht wissen müssen, welche Felder zusammen gesetzt werden müssen, und
     * die Angaben kommen hier ausschließlich von der Aufnahme — nichts davon
     * ist vom Client frei füllbar. Deshalb hat das Model auch kein `Fillable`.
     */
    public static function accept(
        ProjectKey $key,
        string $eventId,
        string $payload,
        IngestType $type = IngestType::Event,
        ?string $sdk = null,
    ): self {
        $entry = new self;

        $entry->project_id = $key->project_id;
        $entry->project_key_id = $key->id;
        $entry->event_id = $eventId;
        $entry->type = $type;
        $entry->sdk = $sdk;
        $entry->payload = $payload;
        $entry->size_bytes = strlen($payload);
        $entry->save();

        return $entry;
    }

    /**
     * Die Nummer einer Meldung in der Form, in der Sentry sie führt: 32
     * Hex-Zeichen in Kleinschreibung, ohne Bindestriche.
     *
     * SDKs schicken beides — mit und ohne Bindestriche —, und einzelne setzen
     * Großbuchstaben. Ohne diese Vereinheitlichung gälte dieselbe Meldung bei
     * der Doppelerkennung als zwei verschiedene.
     */
    public static function normalizeEventId(mixed $eventId): ?string
    {
        if (! is_string($eventId)) {
            return null;
        }

        $normalized = strtolower(str_replace('-', '', trim($eventId)));

        return preg_match('/^[0-9a-f]{32}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * Neue Nummer für eine Meldung, die keine mitgebracht hat. Sentry vergibt
     * in dem Fall ebenfalls eine und gibt sie zurück — sonst hätte der Absender
     * keine Kennung, unter der er seine Meldung wiederfindet.
     */
    public static function freshEventId(): string
    {
        return str_replace('-', '', Str::uuid()->toString());
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ProjectKey, $this>
     */
    public function key(): BelongsTo
    {
        return $this->belongsTo(ProjectKey::class, 'project_key_id');
    }

    /**
     * Der Rumpf als Feld-Baum. Bewusst kein `casts`-Eintrag auf `array`: die
     * Spalte ist die unveränderte Kopie des Eingangs, und ein Cast würde sie
     * beim Speichern neu formatieren.
     *
     * @return array<mixed>|null
     */
    public function decoded(): ?array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IngestType::class,
            'size_bytes' => 'integer',
        ];
    }
}
