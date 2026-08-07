<?php

namespace App\Models;

use App\Support\Ingest\Grouping\Matcher;
use Database\Factories\FingerprintRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine projektweite Regel für die Gruppierung: „sieht die Meldung so aus, dann
 * gehört sie unter diesen Fingerabdruck".
 *
 * Regeln sind die Korrektur eines Groupings, das im Einzelfall danebenliegt —
 * ein Rahmenwerk, das jeden Fehler durch dieselbe Funktion schickt und damit
 * alles zusammenwirft; ein Dienst, dessen Meldungen sich in jedem Rahmen
 * unterscheiden und deshalb auseinanderfallen. Beides ist mit einer Zeile
 * Regelwerk zu beheben und ohne eine Änderung am Verfahren nicht.
 *
 * Sie stehen deshalb in der Datenbank und nicht in einer Konfigurationsdatei:
 * sie sind je Projekt verschieden und werden von denen gepflegt, die die
 * Fehlerliste ansehen — nicht von denen, die die Anwendung ausliefern.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property list<mixed> $matchers Bedingungen, wie sie in der Datenbank stehen.
 * @property list<mixed> $fingerprint Bestandteile, wie sie in der Datenbank stehen.
 * @property int $position
 * @property bool $is_active
 */
#[Fillable(['name', 'matchers', 'fingerprint', 'position', 'is_active'])]
class FingerprintRule extends Model
{
    /** @use HasFactory<FingerprintRuleFactory> */
    use HasFactory;

    /**
     * Wie viele Regeln ein Projekt haben darf.
     *
     * Die Grenze ist keine Sparsamkeit, sondern die Rechnung: die Regeln werden
     * bei **jeder** Meldung der Reihe nach geprüft, und jede Bedingung auf
     * `stack.*` läuft dabei über den ganzen Stacktrace. Ein Regelwerk, das
     * unbemerkt auf tausend Zeilen wächst, hält irgendwann die Auswertung an —
     * und zwar im Hintergrund, lange nachdem jemand die letzte Regel angelegt
     * hat.
     */
    public const MAX_PER_PROJECT = 50;

    /**
     * Die Bedingungen als Gegenstände.
     *
     * Unbrauchbare Einträge werden übergangen und nicht als Fehler geworfen:
     * eine Regel, die halb kaputt in der Datenbank steht, darf die Auswertung
     * **jeder** Meldung dieses Projekts nicht zum Scheitern bringen. Beim
     * Anlegen wird geprüft (siehe FingerprintRuleRequest); hier zählt, dass der
     * Betrieb weiterläuft.
     *
     * @return list<Matcher>
     */
    public function conditions(): array
    {
        $matchers = [];

        foreach ($this->matchers as $matcher) {
            if (! is_array($matcher)) {
                continue;
            }

            $parsed = Matcher::fromArray($matcher);

            if ($parsed !== null) {
                $matchers[] = $parsed;
            }
        }

        return $matchers;
    }

    /**
     * Die Bestandteile, die diese Regel setzt.
     *
     * @return list<string>
     */
    public function values(): array
    {
        $values = [];

        foreach ($this->fingerprint as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            }
        }

        return $values;
    }

    /**
     * Kann diese Regel überhaupt greifen?
     *
     * Ohne Bedingung träfe sie auf **jede** Meldung zu und würde das ganze
     * Projekt in eine Gruppe ziehen; ohne Fingerabdruck hätte sie nichts zu
     * setzen. Beides ist beim Anlegen ausgeschlossen — die Prüfung hier gilt
     * dem Datenbestand, der älter sein kann als die Prüfung.
     */
    public function isUsable(): bool
    {
        return $this->conditions() !== [] && $this->values() !== [];
    }

    /**
     * Die aktiven Regeln in ihrer Reihenfolge.
     *
     * Die Kennung als zweites Ordnungsmerkmal: bei gleicher Position entscheidet
     * sonst die Datenbank, und dieselbe Meldung könnte morgen anders gruppiert
     * werden als heute. Das wäre ein Bruch der Zusage, dass gleiche Eingabe
     * dauerhaft dieselbe Gruppe ergibt.
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
            'matchers' => 'array',
            'fingerprint' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
