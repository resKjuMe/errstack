<?php

namespace App\Support\Releases\Health;

use App\Enums\ReleaseSort;
use App\Models\ReleaseSessionCount;
use App\Models\ReleaseSessionUser;
use App\Support\Filters\GlobalFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Der Ausschnitt, über den die Release-Gesundheit gerechnet wird: Projekte,
 * Zeitraum, Umgebung.
 *
 * Es gibt ihn als eigene Sache, weil dieselbe Einschränkung an zwei Stellen
 * gebraucht wird, die nichts voneinander wissen: {@see ReleaseHealth} rechnet
 * daraus die Kennzahlen einer Seite, und die Sortierung der Versionsliste
 * ({@see ReleaseSort}) hängt dieselben Summen als Unterabfrage an die
 * Liste, weil sich sonst nur die 50 Zeilen einer Seite sortieren ließen und
 * nicht die Liste.
 *
 * Zweimal geschrieben wäre das zweimal dieselbe `where`-Kette — und beim
 * nächsten Zusatz (eine weitere Spalte, ein anderes Fenster) eine Liste, deren
 * Reihenfolge nicht mehr zu ihren Zahlen passt: die schlechteste Version stünde
 * oben, aber mit der Quote einer anderen daneben.
 *
 * **Oben offen** (`from <= t < to`), wie bei den Alarm-Fenstern: sonst zählte
 * das Fenster an der Grenze in zwei aufeinanderfolgenden Zeiträumen mit.
 */
final class SessionWindow
{
    /**
     * @param  list<int>  $projectIds
     */
    public function __construct(
        public readonly array $projectIds,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $environment = null,
    ) {}

    /**
     * Der Ausschnitt, den die globale Filterleiste beschreibt (F7).
     *
     * Die Projekte sind übergebbar, weil die Detailseite einer Version an genau
     * einem hängt: dort sagt die Adresszeile, welches gemeint ist, und die
     * Leiste liefert nur noch Zeitraum und Umgebung.
     *
     * @param  list<int>|null  $projectIds
     */
    public static function fromFilter(GlobalFilter $filter, ?array $projectIds = null): self
    {
        return new self(
            $projectIds ?? $filter->projectIds(),
            $filter->fromUtc(),
            $filter->toUtc(),
            $filter->environment,
        );
    }

    /**
     * Die Sitzungszähler im Ausschnitt.
     */
    public function counts(): Builder
    {
        return $this->apply(ReleaseSessionCount::query()->toBase());
    }

    /**
     * Die Nutzer-Zähler im Ausschnitt.
     */
    public function users(): Builder
    {
        return $this->apply(ReleaseSessionUser::query()->toBase());
    }

    /**
     * Sitzungen je Version — die Summen, aus denen die Crash-Free-Rate wird.
     *
     * Als Unterabfrage gedacht: eine Zeile je Version, und damit anhängbar an
     * eine Liste, die nach ihr sortiert werden soll.
     */
    public function sessionsByRelease(): Builder
    {
        return $this->counts()
            ->selectRaw('release_id')
            ->selectRaw(implode(', ', ReleaseSessionCount::sumExpressions()))
            ->groupBy('release_id');
    }

    /**
     * Dasselbe für das ganze Projekt — der Nenner der Verbreitung.
     */
    public function sessionsByProject(): Builder
    {
        return $this->counts()
            ->selectRaw('project_id')
            ->selectRaw('sum(session_count) as session_count')
            ->groupBy('project_id');
    }

    /**
     * Menschen je Version: wie viele, und wie viele davon hat es erwischt.
     *
     * `count(distinct …)` und nicht `sum()`: dieselbe Person taucht in mehreren
     * Zeitfenstern auf, und aufaddiert wäre sie so viele Menschen, wie sie die
     * Anwendung an dem Tag geöffnet hat.
     */
    public function usersByRelease(): Builder
    {
        return $this->users()
            ->selectRaw('release_id')
            ->selectRaw(implode(', ', self::USER_EXPRESSIONS))
            ->groupBy('release_id');
    }

    /**
     * Menschen je Projekt — der Nenner der Verbreitung über Menschen.
     */
    public function usersByProject(): Builder
    {
        return $this->users()
            ->selectRaw('project_id')
            ->selectRaw('count(distinct user_key) as users')
            ->groupBy('project_id');
    }

    /**
     * Die drei Zahlen, die aus den Nutzer-Zeilen hervorgehen.
     *
     * `unhealthy` fasst alles zusammen, was schiefging — Fehler, Absturz,
     * Abbruch —, während `crashed` nur den Absturz zählt. Beide nebeneinander,
     * weil „absturzfrei" überall dasselbe heißen soll und die strengere Lesart
     * trotzdem irgendwo stehen muss.
     *
     * @var list<string>
     */
    private const USER_EXPRESSIONS = [
        'count(distinct user_key) as users',
        'count(distinct case when crashed_count > 0 then user_key end) as crashed_users',
        'count(distinct case when errored_count > 0 or crashed_count > 0 or abnormal_count > 0'
            .' then user_key end) as unhealthy_users',
    ];

    private function apply(Builder $query): Builder
    {
        // Die Grenzen in UTC, egal wie sie hereingereicht wurden: die Fenster
        // liegen in UTC, und die Zeitzone des Betrachters darf die Rasterung
        // nicht verschieben.
        $query
            ->whereIn('project_id', $this->projectIds)
            ->where('bucket_start', '>=', $this->from->utc())
            ->where('bucket_start', '<', $this->to->utc());

        if ($this->environment !== null) {
            $query->where('environment', $this->environment);
        }

        return $query;
    }
}
