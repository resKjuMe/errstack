<?php

namespace App\Models;

use App\Enums\NotificationEventType;
use App\Notifications\NotificationMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Meldung, die auf ihre Nachbarn wartet.
 *
 * Sie ist der einzige Zustand der Bündelung: es gibt keine „offene
 * Sammelnachricht", die irgendwo mitgeführt und fortgeschrieben würde, sondern
 * nur diese Zeilen. Wer wissen will, was gerade wartet, zählt sie — und wer
 * eine Sammelnachricht baut, nimmt sie und löscht sie hinterher.
 *
 * Der Grund für diesen Schnitt ist der Ausfall: bricht der Durchlauf mitten
 * hinein ab, liegen die Meldungen noch da und gehen beim nächsten Mal hinaus.
 * Ein mitgeführter Zwischenstand wäre dann halb geschrieben.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $project_id
 * @property int|null $organization_id
 * @property NotificationEventType $event_type
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $created_at
 */
class NotificationDigestEntry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'project_id',
        'organization_id',
        'event_type',
        'payload',
    ];

    /**
     * Die Meldung, wie sie hineingegangen ist. Sie überlebt hier auch das
     * Aufräumen dessen, worüber sie berichtet — deshalb liegt sie als Nutzlast
     * da und nicht als Verweis.
     */
    public function message(): NotificationMessage
    {
        return NotificationMessage::fromArray($this->payload);
    }

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
            'event_type' => NotificationEventType::class,
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
