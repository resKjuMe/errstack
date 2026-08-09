<?php

namespace App\Models;

use App\Support\Replays\ReplayStore;
use Carbon\CarbonImmutable;
use Database\Factories\ReplayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine aufgezeichnete Sitzung: was der Nutzer getan hat, bevor es knallte.
 *
 * Der Fehler sagt, **was** passiert ist, und der Stacktrace sagt, **wo**. Die
 * Frage, die beide offenlassen — „wie ist er überhaupt dorthin gekommen?" —
 * beantwortet keine Meldung, sondern nur die Vorgeschichte. Bislang blieb dafür
 * Raten oder Nachfragen bei einem Nutzer, der sich an den vorletzten Klick nicht
 * erinnert.
 *
 * Diese Zeile ist die Sitzung als Ganzes; die Bilddaten liegen in ihren
 * Abschnitten ({@see ReplaySegment}) und dort nicht in der Datenbank, sondern
 * auf einer Platte ({@see ReplayStore}). Das ist die Zusage der Aufgabe, und sie
 * ist wörtlich gemeint: eine Aufzeichnung lässt sich wegwerfen, ohne einen
 * einzigen Fehler anzufassen.
 *
 * **Sie endet nicht von selbst.** Ein SDK meldet keinen Schlusspunkt — wer die
 * Registerkarte schließt, verabschiedet sich nicht. Solange Abschnitte
 * nachkommen können, ist `finished_at` leer; als beendet gilt eine Sitzung erst,
 * wenn seit dem letzten Abschnitt länger nichts kam als
 * `config('replays.idle_minutes')`.
 *
 * @property int $id
 * @property int $project_id
 * @property string $replay_id
 * @property string $environment
 * @property string|null $release
 * @property string|null $dist
 * @property string|null $platform
 * @property string|null $sdk
 * @property string|null $url
 * @property list<string>|null $urls
 * @property array<string, mixed>|null $user
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $device
 * @property bool $masked
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $last_segment_at
 * @property CarbonImmutable|null $finished_at
 * @property int $duration_ms
 * @property int $segment_count
 * @property int $event_count
 * @property int $error_count
 * @property int $size_bytes
 */
// Was beim Anlegen zugewiesen werden darf. Bewusst kurz: alles Weitere setzt
// ReplayMetadata::applyTo() Feld für Feld. Diese fünf stehen hier, weil
// findOrStart() sie braucht — eine Aufzeichnung ohne Projekt, ohne Umgebung und
// ohne Zeitpunkt gibt es nicht, und sie stehen fest, bevor irgendetwas anderes
// über die Sitzung bekannt ist.
#[Fillable([
    'project_id',
    'replay_id',
    'environment',
    'started_at',
    'last_segment_at',
])]
class Replay extends Model
{
    /**
     * Vorgabewerte für eine frisch angelegte Zeile.
     *
     * Sie stehen hier **zusätzlich** zu den Vorgaben der Datenbank, und das ist
     * kein Doppel: {@see findOrStart()} legt die Zeile mit genau den Feldern an,
     * die es mitgibt, und gibt sie sofort zurück — ohne sie neu zu lesen. Was
     * die Datenbank vorgibt, steht in diesem Modell also noch nicht drin, und
     * `$replay->masked` wäre `null`. Der nächste Schritt rechnet damit
     * (`$replay->masked && $gemeldet`) und macht aus einer maskierten
     * Aufzeichnung eine unmaskierte.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'masked' => true,
        'duration_ms' => 0,
        'segment_count' => 0,
        'event_count' => 0,
        'error_count' => 0,
        'size_bytes' => 0,
    ];

    /** @use HasFactory<ReplayFactory> */
    use HasFactory;

    /**
     * Zeitpunkte mit Millisekunden — dieselbe Begründung wie bei {@see Profile}
     * und {@see Transaction}: die Abschnitte einer Sitzung liegen Sekunden
     * auseinander, ihre Ereignisse Millisekunden. Ohne Bruchteile wäre die
     * Zeitleiste eine Treppe.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * Wie viele besuchte Seiten mitgeführt werden.
     *
     * Die Liste ist eine Auskunft und kein Verlauf: sie beantwortet „wo war er
     * unterwegs", und dafür genügen die ersten. Eine Sitzung, die in einer
     * Einzelseiten-Anwendung tausend Adressen durchläuft, würde die Zeile sonst
     * größer machen als alles andere darin.
     */
    public const MAX_URLS = 50;

