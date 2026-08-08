<?php

namespace App\Models;

use App\Enums\OwnershipMatcher;
use App\Support\Ownership\Ownership;
use Database\Factories\OwnershipRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Zuständigkeits-Regel eines Projekts: „sieht der Fehler so aus, gehört er
 * diesen Leuten".
 *
 * Ausgewertet wird das nicht hier, sondern in {@see Ownership} — dieses Model
 * ist die Ablage, und die Auswertung soll ohne Datenbank prüfbar bleiben.
 * Dieselbe Trennung wie beim Eingangsfilter ({@see InboundFilterRule}).
 *
 * @property int $id
 * @property int $project_id
 * @property OwnershipMatcher $matcher
 * @property string|null $tag_key
 * @property string $pattern
 * @property list<string> $owners
 * @property string $source
 * @property int $position
 * @property bool $is_active
 * @property-read Project $project
 */
#[Fillable(['matcher', 'tag_key', 'pattern', 'owners', 'source', 'position', 'is_active'])]
class OwnershipRule extends Model
{
    /** @use HasFactory<OwnershipRuleFactory> */
    use HasFactory;

    /**
     * Wie viele Regeln ein Projekt haben darf.
     *
     * Großzügiger als bei den Stichproben und den Fingerabdrücken, und das ist
     * kein Versehen: eine CODEOWNERS-Datei mittlerer Größe hat dreistellig viele
     * Zeilen, und ein Import, der die Hälfte davon verschluckt, wäre schlimmer
     * als keiner — er sähe aus, als hätte er funktioniert. Der Preis ist
     * beherrschbar, weil diese Regeln anders als jene nicht an **jeder** Meldung
     * hängen, sondern nur am ersten Auftreten eines Fehlers.
     */
    public const MAX_PER_PROJECT = 250;

    /**
     * Wie viele Zuständige eine Regel benennen darf.
     *
     * Zehn sind mehr, als eine Zuständigkeit verträgt: wer eine Datei zwanzig
     * Leuten zuschreibt, hat keine Zuständigkeit festgelegt, sondern sie
     * aufgehoben. Die Grenze ist deshalb ein Hinweis und keine Speicherfrage.
     */
    public const MAX_OWNERS = 10;

    /** Längstmögliches Muster (siehe Migration). */
    public const PATTERN_LIMIT = 500;

    /** Von Hand angelegt. */
    public const SOURCE_MANUAL = 'manual';

    /** Aus einer CODEOWNERS-Datei übernommen. */
    public const SOURCE_CODEOWNERS = 'codeowners';

    /**
     * Die Reihenfolge, in der die Regeln gelten.
     *
     * `position` und danach `id`: zwei Regeln mit demselben Rang sind kein
     * Fehler (der Import vergibt fortlaufend, das Formular lässt jede Zahl zu),
     * und ohne den zweiten Schlüssel hinge das Ergebnis an der Laune der
     * Datenbank. Bei mehreren Treffern gewinnt die **letzte** — die Reihenfolge
     * ist deshalb die Aussage und nicht die Anzeige.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Wie die Regel in der Oberfläche und im Import geschrieben wird —
     * `path:src/billing/*`, `tag:server_name:web-*`.
     *
     * Eine Zeile und nicht drei Felder, weil man sie so auch liest: als Satz
     * über einen Ort. Sie steht hier und nicht in der Oberfläche, damit die
     * Vorschau und die Liste dieselbe Schreibweise zeigen.
     */
    public function expression(): string
    {
        return $this->matcher->value
            .':'
            .($this->tag_key === null ? '' : $this->tag_key.':')
            .$this->pattern;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'matcher' => OwnershipMatcher::class,
            'owners' => 'array',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
