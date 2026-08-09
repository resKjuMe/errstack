<?php

namespace App\Models;

use App\Support\Releases\Version;
use Carbon\CarbonImmutable;
use Database\Factories\ReleaseFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Eine ausgelieferte Version eines Projekts.
 *
 * Einträge entstehen von selbst, sobald eine Meldung mit einer bisher
 * unbekannten Versionsangabe eintrifft ({@see record()}) — wie bei den
 * Umgebungen und aus demselben Grund: eine Liste, die erst gepflegt werden
 * muss, ist am Tag der Auslieferung leer, und genau dann wird sie gebraucht.
 * Zusätzlich lässt sich eine Version über die Schnittstelle ankündigen
 * ({@see forVersion()}), bevor die erste Meldung daraus eintrifft.
 *
 * @property int $id
 * @property int $project_id
 * @property string $version
 * @property string|null $ref
 * @property string|null $url
 * @property int|null $sort_major
 * @property int|null $sort_minor
 * @property int|null $sort_patch
 * @property string|null $sort_prerelease
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $first_event_at
 * @property CarbonImmutable|null $last_event_at
 * @property int|null $artifacts_count Nur gesetzt, wenn die Abfrage mitgezählt hat.
 */
class Release extends Model
{
    /** @use HasFactory<ReleaseFactory> */
    use HasFactory;

    /**
     * Längstmögliche Versionsangabe (siehe Migration). Sie ist dieselbe wie an
     * `events.release`: was am Ereignis steht, muss auch hier hineinpassen —
     * sonst wären es zwei verschiedene Angaben derselben Version.
     */
    public const VERSION_LIMIT = 200;

    /**
     * Findet die Version eines Projekts oder legt sie an.
     *
     * `createOrFirst()` und nicht „nachsehen, dann anlegen": bei der ersten
     * Meldung aus einer frischen Auslieferung laufen mehrere Arbeiter
     * gleichzeitig auf dieselbe unbekannte Version. Ein `exists()` davor wäre
     * nur eine Momentaufnahme — beide bekämen „nein", und der eindeutige Index
     * über (`project_id`, `version`) ließe einen von beiden mit einem Fehler
     * auflaufen, mitten in der Verarbeitung einer Meldung. Hier entscheidet die
     * Datenbank: wer verliert, bekommt die Zeile des Gewinners zurück.
     *
     * Die Sortierfelder werden **nur beim Anlegen** geschrieben. Sie hängen
     * allein an der Versionsangabe, und die ist der Schlüssel — was einmal
     * zerlegt wurde, kann sich nicht ändern.
     */
    public static function forVersion(Project|int $project, string $version): self
    {
        $version = self::normalizeVersion($version) ?? '';

        return self::query()->createOrFirst(
            [
                'project_id' => $project instanceof Project ? $project->id : $project,
                'version' => $version,
            ],
            Version::parse($version)->columns(),
        );
    }

    /**
     * Erfasst die Version einer eingehenden Meldung.
     *
     * Ohne Angabe passiert nichts — und zwar ausdrücklich: eine Meldung ohne
     * Version gehört zu **keiner**. Ein Ersatzwert („unbekannt") wäre eine
     * erfundene Auslieferung, die sich später von einer echten nicht mehr
     * unterscheiden ließe.
     */
    public static function record(Project|int $project, ?string $version, ?DateTimeInterface $seenAt = null): ?self
    {
        $version = self::normalizeVersion($version);

        if ($version === null) {
            return null;
        }

        $release = self::forVersion($project, $version);

        $release->noteEvent($seenAt === null ? CarbonImmutable::now() : CarbonImmutable::parse($seenAt));

        return $release;
    }

