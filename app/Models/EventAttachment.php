<?php

namespace App\Models;

use App\Enums\AttachmentKind;
use App\Support\Attachments\AttachmentStore;
use App\Support\Issues\EventNavigation;
use Carbon\CarbonImmutable;
use Database\Factories\EventAttachmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Eine Datei zu einer Meldung: Screenshot, Logdatei, Speicherabbild.
 *
 * Der Inhalt liegt auf einem Laufwerk, hier steht der Verweis darauf; gelesen
 * und gelöscht wird er über {@see AttachmentStore}. Was dieses Modell weiß, ist
 * die **Zuordnung** — zu welcher Meldung der Anhang gehört ({@see forEvent()})
 * — und was aus dem gemeldeten Inhaltstyp folgt ({@see kindFor()}).
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $ingest_payload_id
 * @property string $event_reference
 * @property string $name
 * @property string|null $content_type
 * @property AttachmentKind $kind
 * @property int $size
 * @property string $checksum
 * @property string $path
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Project|null $project
 */
class EventAttachment extends Model
{
    /** @use HasFactory<EventAttachmentFactory> */
    use HasFactory;

    /** Längster Dateiname (siehe Migration). */
    public const NAME_LIMIT = 255;

    /** Längster Inhaltstyp (siehe Migration). */
    public const CONTENT_TYPE_LIMIT = 100;

    /** Name für einen Anhang, dessen Element keinen mitgebracht hat. */
    public const FALLBACK_NAME = 'anhang.bin';

    /**
     * Die Anhänge einer Meldung, älteste zuerst.
     *
     * Gesucht wird über Projekt **und** Nummer, nicht über die Nummer allein:
     * die Nummer kommt vom Client, und die Anhänge eines fremden Projekts haben
     * an einer Meldung nichts zu suchen — auch wenn ein Zusammentreffen zweier
     * 32-Zeichen-Nummern praktisch nicht vorkommt. Die Reihenfolge ist die des
     * Eingangs: bei mehreren Dateien zu einem Absturz ist das die, in der das SDK
     * sie gesammelt hat.
     *
     * @return Builder<self>
     */
    public static function forEvent(Event $event): Builder
    {
        return self::query()
            ->where('project_id', $event->project_id)
            ->where('event_reference', $event->event_id)
            ->orderBy('id');
    }

    /**
     * Gehört dieser Anhang zu dieser Meldung?
     *
     * Beide Kennungen stehen in der Adresszeile, und eine vertauschte Zeile darf
     * keinen fremden Anhang unter fremder Meldung ausliefern — dieselbe Prüfung
     * wie {@see EventNavigation::belongsTo()} zwischen
     * Meldung und Fehler.
     */
    public function belongsToEvent(Event $event): bool
    {
        return $this->project_id === $event->project_id
            && $this->event_reference === $event->event_id;
    }

    /**
     * Was für eine Datei das ist — aus dem gemeldeten Inhaltstyp.
     *
     * Der Name entscheidet **nicht** mit. Eine Endung ist eine Gewohnheit und
     * keine Zusage: `absturz.png` kann ein Speicherabbild sein, und der Weg von
     * einer Endung zu „darf inline in den Browser" ist genau der, den man nicht
     * gehen will. Fehlt der Inhaltstyp, ist die Datei ein Download — das ist die
     * Antwort, die nie schadet.
     */
    public static function kindFor(?string $contentType): AttachmentKind
    {
        $type = self::normalizeContentType($contentType);

        if ($type === null) {
            return AttachmentKind::Binary;
        }

        /** @var list<string> $images */
        $images = (array) config('attachments.preview.image_types');

        if (in_array($type, $images, true)) {
            return AttachmentKind::Image;
        }

        /** @var list<string> $texts */
        $texts = (array) config('attachments.preview.text_types');

        return in_array($type, $texts, true) ? AttachmentKind::Text : AttachmentKind::Binary;
    }

    /**
     * Der Inhaltstyp ohne Beiwerk: kleingeschrieben und ohne Parameter.
     *
     * SDKs schicken `text/plain; charset=utf-8`, und der Vergleich mit einer
     * Aufzählung würde daran scheitern — mit dem Ergebnis, dass eine Logdatei als
     * Speicherabbild behandelt wird.
     */
    public static function normalizeContentType(?string $contentType): ?string
    {
        $type = strtolower(trim((string) $contentType));
        $type = trim((string) preg_replace('/;.*$/', '', $type));

        return $type === '' ? null : Str::limit($type, self::CONTENT_TYPE_LIMIT, '');
    }

    /**
     * Macht aus dem gemeldeten Dateinamen einen, der ausgeliefert werden darf.
     *
     * Der Name kommt aus dem Kopf eines Envelope-Elements und damit vom Client.
     * Er landet in der Anzeige **und** im `Content-Disposition` des Downloads;
     * Pfadanteile, Zeilenumbrüche und Anführungszeichen haben dort nichts zu
     * suchen. Bleibt nichts Brauchbares übrig, gilt {@see FALLBACK_NAME} — ein
     * Anhang ohne Namen ist immer noch ein Anhang.
     */
    public static function sanitizeName(?string $name): string
    {
        // Beide Trennzeichen: der Name kann von einem Windows-Client kommen, und
        // `basename()` kennt unter Linux nur den Schrägstrich.
        $name = basename(str_replace('\\', '/', trim((string) $name)));

        // Steuerzeichen und Anführungszeichen weg, bevor der Name in einen
        // Kopfzeilenwert geschrieben wird.
        $name = (string) preg_replace('/[\x00-\x1f\x7f"]+/', '', $name);
        $name = trim($name, ' .');

        return $name === '' ? self::FALLBACK_NAME : Str::limit($name, self::NAME_LIMIT, '');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<IngestPayload, $this>
     */
    public function payload(): BelongsTo
    {
        return $this->belongsTo(IngestPayload::class, 'ingest_payload_id');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'ingest_payload_id',
        'event_reference',
        'name',
        'content_type',
        'kind',
        'size',
        'checksum',
        'path',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
            'size' => 'integer',
            'received_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
