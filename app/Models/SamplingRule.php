<?php

namespace App\Models;

use App\Support\Ingest\Processing\Steps\SampleTransaction;
use App\Support\Ingest\Sampling\SampleTarget;
use Database\Factories\SamplingRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Eine projektweite Stichproben-Regel: „sieht der Aufruf so aus, behalte davon
 * diesen Anteil".
 *
 * Regeln sind die Antwort auf eine Rechnung, die ohne sie nicht aufgeht: eine
 * Anwendung mit hundert Aufrufen je Sekunde meldet im Monat 260 Millionen
 * Transaktionen, und die vollständig zu speichern kostet mehr, als die Auskunft
 * wert ist. Gebraucht wird nicht jeder Aufruf, sondern die Verteilung — und die
 * steht in einer Stichprobe genauso.
 *
 * **Fehler sind davon nicht betroffen.** Diese Regeln greifen ausschließlich an
 * Transaktionen ({@see SampleTransaction}); ein Fehler wird immer vollständig
 * behalten. Das ist keine Vorsicht, sondern der Unterschied der Sache: bei
 * Antwortzeiten fragt man nach der Verteilung, bei einem Absturz nach dem
 * Einzelfall — und einen Einzelfall kann man nicht hochrechnen.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string|null $transaction_name
 * @property string|null $environment
 * @property string|null $release
 * @property string|null $op
 * @property float $sample_rate
 * @property int $minimum_per_window
 * @property int $position
 * @property bool $is_active
 */
#[Fillable([
    'name',
    'transaction_name',
    'environment',
    'release',
    'op',
    'sample_rate',
    'minimum_per_window',
    'position',
    'is_active',
])]
class SamplingRule extends Model
{
    /** @use HasFactory<SamplingRuleFactory> */
    use HasFactory;

    /**
     * Wie viele Regeln ein Projekt haben darf.
     *
     * Dieselbe Grenze und derselbe Grund wie bei den Fingerprint-Regeln: die
     * Regeln werden bei **jeder** gemeldeten Transaktion der Reihe nach geprüft,
     * und eine Transaktion ist die häufigste Meldung überhaupt. Ein Regelwerk,
     * das unbemerkt auf tausend Zeilen wächst, bremst genau die Aufnahme aus,
     * die es entlasten soll.
     */
    public const MAX_PER_PROJECT = 50;

    /**
     * Die Merkmale, auf die sich eine Bedingung beziehen kann — in der
     * Reihenfolge, in der die Oberfläche sie zeigt.
     *
     * Geschlossen und klein: mehr Merkmale wären mehr Spalten, und die vier hier
     * decken die Fälle ab, um die es geht — ein Name für den einzelnen
     * Endpunkt, eine Umgebung, um die Entwicklung anders zu behandeln als den
     * Betrieb, eine Version für einen Testlauf und ein Anfragetyp, um
     * Hintergrundarbeit von Seitenaufrufen zu trennen.
     *
     * @var list<string>
     */
    public const CONDITIONS = ['transaction_name', 'environment', 'release', 'op'];

    /**
     * Trifft die Regel auf diesen Aufruf zu?
     *
     * Alle **gesetzten** Bedingungen müssen zutreffen; leere Bedingungen sind
     * der Regel gleichgültig. Eine Regel ohne jede Bedingung trifft damit auf
     * alles zu — sie ist die Vorgabe des Projekts und ausdrücklich erlaubt.
     * Deshalb kommen neue Regeln ans Ende: vorn eingereiht würde eine solche
     * Regel alle übrigen stillschweigend überstimmen.
     */
    public function matches(SampleTarget $target): bool
    {
        return $this->matchesValue($this->transaction_name, $target->name)
            && $this->matchesValue($this->environment, $target->environment)
            && $this->matchesValue($this->release, $target->release)
            && $this->matchesValue($this->op, $target->op);
    }

    /**
     * Eine einzelne Bedingung.
     *
     * Das Muster ist ein Platzhalter-Ausdruck (`*`) und **kein** regulärer
     * Ausdruck — dieselbe Entscheidung wie bei den Fingerprint-Regeln und aus
     * demselben Grund: `GET /api/*` ist in dreißig Sekunden richtig, und ein
     * falscher regulärer Ausdruck wird in einem Schritt, der bei jeder
     * Transaktion läuft, teuer.
     *
     * Verglichen wird **mit** Rücksicht auf Groß- und Kleinschreibung, anders
     * als beim Grouping: dort stehen Klassennamen und Pfade, die je Plattform
     * anders geschrieben werden, hier stehen Routen und Versionsnummern, und
     * `/Admin` ist nicht `/admin`.
     *
     * Fehlt der Wert am Aufruf, kann eine gesetzte Bedingung nicht zutreffen —
     * eine Regel für „Version 2.0" gilt nicht für einen Aufruf ohne Version.
     */
    private function matchesValue(?string $pattern, ?string $value): bool
    {
        if ($pattern === null || trim($pattern) === '') {
            return true;
        }

        return $value !== null && Str::is($pattern, $value);
    }

    /**
     * Die aktiven Regeln in ihrer Reihenfolge.
     *
     * Die Kennung als zweites Ordnungsmerkmal: bei gleicher Position entscheidet
     * sonst die Datenbank, und dieselbe Transaktion würde morgen mit einer
     * anderen Quote bewertet als heute. Das wäre in einer Zeitreihe ein Sprung
     * ohne Ursache.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('position')->orderBy('id');
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
            // `float` und nicht `decimal:8`: die Quote wird gerechnet (mit dem
            // Wurf verglichen, in ein Gewicht umgekehrt), und `decimal` liefert
            // eine Zeichenkette. Der Wertebereich zwischen 0 und 1 ist für eine
            // Gleitkommazahl unproblematisch — die Spalte bleibt `decimal`,
            // damit in der Datenbank genau der eingestellte Wert steht.
            'sample_rate' => 'float',
            'minimum_per_window' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