    /**
     * Länge, auf die eine Adresse gekürzt wird.
     *
     * Großzügig, weil eine abgeschnittene Adresse in die Irre führt — sie sieht
     * aus wie eine andere Seite. Unbegrenzt geht trotzdem nicht: es gibt
     * Anwendungen, die ihren ganzen Zustand in die Adresszeile schreiben.
     */
    public const URL_LIMIT = 2048;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Die Abschnitte, aus denen der Film besteht — in Abspielreihenfolge.
     *
     * Nach `segment_id` und nicht nach dem Zeitpunkt des Eintretens: Abschnitte
     * überholen einander in der Warteschlange, und ein Film in der Reihenfolge
     * ihrer Ankunft springt.
     *
     * @return HasMany<ReplaySegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(ReplaySegment::class)->orderBy('segment_id');
    }

    /**
     * Die Fehler, die in dieser Sitzung passiert sind.
     *
     * @return HasMany<ReplayError, $this>
     */
    public function errors(): HasMany
    {
        return $this->hasMany(ReplayError::class)->orderBy('occurred_at');
    }

    /**
     * Findet die Aufzeichnung eines Projekts oder legt sie an.
     *
     * Zwei Wege führen hierher, und keiner von beiden ist der erste: die
     * Kopfdaten und der erste Abschnitt kommen als getrennte Elemente mit je
     * einem eigenen Job, und welcher zuerst drankommt, hängt an der Zahl der
     * Arbeiter. Wer auch immer zuerst da ist, legt die Zeile an; der andere
     * findet sie vor und ergänzt, was er weiß.
     *
     * Deshalb `createOrFirst()` und nicht `firstOrCreate()`: zwei Jobs können im
     * selben Augenblick feststellen, dass es die Zeile nicht gibt. Dann
     * entscheidet der eindeutige Index, und der Verlierer bekommt die Zeile des
     * Gewinners — statt mitten in der Verarbeitung an einer Schlüsselverletzung
     * abzubrechen.
     *
     * @param  array<string, mixed>  $attributes  Werte für den Fall, dass sie neu angelegt wird.
     */
    public static function findOrStart(Project $project, string $replayId, array $attributes = []): self
    {
        return self::query()->createOrFirst(
            ['project_id' => $project->id, 'replay_id' => $replayId],
            $attributes,
        );
    }

    /**
     * Gilt die Sitzung als beendet?
     *
     * Die Frage stellt sich an zwei Stellen — die Anzeige will „läuft noch"
     * sagen können, das Aufräumen will nichts wegwerfen, was gerade wächst.
     * Beide dieselbe Antwort geben zu lassen ist billiger, als die Frist an zwei
     * Stellen zu prüfen.
     */
    public function hasEnded(): bool
    {
        if ($this->finished_at !== null) {
            return true;
        }

        return $this->last_segment_at
            ->addMinutes(max(0, (int) config('replays.idle_minutes')))
            ->isPast();
    }

    /**
     * Nur Aufzeichnungen, die etwas zu zeigen haben.
     *
     * Es gibt Zeilen ohne einen einzigen Abschnitt: eine Fehlermeldung nennt die
     * laufende Aufzeichnung, und der Fehler ist regelmäßig vor ihr da
     * ({@see App\Support\Ingest\Processing\Steps\LinkEventReplay}). Die Zeile ist
     * dann ein Anker für die Verknüpfung und noch kein Film.
     *
     * In einer Liste hätte sie nichts verloren — ein Eintrag, der beim Anklicken
     * einen leeren Abspieler zeigt, ist schlimmer als kein Eintrag. Kommt die
     * Aufnahme nach, füllt er sich; kommt sie nie, räumt ihn die
     * Aufbewahrungsfrist weg wie jede andere.
     *
     * @param  Builder<Replay>  $query
     * @return Builder<Replay>
     */
    public function scopePlayable(Builder $query): Builder
    {
        return $query->where('segment_count', '>', 0);
    }

    /**
     * Die neuesten Aufzeichnungen zuerst.
     *
     * Nach dem Beginn und nicht nach dem letzten Abschnitt: gesucht wird „was
     * ist gerade passiert", und eine Sitzung, die seit einer Stunde läuft, wäre
     * sonst neuer als eine, die vor fünf Minuten begann und schon vorbei ist.
     *
     * @param  Builder<Replay>  $query
     * @return Builder<Replay>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('started_at')->orderByDesc('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'urls' => 'array',
            'user' => 'array',
            'masked' => 'boolean',
            'started_at' => 'immutable_datetime',
            'last_segment_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'duration_ms' => 'integer',
            'segment_count' => 'integer',
            'event_count' => 'integer',
            'error_count' => 'integer',
            'size_bytes' => 'integer',
        ];
    }
}
