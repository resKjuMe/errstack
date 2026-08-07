<?php

namespace App\Enums;

/**
 * Art einer protokollierten Verwaltungsaktion. Der gespeicherte Wert ist
 * `bereich.vorgang` und damit auch in einem Export von außen lesbar.
 *
 * Die Liste wächst mit dem Funktionsumfang: kommen Projekte, Schlüssel, Token,
 * Integrationen, Alarm-Regeln oder Aufbewahrungs- und Kontingent-Einstellungen
 * hinzu, bekommt jede ihrer verändernden Aktionen hier einen Fall. Fehler-
 * Aktivitäten gehören nicht hierher — die stehen am Fehler selbst.
 */
enum AuditAction: string
{
    case OrganizationCreated = 'organization.created';
    case OrganizationUpdated = 'organization.updated';

    case InvitationSent = 'invitation.sent';
    case InvitationRoleChanged = 'invitation.role_changed';
    case InvitationRevoked = 'invitation.revoked';
    case InvitationAccepted = 'invitation.accepted';

    case MembershipRoleChanged = 'membership.role_changed';
    case MembershipRemoved = 'membership.removed';
    case MembershipLeft = 'membership.left';

    case TeamCreated = 'team.created';
    case TeamUpdated = 'team.updated';
    case TeamDeleted = 'team.deleted';
    case TeamMemberAdded = 'team.member_added';
    case TeamMemberRemoved = 'team.member_removed';

    public function label(): string
    {
        return match ($this) {
            self::OrganizationCreated => 'Organisation angelegt',
            self::OrganizationUpdated => 'Organisation geändert',
            self::InvitationSent => 'Einladung verschickt',
            self::InvitationRoleChanged => 'Rolle einer Einladung geändert',
            self::InvitationRevoked => 'Einladung zurückgezogen',
            self::InvitationAccepted => 'Einladung angenommen',
            self::MembershipRoleChanged => 'Rolle geändert',
            self::MembershipRemoved => 'Mitglied entfernt',
            self::MembershipLeft => 'Organisation verlassen',
            self::TeamCreated => 'Team angelegt',
            self::TeamUpdated => 'Team geändert',
            self::TeamDeleted => 'Team gelöscht',
            self::TeamMemberAdded => 'Mitglied zu Team hinzugefügt',
            self::TeamMemberRemoved => 'Mitglied aus Team entfernt',
        };
    }

    /**
     * Alle Arten für das Auswahlfeld des Filters, nach Beschriftung sortiert.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $options = array_map(
            fn (self $action): array => ['value' => $action->value, 'label' => $action->label()],
            self::cases(),
        );

        usort($options, fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $options;
    }
}
