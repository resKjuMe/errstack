<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * Baut Navigations-Links aus Routen-Namen — für die Hauptnavigation
 * ({@see ShellData}) und für die Unter-Navigation des Einstellungsbereichs
 * ({@see SettingsNav}).
 *
 * Beide Leisten zeichnen nur, was sie bekommen; welche Seiten es gibt,
 * entscheidet diese Stelle. Sie lässt Einträge weg, deren Route (noch) nicht
 * existiert — oder die eine Organisation brauchen, die es gerade nicht gibt.
 * Ein Link auf eine fehlende Route wäre keiner, und ein `route()` darauf wäre
 * eine Ausnahme mitten in der Hülle.
 *
 * Was sie **nicht** tut: Rechte prüfen. Das bleibt bei den Seiten selbst
 * (App\Policies) — dieselbe Aufteilung wie vor dem Umzug in den
 * Einstellungsbereich.
 */
final class NavLinks
{
    /**
     * `params` braucht nur, wer auf eine Route mit Platzhalter zeigt — etwa die
     * Stammdaten der aktiven Organisation.
     *
     * @param  list<array{label: string, route: string, activePattern: string|list<string>, params?: array<array-key, mixed>, icon?: string}>  $entries
     * @return list<array{label: string, href: string, active: bool, icon?: string}>
     */
    public static function build(array $entries): array
    {
        $links = [];

        foreach ($entries as $entry) {
            $route = Route::getRoutes()->getByName($entry['route']);

            if ($route === null) {
                continue;
            }

            if (! self::hasOrganization() && in_array('organization', $route->parameterNames(), true)) {
                continue;
            }

            $link = [
                'label' => $entry['label'],
                'href' => route($entry['route'], $entry['params'] ?? []),
                'active' => request()->routeIs(...(array) $entry['activePattern']),
            ];

            if (isset($entry['icon'])) {
                $link['icon'] = $entry['icon'];
            }

            $links[] = $link;
        }

        return $links;
    }

    /**
     * Steht für diese Anfrage eine Organisation fest? Die Fachseiten liegen unter
     * `/organisationen/{organisation}/…` und die Einstellungen einer Organisation
     * unter `/einstellungen/organisationen/{organisation}/…`; ihre Adressen
     * entstehen aus der Vorbelegung, die App\Http\Middleware\ResolveOrganization
     * hinterlegt. Ohne sie — auf den Gast-Seiten und bei einem Konto ohne
     * Mitgliedschaft — gibt es diese Adressen nicht, und ein Link darauf wäre
     * keiner.
     */
    public static function hasOrganization(): bool
    {
        return filled(URL::getDefaultParameters()['organization'] ?? null);
    }
}
