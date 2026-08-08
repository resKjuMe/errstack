<?php

namespace App\Models;

use Database\Factories\EnvironmentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Eine Umgebung eines Projekts. Einträge entstehen von selbst, sobald eine
 * Meldung mit einer bisher unbekannten Umgebung eintrifft (`record()`); von Hand
 * lässt sich nur bestimmen, ob eine Umgebung in der Filterleiste erscheint.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property bool $is_hidden
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory;

    /**
     * Längstmöglicher Name (siehe Migration). Längere Angaben werden gekürzt,
     * nicht abgewiesen: eine ungewöhnlich benannte Umgebung soll ihre Meldung
     * nicht verlieren.
     */
    public const NAME_LIMIT = 64;

    /**
     * Erfasst die Umgebung einer eingehenden Meldung und gibt sie zurück. Beim
     * ersten Auftreten entsteht der Eintrag, danach wird nur der Zeitpunkt des
     * letzten Auftretens nachgezogen.
     *
     * Ein leerer oder fehlender Name ist der Regelfall bei SDKs ohne
     * Umgebungs-Angabe — dann gilt die Standard-Umgebung des Projekts.
     */
    public static function record(Project $project, ?string $name = null, ?DateTimeInterface $seenAt = null): self
    {
        $name = self::normalizeName($name) ?? self::normalizeName($project->default_environment) ?? 'production';
        $seenAt = $seenAt === null ? Carbon::now() : Carbon::parse($seenAt);

        // Bewusst ohne Massenzuweisung: Umgebungen füllt kein Formular, sondern
        // nur diese Stelle — es gibt deshalb keine `fillable`-Liste, die man
        // versehentlich zu weit fassen könnte.
        $environment = self::query()
            ->where('project_id', $project->id)
            ->where('name', $name)
            ->first();

        if ($environment === null) {
            $environment = new self;
            $environment->project_id = $project->id;
            $environment->name = $name;
            $environment->first_seen_at = $seenAt;
            $environment->last_seen_at = $seenAt;
            $environment->save();

            return $environment;
        }

        // Nur vorwärts: Meldungen können verspätet eintreffen, und dann darf ein
        // alter Zeitstempel den jüngsten nicht überschreiben.
        if ($environment->last_seen_at === null || $environment->last_seen_at->lessThan($seenAt)) {
            $environment->last_seen_at = $seenAt;
            $environment->save();
        }

        return $environment;
    }

    /**
     * Findet die Umgebung eines Projekts oder legt sie an — ohne die Zeitpunkte
     * anzufassen.
     *
     * Der Weg für alles, was **keine** Meldung ist: eine Auslieferung (R3)
     * bringt eine Umgebung mit, aber sie hat dort nichts „gesehen".
     * {@see record()} zu benutzen würde `last_seen_at` auf den Zeitpunkt des
     * Deploys setzen, und die Angabe hieße danach nicht mehr „von dort kam
     * zuletzt eine Meldung" — eine Umgebung, aus der seit Wochen nichts kommt,
     * sähe nach einem Deploy taufrisch aus.
     *
     * Ohne Namen gilt die Standard-Umgebung des Projekts, wie beim Aufnehmen.
     */
    public static function forName(Project $project, ?string $name = null): self
    {
        $name = self::normalizeName($name) ?? self::normalizeName($project->default_environment) ?? 'production';

        $existing = self::query()
            ->where('project_id', $project->id)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            // Ohne Massenzuweisung wie in {@see record()} — es gibt aus
            // demselben Grund keine `fillable`-Liste.
            $environment = new self;
            $environment->project_id = $project->id;
            $environment->name = $name;
            $environment->save();

            return $environment;
        } catch (UniqueConstraintViolationException) {
            // Zwei Auslieferungen derselben Umgebung im selben Augenblick: der
            // eindeutige Index entscheidet, wer sie anlegt, und der andere
            // bekommt die Zeile des Gewinners. Ein `exists()` davor wäre nur
            // eine Momentaufnahme.
            return self::query()
                ->where('project_id', $project->id)
                ->where('name', $name)
                ->firstOrFail();
        }
    }

    /**
     * Vereinheitlicht einen gemeldeten Umgebungs-Namen, damit „production" und
     * „ production " nicht zwei Umgebungen ergeben. Bleibt nichts übrig, ist das
     * Ergebnis null und der Aufrufer entscheidet über den Ersatz.
     */
    public static function normalizeName(?string $name): ?string
    {
        $name = Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''), self::NAME_LIMIT, '');

        return $name === '' ? null : $name;
    }

    /**
     * Nur die Umgebungen, die in der Filterleiste erscheinen sollen.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('is_hidden', false);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_hidden' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
