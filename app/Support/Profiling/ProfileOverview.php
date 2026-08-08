<?php

namespace App\Support\Profiling;

use App\Models\Profile;
use App\Support\Filters\GlobalFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Die Auswahl der Profile, aus der die Übersichtsseite entsteht.
 *
 * Zwei Fragen werden hier beantwortet, und sie hängen zusammen: „welche Profile
 * gibt es im gewählten Zeitraum" und „wie sieht der übliche Ablauf dieser einen
 * Transaktion aus". Die zweite ist der eigentliche Zweck der Seite — ein
 * einzelnes Profil kann den Ausreißer erwischt haben, hundert übereinandergelegte
 * zeigen das Muster.
 *
 * Die Rahmentabelle und der Baum werden dabei bewusst **nicht** über die
 * Auflistung geladen: eine Liste von hundert Zeilen, die je einen Baum mit
 * tausenden Knoten mitschleppt, ist die Stelle, an der eine Übersichtsseite
 * umfällt. Die Auflistung nimmt deshalb nur die Spalten, die sie zeigt; die
 * Bäume holt sich die Zusammenfassung selbst und nur für die Profile, die sie
 * zusammenlegt.
 */
final class ProfileOverview
{
    /**
     * Wie viele Versionen zur Auswahl stehen.
     *
     * Eine Auswahlliste, keine Versionsgeschichte: für den Vergleich braucht es
     * die, für die es im Zeitraum überhaupt genug Profile gibt, und das sind
     * nach Anzahl sortiert die ersten.
     */
    private const RELEASE_LIMIT = 50;

    /**
     * Spalten der Auflistung — alles außer den beiden schweren.
     *
     * @var list<string>
     */
    private const LIST_COLUMNS = [
        'id', 'project_id', 'transaction_id', 'profile_id', 'trace_id',
        'transaction_name', 'platform', 'environment', 'release',
        'thread_id', 'started_at', 'duration_us', 'sample_count',
    ];

    public function __construct(
        private readonly GlobalFilter $filter,
        private readonly ?string $transactionName = null,
        private readonly ?string $release = null,
    ) {}

    /**
     * Die neuesten Profile im Zeitraum.
     *
     * @return Collection<int, Profile>
     */
    public function profiles(int $limit): Collection
    {
        return $this->query()
            ->select(self::LIST_COLUMNS)
            ->newestFirst()
            ->limit($limit)
            ->get();
    }

    /**
     * Die Zusammenfassung: alle Profile dieser Transaktion übereinandergelegt.
     *
     * `null` ohne gewählte Transaktion. Über verschiedene Transaktionen hinweg
     * zusammenzufassen wäre zwar möglich und wäre unbrauchbar: der Flamegraph
     * zeigte dann den Durchschnitt aus Anmeldeseite und nächtlichem Import, und
     * beide haben miteinander nichts zu tun.
     */
    public function aggregate(): ?CallTree
    {
        if ($this->transactionName === null) {
            return null;
        }

        return CallTree::merge($this->trees($this->release));
    }

    /**
     * Die Versionen, für die es Profile dieser Transaktion gibt — mit Anzahl.
     *
     * Grundlage des Vergleichs zwischen zwei Auslieferungen: erst diese Liste
     * sagt, ob ein Vergleich überhaupt etwas hergibt. Zwei Profile aus 1.4 gegen
     * zweihundert aus 1.3 zu stellen, ergibt eine Aussage über den Zufall.
     *
     * @return list<array{value: string, count: int}>
     */
    public function releases(): array
    {
        if ($this->transactionName === null) {
            return [];
        }

        return $this->query()
            ->whereNotNull('release')
            ->select('release')
            ->selectRaw('count(*) as anzahl')
            ->groupBy('release')
            ->orderByDesc('anzahl')
            ->limit(self::RELEASE_LIMIT)
            ->get()
            ->map(static fn (Profile $profile): array => [
                'value' => (string) $profile->release,
                'count' => (int) $profile->getAttribute('anzahl'),
            ])
            ->values()
            ->all();
    }

    /**
     * Die Bäume, die in eine Zusammenfassung eingehen.
     *
     * @return list<CallTree>
     */
    public function trees(?string $release): array
    {
        $query = $this->query()->select(['id', 'frames', 'tree']);

        if ($release !== null) {
            $query->where('release', $release);
        }

        return $query
            ->newestFirst()
            ->limit(Profile::AGGREGATE_LIMIT)
            ->get()
            ->map(static fn (Profile $profile): CallTree => $profile->callTree())
            ->all();
    }

    /**
     * @return Builder<Profile>
     */
    private function query(): Builder
    {
        $query = $this->filter->apply(Profile::query(), 'started_at');

        if ($this->transactionName !== null) {
            $query->where('transaction_name', $this->transactionName);
        }

        return $query;
    }
}
