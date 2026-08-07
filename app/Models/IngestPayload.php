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
 * @property array<string, mixed>|null $item_headers
 * @property string|null $sdk
 * @property string $payload
 * @property string|null $payload_encoding
 * @property int $size_bytes
 */
class IngestPayload extends Model
{
    /** @use HasFactory<IngestPayloadFactory> */
    use HasFactory;

    /**
     * Kennzeichen für Nutzdaten, die verpackt in der Spalte liegen, weil sie
     * kein Text sind ({@see storable()}).
     */
    public const ENCODING_BASE64 = 'base64';

    /**
     * Nimmt eine Meldung in die Ablage auf.
     *
     * Benannter Konstruktor wie bei Projekt und Schlüssel: der Aufrufer soll
     * nicht wissen müssen, welche Felder zusammen gesetzt werden müssen, und
     * die Angaben kommen hier ausschließlich von der Aufnahme — nichts davon
     * ist vom Client frei füllbar. Deshalb hat das Model auch kein `Fillable`.
     *
     * @param  array<string, mixed>|null  $itemHeaders  Kopf des Envelope-Elements,
     *                                                  bei `/store/` nicht vorhanden.
     */
    public static function accept(
        ProjectKey $key,
        string $eventId,
        string $payload,
        IngestType $type = IngestType::Event,
        ?string $sdk = null,
        ?array $itemHeaders = null,
    ): self {
        $entry = new self;

        $entry->project_id = $key->project_id;
        $entry->project_key_id = $key->id;
        $entry->event_id = $eventId;
        $entry->type = $type;
        $entry->item_headers = $itemHeaders;
        $entry->sdk = $sdk;
        $entry->size_bytes = strlen($payload);

        // Die Größe ist die der **Nutzdaten**, nicht die der Spalte: sie wird
        // oben aus dem Original genommen, bevor eine mögliche Verpackung
        // hinzukommt. Sonst wäre jede Auswertung der Datenmenge um ein Drittel
        // zu hoch, sobald Anhänge im Spiel sind.
        [$entry->payload, $entry->payload_encoding] = self::storable($payload);

        $entry->save();

        return $entry;
    }

    /**
     * Bringt Nutzdaten in eine Form, die die Textspalte unbeschadet übersteht,
     * und sagt dazu, ob dabei etwas verpackt wurde.
     *
     * Der Regelfall ist JSON und bleibt unverändert — Zeichen für Zeichen, denn
     * darauf beruhen Signaturbildung und das Vorzeigen des Originals. Anhänge
     * und Aufzeichnungen sind dagegen beliebige Bytes: ein Screenshot ist kein
     * gültiges UTF-8, und ein Nullbyte beendet in manchen Treibern die
     * Zeichenkette. Beides wird deshalb Base64-verpackt.
     *
     * Entschieden wird am Inhalt, nicht am Typ: eine als Anhang gesendete
     * Logdatei bleibt so lesbar, und ein Element, das seinen Typ falsch
     * angibt, wird trotzdem heil abgelegt.
     *
     * @return array{string, string|null}
     */
    private static function storable(string $payload): array
    {
        $isText = $payload === ''
            || (mb_check_encoding($payload, 'UTF-8') && ! str_contains($payload, "\0"));

        return $isText ? [$payload, null] : [base64_encode($payload), self::ENCODING_BASE64];
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
     * Die Nutzdaten, wie das SDK sie geschickt hat — eine mögliche Verpackung
     * für die Textspalte wieder abgenommen.
     *
     * Alles, was mit den Daten arbeitet, nimmt diesen Weg und nicht die Spalte
     * `payload` direkt: sonst bekommt es bei einem Anhang die Base64-Zeichen
     * statt des Bildes.
     */
    public function bytes(): string
    {
        if ($this->payload_encoding !== self::ENCODING_BASE64) {
            return $this->payload;
        }

        $decoded = base64_decode($this->payload, true);

        return $decoded === false ? '' : $decoded;
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
        $decoded = json_decode($this->bytes(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Der Dateiname eines Anhangs, sofern das Element einen mitgebracht hat.
     */
    public function filename(): ?string
    {
        $filename = $this->item_headers['filename'] ?? null;

        return is_string($filename) && $filename !== '' ? $filename : null;
    }

    /**
     * Der Inhaltstyp des Elements, sofern angegeben.
     */
    public function contentType(): ?string
    {
        $contentType = $this->item_headers['content_type'] ?? null;

        return is_string($contentType) && $contentType !== '' ? $contentType : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => IngestType::class,
            // Der Kopf des Elements ist unsere eigene Ablage, keine Kopie des
            // Eingangs — hier darf umformatiert werden, anders als beim Rumpf.
            'item_headers' => 'array',
            'size_bytes' => 'integer',
        ];
    }
}
