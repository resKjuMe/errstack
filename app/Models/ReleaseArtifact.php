<?php

namespace App\Models;

use App\Enums\ReleaseArtifactKind;
use App\Support\SourceMaps\ArtifactStore;
use App\Support\SourceMaps\SourceMap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ein hochgeladenes Bauartefakt einer Auslieferung — Bundle oder Quellkarte.
 *
 * Der Inhalt liegt auf einem Laufwerk, hier steht der Verweis darauf; gelesen
 * wird er über {@see ArtifactStore}. Was dieses Modell weiß, ist die
 * **Zuordnung**: unter welchem Pfad das SDK die Datei melden wird
 * ({@see matchName()}) und welche Namen dafür in Frage kommen
 * ({@see candidatesFor()}).
 *
 * @property int $id
 * @property int $project_id
 * @property int $release_id
 * @property string $name
 * @property ReleaseArtifactKind $kind
 * @property string|null $debug_id
 * @property string|null $source_map_ref
 * @property int $size
 * @property string $checksum
 * @property string $path
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Release $release
 */
class ReleaseArtifact extends Model
{
    /**
     * Längstmöglicher Artefakt-Pfad (siehe Migration).
     */
    public const NAME_LIMIT = 500;

    /**
     * Vereinheitlicht einen Artefakt-Pfad auf die Schreibweise, in der er
     * gesucht wird.
     *
     * Zwei Dinge passieren: die Leerzeichen fallen weg, und eine vollständige
     * Adresse wird zur Tilden-Form — `https://example.com/static/js/app.js` wird
     * `~/static/js/app.js`. Das ist die Schreibweise, mit der die Werkzeuge
     * hochladen, und sie ist der Grund, warum die Zuordnung überhaupt
     * funktioniert: dieselbe Datei kommt unter der eigenen Adresse und unter der
     * eines Auslieferungsnetzes daher, der Pfad dahinter bleibt derselbe.
     *
     * Die Abfrage (`?v=3`) und der Anker fallen mit weg. Sie gehören zur
     * Adresse, nicht zur Datei — und ein Bundle, das mit einem Zeitstempel in
     * der Abfrage geladen wird, wäre sonst bei jedem Aufruf ein anderes
     * Artefakt.
     */
    public static function normalizeName(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        // Nur wenn eine Adresse erkennbar ist. `parse_url` auf `~/app.js`
        // gäbe einen Pfad zurück, den es nicht anzufassen gibt.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $name) === 1) {
            $parts = parse_url($name);
            $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';

            $name = '~'.$path;
        } else {
            $name = (string) preg_replace('/[?#].*$/', '', $name);
        }

        $name = Str::limit($name, self::NAME_LIMIT, '');

