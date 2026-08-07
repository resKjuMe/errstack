<?php

namespace App\Enums;

use App\Models\ApiToken;

/**
 * Geltungsbereich eines API-Tokens ("scope"). Ein Token darf nur, was in seinen
 * Geltungsbereichen steht — und nie mehr, als die Rolle des Ausstellers in der
 * Organisation hergibt (siehe minimumRole() und EnsureApiScope).
 *
 * Die Namen sind absichtlich die von Sentry (`project:read`, `event:write`, …):
 * damit sentry-cli und die offiziellen SDKs später unverändert gegen Errstack
 * laufen (X6), ohne eine Übersetzungstabelle dazwischen.
 *
 * Innerhalb einer Ressource gelten die Bereiche als Rangfolge: `write` schließt
 * `read` ein, `admin` schließt beides ein. Geprüft wird das in
 * {@see ApiToken::can()} über covers().
 */
enum ApiScope: string
{
    case OrgRead = 'org:read';
    case OrgWrite = 'org:write';

    case MemberRead = 'member:read';
    case MemberWrite = 'member:write';

    case TeamRead = 'team:read';
    case TeamWrite = 'team:write';

    case ProjectRead = 'project:read';
    case ProjectWrite = 'project:write';
    case ProjectAdmin = 'project:admin';

    case EventRead = 'event:read';
    case EventWrite = 'event:write';

    case IssueRead = 'issue:read';
    case IssueWrite = 'issue:write';

    case AlertsRead = 'alerts:read';
    case AlertsWrite = 'alerts:write';

    public function label(): string
    {
        return match ($this) {
            self::OrgRead => 'Organisation lesen',
            self::OrgWrite => 'Organisation ändern',
            self::MemberRead => 'Mitglieder lesen',
            self::MemberWrite => 'Mitglieder verwalten',
            self::TeamRead => 'Teams lesen',
            self::TeamWrite => 'Teams verwalten',
            self::ProjectRead => 'Projekte lesen',
            self::ProjectWrite => 'Projekte ändern',
            self::ProjectAdmin => 'Projekte verwalten',
            self::EventRead => 'Ereignisse lesen',
            self::EventWrite => 'Ereignisse einliefern',
            self::IssueRead => 'Fehler lesen',
            self::IssueWrite => 'Fehler bearbeiten',
            self::AlertsRead => 'Alarme lesen',
            self::AlertsWrite => 'Alarme verwalten',
        };
    }

    /**
     * Überschrift, unter der der Bereich in der Auswahl steht.
     */
    public function group(): string
    {
        return match ($this->resource()) {
            'org' => 'Organisation',
            'member' => 'Mitglieder',
            'team' => 'Teams',
            'project' => 'Projekte',
            'event' => 'Ereignisse',
            'issue' => 'Fehler',
            'alerts' => 'Alarme',
            default => 'Weitere',
        };
    }

    /**
     * Mindestrolle, die jemand in der Organisation haben muss, damit ein Token
     * diesen Bereich wirklich nutzen darf. Zwei Wirkungen: die Auswahl beim
     * Anlegen zeigt nur Erlaubtes, und ein bestehendes persönliches Token
     * verliert seine Rechte, sobald die Rolle sinkt.
     */
    public function minimumRole(): OrganizationRole
    {
        return match ($this) {
            self::OrgRead,
            self::MemberRead,
            self::TeamRead,
            self::ProjectRead,
            self::EventRead,
            self::IssueRead,
            self::AlertsRead => OrganizationRole::Viewer,

            self::ProjectWrite,
            self::EventWrite,
            self::IssueWrite,
            self::AlertsWrite => OrganizationRole::Member,

            self::OrgWrite,
            self::MemberWrite,
            self::TeamWrite,
            self::ProjectAdmin => OrganizationRole::Admin,
        };
    }

    /**
     * Ressource vor dem Doppelpunkt (`project` in `project:read`).
     */
    public function resource(): string
    {
        return explode(':', $this->value, 2)[0];
    }

    /**
     * Tätigkeit hinter dem Doppelpunkt (`read`, `write`, `admin`).
     */
    public function action(): string
    {
        return explode(':', $this->value, 2)[1] ?? 'read';
    }

    /**
     * Deckt dieser Bereich den verlangten ab? Nur innerhalb derselben Ressource,
     * und nur nach oben: `project:write` deckt `project:read`, aber nicht
     * `issue:read`.
     */
    public function covers(self $needed): bool
    {
        return $this->resource() === $needed->resource()
            && $this->rank() >= $needed->rank();
    }

    /**
     * Alle Bereiche in der Reihenfolge der Aufzählung.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Alle Bereichs-Namen — für Validierungsregeln.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $scope): string => $scope->value, self::cases());
    }

    private function rank(): int
    {
        return match ($this->action()) {
            'admin' => 30,
            'write' => 20,
            default => 10,
        };
    }
}
