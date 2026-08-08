<?php

namespace App\Support\Issues;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Wer für einen Fehler zuständig ist: eine Person **oder** ein Team.
 *
 * Ein eigener Begriff und nicht zwei Kennungen, die überall nebeneinander
 * durchgereicht werden. Der Unterschied wird an der dritten Stelle sichtbar, an
 * der man ihn braucht: die Suche (`assigned:anna@example.com`), das Formular der
 * Aktionsleiste und die Benachrichtigung stellen dieselbe Frage — „wen meint
 * dieser Text?" —, und ohne diese Klasse stünde die Antwort dreimal da.
 *
 * **Geschrieben wird ein Zuständiger immer als Text**, nie als Kennung. Das ist
 * Absicht: die Suchsprache kennt nur Text, die Auswahlliste soll dieselbe
 * Schreibweise anbieten, die man auch tippen kann, und ein Formular, das eine
 * Kennung schickt, wäre der zweite Weg, dasselbe zu sagen. Die Schreibweisen:
 *
 *   `me`                   — der Betrachter selbst.
 *   `anna@example.com`     — eine Person über ihre E-Mail-Adresse. Der
 *                            zuverlässige Weg, weil sie eindeutig ist.
 *   `Anna Beck`            — eine Person über ihren Namen. Bequem und nicht
 *                            eindeutig: gibt es den Namen zweimal, gilt er als
 *                            nicht auflösbar (siehe {@see resolve()}).
 *   `#Kasse`               — ein Team. Das Rautenzeichen unterscheidet es von
 *                            einer gleichnamigen Person und ist dieselbe
 *                            Schreibweise, die auch Sentry benutzt.
 *
 * **Aufgelöst wird immer gegen genau eine Organisation.** Eine Zuweisung an
 * jemanden, der den Fehler gar nicht sehen darf, wäre eine Auskunft über fremde
 * Projekte — und eine Zuständigkeit, die niemand wahrnehmen kann.
 */
final class IssueAssignee
{
    /** Das Zeichen, an dem ein Team zu erkennen ist. */
    public const TEAM_PREFIX = '#';

    /** Der Text, mit dem der Betrachter sich selbst meint. */
    public const SELF = 'me';

    /**
     * Der Text, mit dem „niemand" gemeint ist — in der Suche (`assigned:none`)
     * und als Wert des Auswahlfeldes.
     */
    public const NOBODY = 'none';

    private function __construct(
        public readonly ?User $user,
        public readonly ?Team $team,
    ) {}

    public static function forUser(User $user): self
    {
        return new self($user, null);
    }

    public static function forTeam(Team $team): self
    {
        return new self(null, $team);
    }

    /**
     * Wen dieser Text meint — oder `null`, wenn niemand gemeint ist.
     *
     * **Mehrdeutig heißt nicht auflösbar**, und das ist der Unterschied zu den
     * Nennungen in einem Kommentar ({@see Mentions}). Dort sind bei zwei
     * gleichnamigen Personen beide gemeint, weil eine überflüssige
     * Benachrichtigung sich selbst erklärt. Eine Zuständigkeit lässt sich nicht
     * verdoppeln: sie ginge an eine der beiden, und welche das war, sähe niemand.
     * Wer den Namen zweimal vergeben hat, nimmt die E-Mail-Adresse.
     *
     * Ein leerer Text und `none` ergeben `null` — „niemand" ist eine gültige
     * Antwort und kein Fehler; wer wissen will, ob der Text überhaupt jemanden
     * bezeichnet, fragt vorher {@see means()}.
     */
    public static function resolve(string $target, Organization $organization, ?User $viewer = null): ?self
    {
        $target = trim($target);

        if ($target === '' || mb_strtolower($target) === self::NOBODY) {
            return null;
        }

        if (mb_strtolower($target) === self::SELF) {
            return $viewer !== null && $organization->hasMember($viewer)
                ? self::forUser($viewer)
                : null;
        }

        if (str_starts_with($target, self::TEAM_PREFIX)) {
            $team = self::team($organization, mb_substr($target, 1));

            return $team === null ? null : self::forTeam($team);
        }

        // Die E-Mail-Adresse zuerst und ohne Ausweichen auf den Namen: wer eine
        // Adresse tippt, meint ein Konto und keine Namensähnlichkeit.
        if (str_contains($target, '@')) {
            $user = self::members($organization)->where('email', $target)->first();

            return $user instanceof User ? self::forUser($user) : null;
        }

        $matches = self::members($organization)
            ->filter(static fn (User $member): bool => mb_strtolower($member->name) === mb_strtolower($target));

        if ($matches->count() === 1) {
            $first = $matches->first();

            return $first instanceof User ? self::forUser($first) : null;
        }

        if ($matches->count() > 1) {
            return null;
        }

        // Zuletzt ein Team ohne Rautenzeichen: „Kasse" ist bequemer als
        // „#Kasse", und solange es keine Person dieses Namens gibt, ist es auch
        // eindeutig.
        $team = self::team($organization, $target);

        return $team === null ? null : self::forTeam($team);
    }

