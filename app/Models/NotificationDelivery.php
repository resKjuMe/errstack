<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Notifications\DeliveryResult;
use App\Notifications\NotificationMessage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ein Zustellversuch an genau einen Kanal — und zugleich das Protokoll dazu.
 *
 * Der Datensatz entsteht beim Auslösen der Benachrichtigung (Status
 * `Pending`), der Worker schreibt Versuche, Antwortcode und Fehler fort. Weil
 * die Nutzlast mitgespeichert wird, lässt sich ein fehlgeschlagener Versuch
 * später unverändert wiederholen.
 *
 * @property int $id
 * @property int $notification_channel_id
 * @property string $subject
 * @property string|null $reference Kennung der Meldung — dieselbe über alle Meldungen eines Alarms
 * @property array<string, mixed> $payload
 * @property DeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $error
 * @property bool $is_test
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $delivered_at
 * @property Carbon $created_at
 */
#[Fillable(['subject', 'reference', 'payload', 'status', 'is_test'])]
class NotificationDelivery extends Model
{
    /**
     * @return BelongsTo<NotificationChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    /**
     * Die gespeicherte Nutzlast zurück als Nachricht — Grundlage jedes
     * Versuchs, auch des wiederholten.
     */
    public function message(): NotificationMessage
    {
        return NotificationMessage::fromArray($this->payload)->forDelivery($this->id);
    }

    /**
     * Hält das Ergebnis eines Versuchs fest. `attempts` zählt dabei die
     * tatsächlichen Versuche, nicht die Zustellungen.
     */
    public function recordAttempt(DeliveryResult $result): void
    {
        $this->attempts++;
        $this->last_attempt_at = now();
        $this->response_code = $result->responseCode;
        $this->error = $result->error === null ? null : Str::limit($result->error, 2000);

        if ($result->ok) {
            $this->status = DeliveryStatus::Sent;
            $this->delivered_at = now();
        }

        $this->save();
    }

    /**
     * Endgültiger Fehlschlag: alle Versuche der Warteschlange sind verbraucht.
     * Erst hier wird der Status hart auf `Failed` gesetzt — bis dahin bleibt
     * die Zustellung „unterwegs", weil ein weiterer Versuch folgt.
     */
    public function markFailed(string $error): void
    {
        $this->status = DeliveryStatus::Failed;
        $this->error = Str::limit($error, 2000);
        $this->last_attempt_at = now();
        $this->save();
    }

    /**
     * Setzt einen fehlgeschlagenen Versuch zurück, damit er erneut in die
     * Warteschlange darf. Die Zählung der Versuche bleibt stehen — sie ist die
     * Geschichte dieser Zustellung.
     */
    public function markPending(): void
    {
        $this->status = DeliveryStatus::Pending;
        $this->error = null;
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DeliveryStatus::class,
            'is_test' => 'boolean',
            'last_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
