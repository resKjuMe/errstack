<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ein verbundenes Repository — die Herkunft des Codes einer Organisation.
 *
 * Es steht an der Organisation und nicht am Projekt: dasselbe Repository
 * versorgt in aller Regel mehrere Projekte, und der Bezug zu einem entsteht
 * über die Auslieferung, in der seine Commits stecken.
 *
 * **Verbinden heißt hier: eintragen.** Solange es keine Anbindung gibt (X1/X2),
 * holt niemand von selbst Commits ab — sie werden übergeben. Das Repository ist
 * dann die Zeile, die aus einem Namen in einer Übergabe („acme/webshop") einen
 * Gegenstand macht, an dem Commits hängen und der eine Adresse hat, unter der
 * sie sich ansehen lassen.
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $integration_id
 * @property string $name
 * @property string $provider
 * @property string|null $url
 * @property string|null $external_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    /**
     * Längstmöglicher Name (siehe Migration).
     */
    public const NAME_LIMIT = 200;

    /**
     * Die Herkunft eines Repositories, das ohne Anbindung entstanden ist —
     * von Hand eingetragen oder beim ersten Übergeben von Commits angelegt.
     */
    public const PROVIDER_MANUAL = 'manual';

    /**
     * Findet das Repository einer Organisation oder legt es an.
     *
     * `createOrFirst()` wie bei den Versionen und aus demselben Grund: die
     * erste Übergabe aus einem frischen Repository trifft in einer Pipeline mit
     * mehreren gleichzeitigen Bauläufen mehrfach ein. Ein `exists()` davor wäre
     * nur eine Momentaufnahme; hier entscheidet der eindeutige Index, und wer
     * verliert, bekommt die Zeile des Gewinners.
     *
     * **Warum von selbst und nicht mit einem Fehler:** die Übergabe kommt aus
     * einer Bauumgebung ohne Anbindung — das ist der Fall, für den es sie gibt.
     * Ein „dieses Repository ist nicht verbunden" wäre dort ein roter Baulauf
     * für einen Vorgang, der nichts falsch gemacht hat, und die Antwort darauf
     * wäre ein Handgriff in der Oberfläche, den niemand vorher kennen kann.
     */
    public static function forName(Organization|int $organization, string $name, ?string $url = null): self
    {
        $repository = self::query()->createOrFirst(
            [
                'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
                'name' => self::normalizeName($name) ?? '',
            ],
            [
                'provider' => self::PROVIDER_MANUAL,
                'url' => $url,
            ],
        );

        // Eine Adresse, die beim ersten Mal fehlte, wird nachgetragen — aber
        // eine vorhandene nie überschrieben: sie kann von Hand gesetzt oder von
        // einer Anbindung gepflegt sein, und eine Bauumgebung, die sie
        // beiläufig mitschickt, weiß es nicht besser.
        if ($url !== null && $repository->url === null) {
            $repository->forceFill(['url' => $url])->save();
        }

        return $repository;
    }

    /**
     * Vereinheitlicht einen Repository-Namen, damit „acme/webshop" und
     * „ acme/webshop " nicht zwei Repositories ergeben.
     */
    public static function normalizeName(?string $name): ?string
    {
        $name = Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''), self::NAME_LIMIT, '');

        return $name === '' ? null : $name;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Über welche Anbindung dieses Repository hereinkam (X1) — und keine bei
     * den von Hand eingetragenen, die es weiterhin gibt.
     *
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /**
     * @return HasMany<Commit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(Commit::class);
    }

    /**
     * Die Adresse eines Commits beim Anbieter.
     *
     * Nur für die Adressen, deren Aufbau feststeht: GitHub und GitLab — und
     * damit auch die selbst betriebenen Fassungen von beiden — legen den Commit
     * unter `<repository>/commit/<hash>` ab. Ist die Adresse etwas anderes
     * (eine SSH-Angabe, ein anderer Anbieter), gibt es keinen Link statt eines
     * geratenen: ein Verweis, der ins Leere führt, ist schlechter als kein
     * Verweis. Welche Anbieter ihre Adressen anders bauen, weiß erst die
     * Anbindung (X1/X2) — sie kann sie dann selbst beisteuern.
     */
    public function commitUrl(string $sha): ?string
    {
        $url = trim((string) $this->url);

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            return null;
        }

        // Die Klon-Adresse endet auf `.git`; sie ist das, was jemand aus dem
        // Repository kopiert, und daran angehängt führte der Link ins Leere.
        return Str::chopEnd(rtrim($url, '/'), '.git').'/commit/'.$sha;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'name',
        'integration_id',
        'provider',
        'url',
        'external_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
