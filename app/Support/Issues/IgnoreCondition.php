<?php

namespace App\Support\Issues;

use App\Enums\IgnoreOutcome;
use App\Enums\IssueIgnoreMode;
use App\Models\Issue;
use Carbon\CarbonImmutable;

/**
 * Die Bedingung, unter der eine Stummschaltung endet — und ihre Auswertung.
 *
 * **Ohne Datenbank.** Die Klasse bekommt Zahlen und gibt ein Urteil zurück; sie
 * liest nichts nach und schreibt nichts. Das ist keine Formsache: die
 * Auswertung läuft bei **jedem** eingehenden Ereignis eines stummgeschalteten
 * Eintrags, und eine Abfrage an dieser Stelle wäre bei einer Fehlerflut genau
 * die Last, die das Stummschalten vermeiden soll. Zugleich ist die Regel damit
 * ohne Testbestand prüfbar — vier Zahlen und eine Uhr.
 *
 * **Gemessen wird gegen den Stand beim Stummschalten**, nicht gegen null.
 * „Hundert weitere Ereignisse" heißt hundert **ab jetzt**; ein Eintrag mit
 * 40.000 Auftreten wäre sonst in derselben Sekunde wieder wach, in der jemand
 * ihn stummgeschaltet hat.
 *
 * **Das Zeitfenster wird zurückgesetzt und nicht summiert.** „100 in einer
 * Stunde" fragt nach einer Welle. Zählte man einfach ab dem Stummschalten
 * weiter, wäre die Schwelle nach genügend Zeit auch bei ruhigem Dauerrauschen
 * erreicht — die Bedingung hätte dann nur noch die Bedeutung „irgendwann". Ist
 * die Stunde ohne Erreichen der Schwelle vorbei, beginnt sie deshalb neu
 * ({@see IgnoreOutcome::Restart}).
 */
final class IgnoreCondition
{
    public function __construct(
        /** Schwelle in Ereignissen — `null`, wenn nicht danach gemessen wird. */
        public readonly ?int $count,
        /** Das Fenster in Minuten — `null` heißt „seit dem Stummschalten". */
        public readonly ?int $windowMinutes,
        /** Schwelle in Betroffenen — `null`, wenn nicht danach gemessen wird. */
        public readonly ?int $users,
        /** Beginn der laufenden Messung. */
        public readonly ?CarbonImmutable $since,
        /** `times_seen` zu diesem Beginn. */
        public readonly int $timesSeenAtStart,
        /** `users_seen` zu diesem Beginn. */
        public readonly int $usersSeenAtStart,
    ) {}

    public static function fromIssue(Issue $issue): self
    {
        return new self(
            count: $issue->ignore_count,
            windowMinutes: $issue->ignore_window_minutes,
            users: $issue->ignore_users,
            since: $issue->ignored_at,
            timesSeenAtStart: (int) $issue->ignore_times_seen,
            usersSeenAtStart: (int) $issue->ignore_users_seen,
        );
    }

    /**
     * Die Angaben, die zu einer gewählten Art gehören — fertig zum Schreiben an
     * den Eintrag.
     *
     * Sie stehen hier und nicht im Controller, weil sie zur Regel gehören: dass
     * „bis es wieder auftritt" dasselbe ist wie „bis ein weiteres Ereignis", ist
     * eine Aussage über die Bedingung und keine über das Formular.
     *
     * @return array{count: int|null, window: int|null, users: int|null}
     */
    public static function columnsFor(IssueIgnoreMode $mode, ?int $count, ?int $window): array
    {
        return match ($mode) {
            IssueIgnoreMode::Forever => ['count' => null, 'window' => null, 'users' => null],

            // Ein einziges weiteres Ereignis genügt. Ausdrücklich als Schwelle
            // von eins und nicht als vierter Sonderweg durch die Auswertung —
            // dieselbe Regel, andere Zahl.
            IssueIgnoreMode::UntilRecurrence => ['count' => 1, 'window' => null, 'users' => null],

            IssueIgnoreMode::UntilCount => ['count' => $count, 'window' => $window, 'users' => null],

            IssueIgnoreMode::UntilUsers => ['count' => null, 'window' => null, 'users' => $count],
        };
    }

    /**
     * Gilt dauerhaft, bis jemand sie von Hand aufhebt?
     */
    public function isPermanent(): bool
    {
        return $this->count === null && $this->users === null;
    }

    /**
     * Was mit dem Eintrag geschehen soll, nachdem ein weiteres Ereignis gezählt
     * wurde.
     *
     * Die Reihenfolge der Prüfungen ist Absicht: **erst** die Schwelle, dann der
     * Ablauf des Fensters. Das hundertste Ereignis kann in derselben Sekunde
     * eintreffen, in der die Stunde endet — und dann ist die Welle da gewesen,
     * nicht verpasst.
     */
    public function evaluate(int $timesSeen, int $usersSeen, CarbonImmutable $now): IgnoreOutcome
    {
        if ($this->isPermanent()) {
            return IgnoreOutcome::Keep;
        }

        if ($this->users !== null) {
            // Betroffene werden am Eintrag insgesamt gezählt und nicht je
            // Zeitfenster; die Bedingung kennt deshalb keines (siehe
            // IssueIgnoreMode::allowsWindow()).
            return $usersSeen - $this->usersSeenAtStart >= $this->users
                ? IgnoreOutcome::Wake
                : IgnoreOutcome::Keep;
        }

        if ($this->count !== null && $timesSeen - $this->timesSeenAtStart >= $this->count) {
            return IgnoreOutcome::Wake;
        }

        if ($this->windowMinutes !== null && $this->since !== null
            && $this->since->addMinutes($this->windowMinutes)->lessThanOrEqualTo($now)) {
            return IgnoreOutcome::Restart;
        }

        return IgnoreOutcome::Keep;
    }
}
