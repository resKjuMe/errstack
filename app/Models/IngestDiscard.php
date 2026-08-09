<?php

namespace App\Models;

use App\Enums\DiscardOrigin;
use App\Enums\DiscardReason;
use App\Support\Ingest\Spikes\SpikeSweep;
use Database\Factories\IngestDiscardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ein Zähler dafür, was **nicht** angekommen ist — je Projekt, Schlüssel,
 * Grund, Kategorie und Stunde.
 *
 * Verworfenes hinterlässt sonst keine Spur: Was wir wegen eines unbekannten
 * Typs nicht ablegen, und was ein SDK schon bei sich weggeworfen hat, taucht in
 * `ingest_payloads` nie auf. Ohne diese Zählung wäre die Antwort auf „warum
 * fehlen Meldungen?" nicht zu geben — und genau das ist die Frage, die man an
 * ein Fehler-Werkzeug stellt, wenn man ihm nicht mehr traut.
 *
 * Gezählt statt einzeln abgelegt: eine Fehlerflut soll die Ablage nicht
 * volllaufen lassen, und für die Auskunft genügt die Anzahl je Stunde. Die
 * Auswertung — Verlauf, Anteile, Warnung bei Auffälligkeiten — gehört zur
 * Nutzungsstatistik (O3); hier steht nur das Mitschreiben.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $project_key_id
 * @property DiscardOrigin $origin
 * @property string $reason
 * @property string|null $category
 * @property Carbon $bucket
 * @property int $quantity
 */
class IngestDiscard extends Model
{
    /** @use HasFactory<IngestDiscardFactory> */
    use HasFactory;

    /**
     * Schreibt eine Verwerfung mit, die wir selbst vorgenommen haben.
     */
    public static function server(
        ProjectKey $key,
        DiscardReason $reason,
        ?string $category = null,
        int $quantity = 1,
    ): void {
        self::record($key->project_id, $key->id, DiscardOrigin::Server, $reason->value, $category, $quantity);
    }

    /**
     * Schreibt eine Verwerfung mit, die das ganze Projekt betraf — ohne
     * Schlüssel.
     *
     * Der Fall ist die Drosselung des Ausschlag-Schutzes (A7): sie ist eine
     * Entscheidung über das Projekt und nicht über einen Schlüssel, und sie
     * wird nicht Meldung für Meldung verbucht, sondern gesammelt je Minute
     * ({@see SpikeSweep}) — bei einer Flut wäre die
     * Zählung sonst teurer als das Ablegen, gegen das sie schützt. Zu diesem
     * Zeitpunkt lässt sich nicht mehr sagen, über welchen Schlüssel die
     * einzelnen Meldungen hereinkamen; einen davon einzutragen wäre eine
     * erfundene Angabe.
     */
    public static function forProject(
        Project $project,
        DiscardReason $reason,
        ?string $category = null,
        int $quantity = 1,
    ): void {
        self::record($project->id, null, DiscardOrigin::Server, $reason->value, $category, $quantity);
    }

    /**
     * Schreibt mit, was ein SDK selbst verworfen hat.
     *
     * Grund und Kategorie kommen unverändert aus dessen Meldung; wir kennen die
     * Bezeichnungen nicht alle und sollen sie auch nicht kennen müssen. Beides
     * wird nur auf ein unverfängliches Format gebracht — es landet in
     * Auswertungen und Protokollzeilen.
     */
    public static function client(
        ProjectKey $key,
        string $reason,
        ?string $category,
        int $quantity,
    ): void {
        $reason = self::sanitize($reason, 48);

        if ($reason === null || $quantity < 1) {
            return;
        }

        self::record($key->project_id, $key->id, DiscardOrigin::Client, $reason, self::sanitize($category, 32), $quantity);
    }

    /**
     * Erhöht den Zähler der laufenden Stunde, oder legt ihn an.
     *
     * Absichtlich ohne eindeutigen Index über die Merkmalsspalten: zwei
     * gleichzeitige Anfragen könnten sonst kollidieren und eine Meldung ginge
     * verloren, nur weil ein Zähler nicht hochgezählt werden konnte. Entstehen
     * bei einem Wettlauf zwei Zeilen für dieselbe Stunde, ist das harmlos —
     * die Auswertung summiert ohnehin.
     */
    private static function record(
        int $projectId,
        ?int $keyId,
        DiscardOrigin $origin,
        string $reason,
        ?string $category,
        int $quantity,
    ): void {
        if ($quantity < 1) {
            return;
        }

        $bucket = self::bucket();

        $existing = self::query()
            ->where('project_id', $projectId)
            ->where('origin', $origin->value)
            ->where('reason', $reason)
            ->where('bucket', $bucket)
            // Getrennt, weil `where('spalte', null)` zu `spalte = null` wird
            // und das nie zutrifft.
            ->when($keyId === null, fn ($query) => $query->whereNull('project_key_id'))
            ->when($keyId !== null, fn ($query) => $query->where('project_key_id', $keyId))
            ->when($category === null, fn ($query) => $query->whereNull('category'))
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->first();

        if ($existing !== null) {
            $existing->increment('quantity', $quantity);

            return;
        }

        $entry = new self;

        $entry->project_id = $projectId;
        $entry->project_key_id = $keyId;
        $entry->origin = $origin;
        $entry->reason = $reason;
        $entry->category = $category;
        $entry->bucket = $bucket;
        $entry->quantity = $quantity;
        $entry->save();
    }

    /**
     * Die Stunde, in der gezählt wird. Feiner wäre für die Auskunft ohne
     * Nutzen und würde die Tabelle unnötig füllen.
     */
    public static function bucket(): Carbon
    {
        return Carbon::now()->startOfHour();
    }

    /**
     * Kürzt eine vom Client gelieferte Bezeichnung auf das, was als Merkmal
     * taugt: Kleinbuchstaben, Ziffern, Unterstrich, Bindestrich, Punkt.
     */
    private static function sanitize(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^a-z0-9_.-]/', '', strtolower(trim($value))) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, $limit);
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => DiscardOrigin::class,
            'bucket' => 'datetime',
            'quantity' => 'integer',
        ];
    }
}
