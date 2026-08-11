<?php

namespace App\Enums;

use App\Support\Integrations\GitHub\GitHubClient;

/**
 * Ob eine Anbindung noch trägt.
 *
 * Zwei Zustände, und der zweite ist der Grund für die Spalte: ein Token wird
 * zurückgezogen, eine Zugriffsberechtigung entzogen, eine OAuth-App gelöscht —
 * und danach scheitert **jeder** Aufruf, ohne dass jemand etwas geändert hätte.
 * Ohne diesen Zustand bliebe das still: die Commits einer Auslieferung kämen
 * einfach nicht mehr, und das sieht aus wie „diese Version hatte keine".
 *
 * Der Zustand entsteht deshalb dort, wo er auffällt — am fehlgeschlagenen
 * Aufruf (siehe {@see GitHubClient}) — und
 * nicht in einem Prüflauf, der ihn Stunden später bemerkt.
 */
enum IntegrationStatus: string
{
    /**
     * Die Anbindung antwortet.
     *
     * Genauer: der letzte Aufruf hat geantwortet. Eine Zusage für den nächsten
     * ist das nicht und kann es nicht sein — dafür müsste die Anwendung sie im
     * Leerlauf abfragen, und die Antwort wäre trotzdem nur so frisch wie der
     * Abstand dazwischen.
     */
    case Connected = 'connected';

    /**
     * Der Zugang ist weg: GitHub hat mit `401` oder `403` geantwortet.
     *
     * Nicht dasselbe wie „gerade nicht erreichbar". Ein Netzfehler oder ein
     * `500` beim Anbieter geht vorbei und lässt den Zustand stehen; ein
     * abgelehnter Zugang geht nicht von selbst vorbei — er braucht jemanden,
     * der die Anbindung neu verbindet. Nur der zweite Fall gehört in die
     * Oberfläche.
     */
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return __('enums.integration_status.'.$this->value);
    }
}