    /**
     * Bezeichnet dieser Text überhaupt jemanden?
     *
     * Die Frage ist nicht dieselbe wie „ließ er sich auflösen": `none` und ein
     * leerer Text meinen ausdrücklich niemanden, ein unbekannter Name dagegen
     * meint jemanden, den es nicht gibt. Der Unterschied entscheidet, ob eine
     * Suche eine Fehlermeldung bekommt oder eine leere Zuständigkeit.
     */
    public static function means(string $target): bool
    {
        $target = trim($target);

        return $target !== '' && mb_strtolower($target) !== self::NOBODY;
    }

    /**
     * Die Spalten, die eine Zuweisung am Eintrag setzt.
     *
     * Immer **beide**, auch die leere: nur so ist die Zusage „höchstens eine
     * Zuständigkeit" eine Eigenschaft des Schreibwegs und nicht eine Hoffnung.
     * `null` als Zuständiger räumt beide.
     *
     * @return array{assigned_user_id: int|null, assigned_team_id: int|null}
     */
    public static function columnsFor(?self $assignee): array
    {
        return [
            'assigned_user_id' => $assignee?->user?->id,
            'assigned_team_id' => $assignee?->team?->id,
        ];
    }

    /**
     * `user` oder `team` — für die Anzeige und für den Vermerk im Verlauf.
     */
    public function kind(): string
    {
        return $this->team !== null ? 'team' : 'user';
    }

    /**
     * Der angezeigte Name — bei einem Team mit dem Rautenzeichen davor, damit
     * er zugleich das ist, was man ins Suchfeld tippen kann.
     */
    public function label(): string
    {
        return $this->team !== null
            ? self::TEAM_PREFIX.$this->team->name
            : (string) $this->user?->name;
    }

    /**
     * Der Text, mit dem sich genau dieser Zuständige wieder benennen lässt.
     *
     * Bei einer Person die E-Mail-Adresse und nicht der Name: sie ist eindeutig,
     * und ein gespeicherter Link soll auch dann noch dieselbe Person meinen,
     * wenn jemand mit gleichem Namen dazukommt.
     */
    public function term(): string
    {
        return $this->team !== null
            ? self::TEAM_PREFIX.$this->team->name
            : (string) $this->user?->email;
    }

    /**
     * Wer benachrichtigt werden soll.
     *
     * Ein Team wird dabei erst **hier** zu seinen Mitgliedern aufgelöst und
     * nicht schon beim Zuweisen: zuständig ist das Team, und wer darin steht,
     * kann sich ändern — dieselbe Entscheidung wie bei den Nennungen
     * ({@see IssueMentionNotifier}).
     *
     * @return list<int>
     */
    public function recipientIds(): array
    {
        if ($this->team !== null) {
            return $this->team->members()
                ->pluck('users.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $this->user === null ? [] : [$this->user->id];
    }

    private static function team(Organization $organization, string $name): ?Team
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        // Der Name ist je Organisation eindeutig (siehe die Migration der
        // Organisations-Tabellen) — hier gibt es die Mehrdeutigkeit also nicht,
        // die bei Personen auftreten kann.
        return Team::query()
            ->where('organization_id', $organization->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();
    }

    /**
     * Die Mitglieder der Organisation — einmal geladen und dann im Speicher
     * durchsucht.
     *
     * Zwei Abfragen (E-Mail, dann Name) wären der naheliegende Weg und der
     * schlechtere: der Vergleich der Namen soll ohne Rücksicht auf Groß- und
     * Kleinschreibung gehen, und was eine Datenbank darunter versteht, hängt an
     * ihrer Sortierfolge — in SQLite (Tests) und MySQL (Betrieb) verschieden.
     *
     * @return Collection<int, User>
     */
    private static function members(Organization $organization): Collection
    {
        return User::query()
            ->select(['users.id', 'users.name', 'users.email'])
            ->join('organization_user', 'organization_user.user_id', '=', 'users.id')
            ->where('organization_user.organization_id', $organization->id)
            ->get();
    }
}
