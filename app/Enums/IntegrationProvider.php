<?php

namespace App\Enums;

use App\Models\Repository;
use App\Support\Integrations\GitHub\GitHubIssueLinks;
use App\Support\Integrations\Tickets\TicketProvider;

/**
 * Der Anbieter, an den eine Organisation angebunden ist.
 *
 * Eine Aufzählung im Code und ein Freitext in der Datenbank — dieselbe
 * Aufteilung wie bei {@see Repository::$provider} und aus demselben
 * Grund: mit jedem weiteren Anbieter (X2) kommt ein Wert dazu, und eine
 * Aufzählung in der Datenbank hieße, dafür jedes Mal eine Wanderung zu
 * schreiben. Was der Code kennt, steht hier.
 *
 * Der Wert ist zugleich der, der am Repository steht: ein Repository, das über
 * die Anbindung hereingekommen ist, trägt `github` statt
 * {@see Repository::PROVIDER_MANUAL} — daran ist es zu erkennen,
 * ohne die Anbindung nachzuladen.
 *
 * **Nicht jeder Anbieter kann dasselbe** (X4). Es sind zwei Fachgebiete, die
 * sich nur zufällig überschneiden: ein Code-Hoster liefert Commits und führt
 * nebenbei Tickets, ein Ticket-System führt Tickets und hat keine Commits. Die
 * beiden Fragen unten sagen, welches Gebiet ein Wert bedient — abgefragt wird
 * das an den Stellen, die sonst je Anbieter ein `match` bräuchten.
 */
enum IntegrationProvider: string
{
    case GitHub = 'github';

    /**
     * Jira Cloud (und Jira Server/Data Center unter derselben Schnittstelle).
     */
    case Jira = 'jira';

    case Linear = 'linear';

    public function label(): string
    {
        return __('enums.integration_provider.'.$this->value);
    }

    /**
     * Ob dieser Anbieter Repositories versorgt — Commits holen, Auslieferungen
     * füllen, verdächtige Commits finden.
     */
    public function hasRepositories(): bool
    {
        return $this === self::GitHub;
    }

    /**
     * Ob dieser Anbieter über die gemeinsame Ticket-Schnittstelle bedient wird
     * (siehe {@see TicketProvider}).
     *
     * **GitHub gehört ausdrücklich nicht dazu**, obwohl es Tickets führt. Seine
     * Anbindung ist mit X1 als Ganzes entstanden — Anmeldung über OAuth,
     * Repository-Auswahl, unterschriebene Meldungen — und wird über
     * {@see GitHubIssueLinks} bedient. Sie
     * nachträglich in die Schnittstelle zu ziehen wäre eine Umbaumaßnahme an
     * fertigem Code, für die es hier keinen Anlass gibt: was beide gemeinsam
     * brauchen, steht in der Verknüpfung (`issue_links`), und die ist längst
     * gemeinsam.
     */
    public function isTicketProvider(): bool
    {
        return $this === self::Jira || $this === self::Linear;
    }

    /**
     * Die Ticket-Anbieter, in der Reihenfolge, in der sie in der Oberfläche
     * stehen.
     *
     * @return list<self>
     */
    public static function ticketProviders(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider): bool => $provider->isTicketProvider()));
    }
}
