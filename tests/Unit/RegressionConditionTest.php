<?php

namespace Tests\Unit;

use App\Enums\IssueStatus;
use App\Models\Release;
use App\Support\Issues\RegressionCondition;
use App\Support\Releases\Version;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Die Rückfall-Bedingung für sich, ohne Datenbank.
 *
 * Sie entscheidet bei jedem Ereignis eines erledigten Eintrags, ob er wieder
 * aufgeht — und beide Fehlerrichtungen sind teuer: löst sie zu leicht aus, geht
 * jede Erledigung von der nächsten nachgereichten Altmeldung wieder verloren und
 * niemand bekommt seine Liste leer; löst sie zu selten aus, bleibt ein Fehler
 * erledigt, der wieder da ist — und genau davor soll die Aufgabe schützen.
 * Geprüft wird deshalb jede Kante: gleiche Zeit, gleiche Fassung, keine Fassung.
 */
class RegressionConditionTest extends TestCase
{
    private CarbonImmutable $resolvedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolvedAt = CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
    }

    public function test_an_open_issue_can_not_regress(): void
    {
        $condition = new RegressionCondition(IssueStatus::Unresolved, null, null);

        $this->assertFalse($condition->isPossible());
        $this->assertFalse($condition->evaluate($this->resolvedAt->addHour(), null, null));
    }

    /**
     * Ein stummgeschalteter Eintrag kommt nicht zurück — er war nie weg. Was
     * mit ihm geschieht, entscheidet seine Bedingung (S6).
     */
    public function test_an_ignored_issue_can_not_regress(): void
    {
        $condition = new RegressionCondition(IssueStatus::Ignored, $this->resolvedAt, null);

        $this->assertFalse($condition->isPossible());
    }

    public function test_a_later_event_reopens_an_issue_resolved_without_a_release(): void
    {
        $condition = $this->resolved();

        $this->assertTrue($condition->evaluate($this->resolvedAt->addSecond(), null, null));
    }

    /**
     * Nachgereichte Altmeldungen sind kein Rückfall: ein SDK, das nach einer
     * Netztrennung seine Warteschlange leert, liefert Stunden später Ereignisse
     * von vorhin.
     */
    public function test_an_event_from_before_the_resolution_does_not_reopen(): void
    {
        $condition = $this->resolved();

        $this->assertFalse($condition->evaluate($this->resolvedAt->subSecond(), null, null));

        // Auf die Sekunde gleichzeitig zählt als „davor": das Erledigen ist die
        // Antwort auf alles, was bis dahin da war.
        $this->assertFalse($condition->evaluate($this->resolvedAt, null, null));
    }

    /**
     * „Erledigt in 1.4.2": Meldungen aus 1.4.2 und älter sind kein Widerspruch,
     * sondern der Stand ohne den Fix, der noch läuft.
     */
    public function test_the_same_or_an_older_release_does_not_reopen(): void
    {
        $fixed = $this->release(2, '1.4.2');
        $condition = $this->resolved($fixed);

        $this->assertFalse($condition->evaluate($this->resolvedAt->addHour(), $fixed, $fixed));
        $this->assertFalse($condition->evaluate($this->resolvedAt->addHour(), $this->release(1, '1.4.1'), $fixed));
    }

    public function test_a_newer_release_reopens(): void
    {
        $fixed = $this->release(2, '1.4.2');

        $this->assertTrue(
            $this->resolved($fixed)->evaluate($this->resolvedAt->addHour(), $this->release(3, '1.10.0'), $fixed),
        );
    }

    /**
     * Ohne Versionsangabe an der Meldung gibt es keinen Rückfall, solange die
     * Erledigung an eine Fassung gebunden war: gefordert ist eine **neuere**
     * Fassung, und „keine Angabe" ist keine.
     */
    public function test_an_event_without_a_release_does_not_reopen_a_release_bound_resolution(): void
    {
        $fixed = $this->release(2, '1.4.2');

        $this->assertFalse(
            $this->resolved($fixed)->evaluate($this->resolvedAt->addHour(), null, $fixed),
        );
    }

    /**
     * Wurde die Fassung inzwischen gelöscht, bleibt von „erledigt in 1.4.2" die
     * einfache Aussage „ab jetzt weg" — und die ist widerlegt.
     */
    public function test_a_deleted_resolution_release_falls_back_to_the_plain_rule(): void
    {
        $this->assertTrue(
            $this->resolved($this->release(2, '1.4.2'))->evaluate($this->resolvedAt->addHour(), null, null),
        );
    }

    /**
     * Eine Vorabversion ist älter als ihre endgültige Fassung — die Stelle, an
     * der ein reiner Textvergleich es genau falsch herum hätte.
     */
    public function test_a_prerelease_of_the_fixed_version_does_not_reopen(): void
    {
        $fixed = $this->release(2, '2.0.0');

        $this->assertFalse(
            $this->resolved($fixed)->evaluate($this->resolvedAt->addHour(), $this->release(3, '2.0.0-rc.1'), $fixed),
        );
    }

    /**
     * Unzerlegbare Fassungen — ein Commit-Hash — haben keine Nummer; dann
     * entscheidet, wann die erste Meldung daraus eintraf.
     */
    public function test_unordered_releases_fall_back_to_the_first_event(): void
    {
        $fixed = $this->release(2, 'a1b2c3d', $this->resolvedAt->subDay());
        $later = $this->release(3, 'e4f5a6b', $this->resolvedAt->addMinutes(30));

        $condition = $this->resolved($fixed);

        $this->assertTrue($condition->evaluate($this->resolvedAt->addHour(), $later, $fixed));
        $this->assertFalse($condition->evaluate($this->resolvedAt->addHour(), $fixed, $fixed));
    }

    private function resolved(?Release $fixedIn = null): RegressionCondition
    {
        return new RegressionCondition(IssueStatus::Resolved, $this->resolvedAt, $fixedIn?->id);
    }

    /**
     * Eine Auslieferung ohne Datenbank — mit denselben Sortierfeldern, die das
     * Anlegen schreiben würde.
     */
    private function release(int $id, string $version, ?CarbonImmutable $firstEventAt = null): Release
    {
        $release = new Release([
            'version' => $version,
            ...Version::parse($version)->columns(),
        ]);

        $release->id = $id;
        $release->first_event_at = $firstEventAt;

        return $release;
    }
}