        return $name === '' ? null : $name;
    }

    /**
     * Die Pfade, unter denen ein gemeldeter Rahmen abgelegt sein könnte — in
     * der Reihenfolge, in der sie gelten sollen.
     *
     * Der Rahmen nennt eine Adresse, das Artefakt einen Pfad, und zwischen
     * beiden liegt alles, was zwischen Bauvorgang und Browser passiert. Die
     * Liste geht deshalb von der genauesten Angabe zur ungenauesten:
     *
     *   1. die Adresse selbst — wer sie beim Hochladen so angegeben hat, meint
     *      genau diese Auslieferung;
     *   2. die Tilden-Form — die übliche Angabe, unabhängig vom Rechnernamen;
     *   3. der nackte Pfad;
     *   4. der Dateiname mit Tilde und
     *   5. der Dateiname allein — die letzte Rettung für ein Bundle, das aus
     *      einem Unterordner geladen wird, den es beim Bauen nicht gab.
     *
     * Die Reihenfolge ist die eigentliche Aussage: der Dateiname allein ist eine
     * begründete Vermutung und darf nie eine genauere Angabe überstimmen.
     *
     * @return list<string>
     */
    public static function candidatesFor(string $reported): array
    {
        $reported = trim($reported);

        if ($reported === '') {
            return [];
        }

        $candidates = [$reported];

        $isUrl = preg_match('#^[a-z][a-z0-9+.-]*://#i', $reported) === 1;
        $path = $reported;

        if ($isUrl) {
            $parts = parse_url($reported);
            $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        } else {
            $path = (string) preg_replace('/[?#].*$/', '', $path);
        }

        // Eine schon in Tilden-Form gemeldete Angabe („~/app.js") ist kein
        // Pfad, dem noch eine Tilde vorangestellt werden müsste.
        $path = preg_replace('#^~#', '', $path) ?? $path;

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $base = basename($path);

        foreach (['~'.$path, $path, '~/'.$base, $base] as $candidate) {
            if ($candidate !== '' && ! in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Sucht das Artefakt einer Version zu einem gemeldeten Pfad.
     *
     * **Eine Abfrage, nicht fünf.** Die Bewerber werden gemeinsam geholt und
     * anschließend nach ihrer Rangfolge sortiert — bei einem Stacktrace aus
     * vierzig Rahmen ist der Unterschied zwischen einer und fünf Abfragen je
     * Rahmen der zwischen vierzig und zweihundert.
     */
    public static function matchName(Release $release, string $reported): ?self
    {
        $candidates = self::candidatesFor($reported);

        if ($candidates === []) {
            return null;
        }

        $found = self::query()
            ->where('release_id', $release->id)
            ->whereIn('name', $candidates)
            ->get()
            ->keyBy('name');

        foreach ($candidates as $candidate) {
            $artifact = $found->get($candidate);

            if ($artifact instanceof self) {
                return $artifact;
            }
        }

        return null;
    }

    /**
     * Sucht ein Artefakt über seine Debug-Kennung.
     *
     * Ohne Version: die Kennung ist für sich eindeutig, und aus welcher
     * Auslieferung sie stammt, ist bei der Suche gleichgültig — genau das ist
     * ihr Vorteil gegenüber dem Pfad. Die Quellkarte hat Vorrang vor dem
     * Bundle, denn sie ist das, was gebraucht wird; beide tragen dieselbe
     * Kennung.
     */
    public static function matchDebugId(Project|int $project, string $debugId): ?self
    {
        $debugId = self::normalizeDebugId($debugId);

        if ($debugId === null) {
            return null;
        }

        return self::query()
            ->where('project_id', $project instanceof Project ? $project->id : $project)
            ->where('debug_id', $debugId)
            ->orderByRaw('case when kind = ? then 0 else 1 end', [ReleaseArtifactKind::SourceMap->value])
            ->first();
    }

    /**
     * Vereinheitlicht eine Debug-Kennung: kleingeschrieben und nur, wenn sie
     * wirklich eine ist.
     *
     * Geprüft wird die Form, nicht nur die Länge. Eine Kennung ist der
     * Schlüssel, über den ohne jede weitere Angabe zugeordnet wird — was keine
     * gültige Form hat, würde entweder nie treffen oder, schlimmer, versehentlich
     * doch.
     */
    public static function normalizeDebugId(?string $debugId): ?string
    {
        $debugId = strtolower(trim((string) $debugId));

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $debugId) === 1
            ? $debugId
            : null;
    }

    /**
     * Ist dieses Artefakt eine Quellkarte?
     */
    public function isSourceMap(): bool
    {
        return $this->kind === ReleaseArtifactKind::SourceMap;
    }

    /**
     * Der Pfad, auf den der Verweis dieses Bundles zeigt — gegen den eigenen
     * Pfad aufgelöst.
     *
     * `//# sourceMappingURL=app.js.map` steht in einer Datei, die selbst unter
     * `~/static/js/app.js` liegt; gemeint ist `~/static/js/app.js.map` und nicht
     * `app.js.map` im Wurzelverzeichnis. Eine vollständige Adresse und ein
     * absoluter Pfad bleiben unangetastet — sie sagen selbst, wohin sie zeigen.
     *
     * Eingebettete Karten (`data:`) kommen hier nicht vor: sie stehen im Bundle
     * selbst und werden beim Hochladen als solche erkannt
     * ({@see SourceMap::referenceFrom()}).
     */
    public function sourceMapName(): ?string
    {
        $reference = trim((string) $this->source_map_ref);

        if ($reference === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $reference) === 1
            || str_starts_with($reference, '~/')
            || str_starts_with($reference, '/')) {
            return $reference;
        }

        $directory = rtrim(str_replace('\\', '/', dirname($this->name)), '/');

        return ($directory === '' || $directory === '.' ? '' : $directory.'/').$reference;
    }

    /**
     * Die Quellkarten einer Version.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function sourceMaps(Builder $query): void
    {
        $query->where('kind', ReleaseArtifactKind::SourceMap);
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'release_id',
        'name',
        'kind',
        'debug_id',
        'source_map_ref',
        'size',
        'checksum',
        'path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReleaseArtifactKind::class,
            'size' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