    /**
     * Schreibt erstes und letztes Auftreten in einer Anweisung fort.
     *
     * Nicht `observe()` genannt, so nahe das auch läge: den Namen belegt
     * Eloquent bereits statisch (Modell-Beobachter), und eine Methode kann nicht
     * beides sein.
     *
     * Sperrfrei wie das Zählen am Fehler-Eintrag (I6) und aus demselben Grund:
     * bei einem Ausfall tragen **alle** gleichzeitig verarbeiteten Meldungen
     * dieselbe Version, und damit wäre genau diese eine Zeile die, auf die
     * jeder Arbeiter schreiben will. Gelesen-geändert-geschrieben wäre hier der
     * Engpass, an dem die Aufnahme stehen bleibt.
     *
     * `case when` statt einer Zuweisung, weil Meldungen nicht in ihrer
     * zeitlichen Reihenfolge ankommen: ein SDK, das nach einer Netztrennung
     * seine Warteschlange leert, liefert Stunden später Ereignisse von vorhin.
     * `last_event_at = ?` würde die Version dann in die Vergangenheit
     * zurückdatieren.
     *
     * Der Vergleich gegen den Nullwert steht in jeder Bedingung mit drin: eine
     * über die Schnittstelle angekündigte Version hat noch kein Ereignis, und
     * `null > ?` ist in SQL weder wahr noch falsch — ohne den Zusatz bliebe der
     * erste Zeitstempel für immer leer.
     *
     * **Die Instanz wird mitgeführt**, anders als beim Zählen am Fehler-Eintrag.
     * Dort ist die Veraltung der bewusste Preis, weil sich die neuen Zähler
     * nicht ohne Leseabfrage herleiten ließen; hier ergeben sich beide
     * Zeitpunkte aus derselben Fallunterscheidung wie in der Anweisung. Wer die
     * frisch angelegte Auslieferung weiterreicht — der Aufnahmeweg tut das
     * ({@see App\Support\Ingest\Processing\Steps\RecordRelease}) —, bekäme sonst
     * eine mit leerem `first_event_at`, und genau daran hängt die Rangfolge
     * unzerlegbarer Fassungen ({@see isNewerThan()}).
     */
    public function noteEvent(CarbonImmutable $occurred): void
    {
        $occurred = $occurred->utc();
        $at = $occurred->format('Y-m-d H:i:s');
        $now = Carbon::now()->format('Y-m-d H:i:s');

        DB::update(
            'update '.$this->getTable().' set '
            .'first_event_at = case when first_event_at is null or first_event_at > ? then ? else first_event_at end, '
            .'last_event_at = case when last_event_at is null or last_event_at < ? then ? else last_event_at end, '
            .'updated_at = ? '
            .'where id = ?',
            [$at, $at, $at, $at, $now, $this->id],
        );

        if ($this->first_event_at === null || $this->first_event_at->greaterThan($occurred)) {
            $this->first_event_at = $occurred;
        }

        if ($this->last_event_at === null || $this->last_event_at->lessThan($occurred)) {
            $this->last_event_at = $occurred;
        }

        // Ohne das gälte die Instanz als geändert und würde bei einem späteren
        // `save()` die beiden Spalten ein zweites Mal schreiben — dann als
        // Zuweisung und damit ohne die Fallunterscheidung von oben.
        $this->syncOriginalAttributes(['first_event_at', 'last_event_at']);
    }

    /**
     * Ist diese Auslieferung jünger als jene?
     *
     * **Dieselbe Rangfolge wie in der Liste** ({@see newestFirst()}), Stufe für
     * Stufe — und das ist der Zweck dieser Methode. Die Rückfallerkennung (S8)
     * fragt „ist eine **neuere** Fassung betroffen?", und die Antwort darf nicht
     * davon abhängen, wer fragt: eine zweite Vorstellung von „neuer" wäre eine,
     * die der Versionsliste eines Tages widerspricht — dieselben zwei Angaben
     * stünden dann in der Liste in der einen und in der Erkennung in der anderen
     * Reihenfolge.
     *
     * Die Zeit entscheidet erst zuletzt und trägt dort alles, was keine Nummer
     * hat: ein Commit-Hash hat keine Rangfolge, aber die Auslieferung, aus der
     * die erste Meldung später eintraf, ist die spätere. Genommen wird dafür das
     * **erste** Ereignis und nicht das letzte, mit dem die Liste ihren
     * Gleichstand auflöst — das letzte wandert bei jeder eingehenden Meldung,
     * und eine Erkennung, die heute „neuer" sagt und morgen „älter", wäre keine.
     */
    public function isNewerThan(self $other): bool
    {
        return self::rank($this) > self::rank($other);
    }

    /**
     * Der Rang einer Auslieferung als vergleichbare Liste.
     *
     * PHP vergleicht Listen von links nach rechts, Feld für Feld — dieselbe
     * Ordnung, die `order by` aus mehreren Spalten macht. Die Werte sind
     * deshalb so gewählt, dass **größer** überall „neuer" heißt, auch dort, wo
     * die Abfrage absteigend sortiert.
     *
     * Die beiden Marken (`hat eine Nummer`, `ist endgültig`) stehen als eigene
     * Felder da und nicht als Kunstgriff im Text daneben: „endgültig vor Vorab"
     * über ein vorangestelltes Zeichen zu lösen hinge daran, dass kein
     * Vorabteil dieses Zeichen enthält — und was ein SDK als Version schickt,
     * ist nicht verhandelbar.
     *
     * @return list<int|string>
     */
    private static function rank(self $release): array
    {
        return [
            $release->sort_major === null ? 0 : 1,
            $release->sort_major ?? 0,
            $release->sort_minor ?? 0,
            $release->sort_patch ?? 0,
            $release->sort_prerelease === null ? 1 : 0,
            $release->sort_prerelease ?? '',
            $release->first_event_at?->getTimestamp() ?? 0,
            $release->id,
        ];
    }

