<?php

namespace App\Models;

use App\Enums\SpanStatus;
use App\Support\Ingest\Processing\Steps\RecordTransaction;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine gemessene Antwortzeit: ein Seitenaufruf, ein Endpunkt, eine
 * Hintergrundaufgabe — mit ihren Einzelschritten.
 *
 * Eine Transaktion ist **kein Fehler**. Sie entsteht bei jedem erfolgreichen
 * Aufruf, ist entsprechend zahlreich und wird anders gelesen: nicht als
 * Einzelfall, sondern als Verteilung über einen Zeitraum. In einer Fehlerliste
 * hat sie deshalb nichts zu suchen, und sie kann dort auch nicht auftauchen — sie
 * liegt in eigenen Tabellen und wird von einem eigenen Verarbeitungsschritt
 * geschrieben ({@see RecordTransaction}).
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $ingest_payload_id
 * @property string $event_id
 * @property string $trace_id
 * @property string $span_id
 * @property string|null $parent_span_id
 * @property string $name
 * @property string|null $op
 * @property string|null $source
 * @property string|null $status
 * @property string|null $platform
 * @property string $environment
 * @property string|null $release
 * @property string|null $user_identifier
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $finished_at
 * @property int $duration_us
 * @property int $span_count
 * @property array<string, mixed>|null $measurements
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * Zeitpunkte mit Millisekunden ablegen.
     *
     * Ohne diese Angabe schreibt Eloquent `Y-m-d H:i:s` und schneidet die
     * Bruchteile ab — bei Aufrufen, die 40 ms brauchen, wäre die Reihenfolge
     * innerhalb eines Traces damit verloren, und alle Schritte einer
     * Transaktion begännen zur selben Zeit.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Längstmöglicher Transaktionsname (siehe Migration). Länger benannte werden
     * gekürzt, nicht verworfen.
     */
    public const NAME_LIMIT = 200;

    /**
     * Längstmögliche Operation (`http.server`, `db.sql.query`, …).
     */
    public const OP_LIMIT = 100;

    /**
     * Die Breite eines Zeitfensters der Vorberechnung, in Sekunden.
     *
     * Eine Minute: fein genug, dass ein Ausschlag von wenigen Minuten sichtbar
     * bleibt, und grob genug, dass ein Transaktionsname an einem Tag 1440 Zeilen
     * ergibt. Längere Zeiträume entstehen durch Summieren dieser Fenster, nicht
     * durch eine zweite Auflösung.
     */
    public const BUCKET_SECONDS = 60;

    /**
     * Die Einzelschritte dieser Transaktion.
     *
     * Der Baum wird nicht über diese Beziehung abgebildet, sondern über
     * `parent_span_id`: ein `hasMany` je Ebene wäre eine Abfrage je Ebene, und
     * die Tiefe ist nicht vorhersagbar. Geladen werden alle Schritte auf einmal,
     * verschachtelt wird in der Anzeige (PF4).
     *
     * @return HasMany<TransactionSpan, $this>
     */
    public function spans(): HasMany
    {
        return $this->hasMany(TransactionSpan::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Die Rohdaten, aus denen die Messung entstand — sofern sie noch da sind
     * (das Aufräumen alter Eingänge greift früher als die Aufbewahrung der
     * Auswertung).
     *
     * @return BelongsTo<IngestPayload, $this>
     */
    public function payload(): BelongsTo
    {
        return $this->belongsTo(IngestPayload::class, 'ingest_payload_id');
    }

    /**
     * Zählt diese Transaktion in die Fehlerrate?
     */
    public function failed(): bool
    {
        return SpanStatus::isFailureValue($this->status);
    }

    /**
     * Der Anfang des Zeitfensters, in dem diese Transaktion gezählt wird.
     */
    public function window(): CarbonImmutable
    {
        return self::windowFor($this->started_at);
    }

    /**
     * Der Anfang des Zeitfensters zu einem Zeitpunkt.
     *
     * Abgeschnitten und nicht gerundet: sonst fiele eine Messung um 10:00:31 in
     * das Fenster 10:01, das noch gar nicht begonnen hat.
     *
     * Gerechnet aus {@see BUCKET_SECONDS} und nicht mit `startOfMinute()`: sonst
     * gäbe es die Fensterbreite zweimal — als Konstante und als Methodenname —
     * und eine Änderung der einen ließe die andere stehen.
     */
    public static function windowFor(CarbonImmutable $at): CarbonImmutable
    {
        $timestamp = $at->utc()->getTimestamp();

        return CarbonImmutable::createFromTimestamp($timestamp - $timestamp % self::BUCKET_SECONDS)->utc();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `immutable_datetime`, weil an einem gemessenen Zeitpunkt nichts zu
            // verschieben ist — und weil ein versehentliches `->addHour()` auf
            // einer geteilten Instanz sonst die Messung selbst verändert.
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_us' => 'integer',
            'span_count' => 'integer',
            'measurements' => 'array',
        ];
    }
}
