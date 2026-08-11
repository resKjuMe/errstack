<?php

namespace App\Support\Integrations\Tickets;

use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\IssueLink;
use App\Models\Organization;
use App\Support\Integrations\Tickets\Jira\JiraTicketProvider;
use App\Support\Integrations\Tickets\Linear\LinearTicketProvider;
use Illuminate\Support\Collection;

/**
 * Welche Klasse einen Anbieter bedient (X4).
 *
 * Die einzige Stelle mit einem `match` über die Anbieter — und deshalb gibt es
 * sie: ohne wäre dieselbe Fallunterscheidung im Controller, in der
 * Warteschlange, im Webhook und in der Ansicht, und beim vierten Anbieter würde
 * man eine davon vergessen.
 *
 * **`null` heißt „nicht (mehr) benutzbar" und ist kein Sonderfall**, sondern der
 * Normalfall bei jeder zweiten Frage: eine Organisation ohne Jira-Zugang, eine
 * Anbindung mit zurückgezogenem Token, eine Verknüpfung, deren Anbindung gelöst
 * wurde. Alle drei sollen nichts tun und nicht scheitern — die Verknüpfung bleibt
 * lesbar, sie wird nur nicht mehr abgeglichen.
 */
final class TicketProviders
{
    /**
     * Der Anbieter zu einer Anbindung — oder `null`.
     *
     * Geprüft wird beides: dass es für diesen Wert eine Klasse gibt (GitHub
     * gehört nicht dazu, siehe {@see IntegrationProvider::isTicketProvider()}),
     * und dass die Anbindung benutzbar ist.
     */
    public static function for(?Integration $integration): ?TicketProvider
    {
        if ($integration === null || ! $integration->isUsable()) {
            return null;
        }

        return match ($integration->provider) {
            IntegrationProvider::Jira => new JiraTicketProvider($integration),
            IntegrationProvider::Linear => new LinearTicketProvider($integration),
            IntegrationProvider::GitHub => null,
        };
    }

    /**
     * Der Anbieter, über den diese Verknüpfung abgeglichen wird.
     *
     * Über die Anbindung an der Zeile und nicht über eine Suche nach dem
     * Anbieter der Organisation: die Verknüpfung sagt selbst, wen man fragen
     * muss, und eine gelöste Anbindung soll nicht durch eine neue ersetzt
     * werden, die zufällig denselben Anbieter bedient.
     */
    public static function forLink(IssueLink $link): ?TicketProvider
    {
        return self::for($link->integration);
    }

    /**
     * Die Ticket-Anbindungen einer Organisation, nach Anbieter geordnet.
     *
     * @return Collection<int, Integration>
     */
    public static function integrationsFor(Organization|int $organization): Collection
    {
        return Integration::query()
            ->where('organization_id', $organization instanceof Organization ? $organization->id : $organization)
            ->whereIn('provider', array_map(
                fn (IntegrationProvider $provider): string => $provider->value,
                IntegrationProvider::ticketProviders(),
            ))
            ->orderBy('provider')
            ->get();
    }
}