    /**
     * Vereinheitlicht eine gemeldete Versionsangabe, damit „1.0.0" und
     * „ 1.0.0 " nicht zwei Auslieferungen ergeben.
     *
     * Gekürzt statt abgewiesen — wie bei den Umgebungen: eine ungewöhnlich
     * lange Versionsangabe soll ihre Meldung nicht verlieren. Bleibt nichts
     * übrig, ist das Ergebnis `null`; der Aufrufer entscheidet, ob das ein
     * Fehler ist oder einfach „keine Version".
     */
    public static function normalizeVersion(?string $version): ?string
    {
        $version = Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $version) ?? ''), self::VERSION_LIMIT, '');

        return $version === '' ? null : $version;
    }

    /**
     * Die Versionsliste in semantischer Ordnung, neueste zuerst.
     *
     * Vier Stufen, und jede ist nötig:
     *
     *   1. Zerlegbare Versionen vor unzerlegbaren. Ein Commit-Hash hat keine
     *      Rangfolge gegenüber `1.2.3`; ihn dazwischen einzusortieren wäre eine
     *      erfundene Aussage.
     *   2. Die Zahlen, absteigend.
     *   3. Endgültige Fassungen vor ihren Vorabversionen — `1.0.0` steht über
     *      `1.0.0-rc.1`. Das ist die Stelle, an der ein reiner Textvergleich es
     *      genau falsch herum hätte.
     *   4. Zuletzt die Zeit: für alles Unzerlegbare ist sie die einzige Ordnung,
     *      die es gibt.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query
            ->orderByRaw('case when sort_major is null then 1 else 0 end')
            ->orderByDesc('sort_major')
            ->orderByDesc('sort_minor')
            ->orderByDesc('sort_patch')
            ->orderByRaw('case when sort_prerelease is null then 0 else 1 end')
            ->orderByDesc('sort_prerelease')
            ->orderByDesc('last_event_at')
            ->orderByDesc('id');
    }

    /**
     * Dieselbe Ordnung andersherum: die älteste Version zuerst.
     *
     * **Keine reine Umkehrung**, und das ist die eine Stelle, an der man
     * hinsehen muss: die erste Stufe bleibt, wie sie ist. Eine Angabe ohne
     * Rangfolge — ein Commit-Hash — steht auch hier hinten. Umgedreht stünde
     * sie plötzlich vor `0.1.0`, und die Aussage „das war die erste Version"
     * wäre erfunden; unzerlegbar heißt nicht „älter", sondern „nicht
     * einzuordnen".
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function oldestFirst(Builder $query): void
    {
        $query
            ->orderByRaw('case when sort_major is null then 1 else 0 end')
            ->orderBy('sort_major')
            ->orderBy('sort_minor')
            ->orderBy('sort_patch')
            // Und hier gilt die Umkehrung sehr wohl: `1.0.0-rc.1` kam vor
            // `1.0.0`.
            ->orderByRaw('case when sort_prerelease is null then 1 else 0 end')
            ->orderBy('sort_prerelease')
            ->orderBy('last_event_at')
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Die Fehler, die in dieser Version zum ersten Mal auftraten.
     *
     * Das ist die Zahl, die nach einer Auslieferung zählt: nicht „wie viele
     * Fehler gibt es", sondern „wie viele sind mit dieser Version dazugekommen".
     *
     * @return HasMany<Issue, $this>
     */
    public function newIssues(): HasMany
    {
        return $this->hasMany(Issue::class, 'first_release_id');
    }

    /**
     * Die Fehler, deren letztes Auftreten in dieser Version liegt.
     *
     * @return HasMany<Issue, $this>
     */
    public function currentIssues(): HasMany
    {
        return $this->hasMany(Issue::class, 'last_release_id');
    }

    /**
     * Die Commits, die in dieser Auslieferung stecken (R2).
     *
     * In der Reihenfolge, in der sie übergeben wurden, und nicht nach
     * `committed_at`: die Zeit eines Commits stammt aus dem Repository und gibt
     * nach einem Rebase oder Cherry-Pick die Reihenfolge verkehrt herum wieder.
     * Wer die Liste schickt, kennt sie.
     *
     * @return BelongsToMany<Commit, $this>
     */
    public function commits(): BelongsToMany
    {
        return $this->belongsToMany(Commit::class, 'release_commit')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * Die hochgeladenen Bauartefakte dieser Version — Bundle und Quellkarte (R5).
     *
     * @return HasMany<ReleaseArtifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(ReleaseArtifact::class);
    }

    /**
     * Die Auslieferungen dieser Version, neueste zuerst (R3).
     *
     * Mehrere, und das ist der Punkt: dieselbe Version geht nacheinander nach
     * `staging` und nach `production`, und nach einem Rollback ein zweites Mal.
     * `released_at` daneben ist die eine angekündigte Zeit aus der
     * Schnittstelle — sie ersetzt diese Liste nicht.
     *
     * @return HasMany<Deploy, $this>
     */
    public function deploys(): HasMany
    {
        return $this->hasMany(Deploy::class)->newestFirst();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'version',
        'ref',
        'url',
        'sort_major',
        'sort_minor',
        'sort_patch',
        'sort_prerelease',
        'released_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_major' => 'integer',
            'sort_minor' => 'integer',
            'sort_patch' => 'integer',
            // `immutable_datetime` wie am Fehler-Eintrag: ein versehentliches
            // `->addHour()` auf einer geteilten Instanz soll nicht die Version
            // selbst verschieben.
            'released_at' => 'immutable_datetime',
            'first_event_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
        ];
    }
}
