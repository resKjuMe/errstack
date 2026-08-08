<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeployFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Auslieferung: diese Version, diese Umgebung, dieser Zeitpunkt.
 *
 * Der Unterschied zur Version selbst (R1) ist der zwischen „gebaut" und
 * „draußen". Eine Version entsteht von selbst aus der ersten Meldung und sagt
 * damit nur, dass irgendwo etwas mit dieser Angabe lief. Ein Deploy sagt, wann
 * jemand sie ausgeliefert hat und wohin — und beides zusammen ist die Antwort
 * auf die Frage, mit der nach jeder Störung jemand kommt: „hängt das mit dem
 * Deploy von eben zusammen?"
 *
 * **Die Umgebung ist kein Beiwerk.** An ihr hängt, ob ein Deploy Folgen hat:
 * eine Auslieferung nach `staging` markiert die Verlaufsgrafiken derselben
 * Umgebung und sonst nichts. Erst der Deploy in die Standard-Umgebung des
 * Projekts löst die Einträge auf, die auf „erledigt im nächsten Release"
 * warten, und benachrichtigt die Beteiligten.
 *
 * @property int $id
 * @property int $project_id
 * @property int $release_id
 * @property int $environment_id
 * @property string|null $name
 * @property string|null $url
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable $finished_at
 * @property CarbonImmutable|null $created_at
 */
class Deploy extends Model
{
    /** @use HasFactory<DeployFactory> */
    use HasFactory;

    /**
     * Längstmögliche Beschriftung (siehe Migration).
     */
    public const NAME_LIMIT = 200;

    /**
     * Erfasst eine Auslieferung.
     *
     * **Ohne `finished_at` gilt jetzt.** Der Aufruf steht in einer
     * Auslieferungs-Pipeline und wird abgesetzt, wenn sie durch ist; wer keinen
     * Zeitpunkt mitschickt, meint diesen. Ein Nullwert wäre hier die schlechtere
     * Wahl: der Zeitpunkt ist genau das, was ein Deploy gegenüber der Version
     * beiträgt, und ohne ihn stünde eine Zeile in der Liste, die nichts sagt.
     *
     * **Kein `updateOrCreate`.** Zweimal auszuliefern ist zweimal ausgeliefert —
     * nach einem Rollback ist das der Normalfall, und die zweite Zeile ist die
     * Auskunft darüber. Wer den Aufruf wiederholt, weil sein Bauschritt
     * fehlschlug, hat tatsächlich zweimal ausgeliefert.
     */
    public static function record(
        Release $release,
        Environment $environment,
        ?string $name = null,
        ?string $url = null,
        ?CarbonImmutable $startedAt = null,
        ?CarbonImmutable $finishedAt = null,
    ): self {
        $deploy = new self;

        // Das Projekt kommt von der Version und nicht aus dem Aufruf: hier ist
        // die eine Stelle, an der die drei Bezüge zusammenkommen, und damit die
        // einzige, an der sie auseinanderlaufen könnten.
        $deploy->project_id = $release->project_id;
        $deploy->release_id = $release->id;
        $deploy->environment_id = $environment->id;
        $deploy->name = self::normalizeName($name);
        $deploy->url = $url;
        $deploy->started_at = $startedAt;
        $deploy->finished_at = $finishedAt ?? CarbonImmutable::now();
        $deploy->save();

        return $deploy;
    }

    /**
     * Kürzt eine Beschriftung auf das, was die Spalte trägt — wie bei den
     * Umgebungen und Versionen: eine ungewöhnlich lange Angabe soll ihren
     * Deploy nicht verlieren.
     */
    public static function normalizeName(?string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');

        return $name === '' ? null : mb_substr($name, 0, self::NAME_LIMIT);
    }

    /**
     * Wie die Auslieferung angeschrieben wird.
     *
     * Ohne eigene Beschriftung ist es die Umgebung — sie ist das, was der
     * Deploy in jedem Fall aussagt, und besser als eine leere Zelle.
     */
    public function label(): string
    {
        return $this->name ?? $this->environment->name;
    }

    /**
     * Die Deploys, neueste zuerst.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('finished_at')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'release_id',
        'environment_id',
        'name',
        'url',
        'started_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
