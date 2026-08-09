<?php

namespace App\Enums;

use App\Models\Repository;

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
 */
enum IntegrationProvider: string
{
    case GitHub = 'github';

    public function label(): string
    {
        return __('enums.integration_provider.'.$this->value);
    }
}
