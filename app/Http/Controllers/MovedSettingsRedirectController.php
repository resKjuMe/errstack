<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Die abgelösten Adressen der Verwaltungsseiten. Bis U6 lagen sie verstreut
 * neben den Fachseiten (`/organisationen/…`, `/projekte`, `/zugriffstoken`,
 * `/profile`); seither liegen sie gebündelt unter `/einstellungen/…`.
 *
 * Sie stehen in Lesezeichen und in schon verschickten Links und führen deshalb
 * weiterhin ans Ziel.
 *
 * Weitergeleitet wird auf der Ebene des Pfad-Anfangs und nicht Route für Route:
 * die neue Adresse ist die alte mit einem anderen Anfang, und das gilt für jeden
 * Unterpfad — auch für die, die erst später dazukommen. Eine Liste einzelner
 * Weiterleitungen wäre genau die Liste, die beim nächsten neuen Unterpfad
 * niemand mitzieht. Was es unter der neuen Adresse nicht gibt, endet dort in
 * einem 404 — die Auskunft „gibt es nicht" gibt die neue Adresse, nicht diese
 * Stelle.
 *
 * Die Abfrage-Parameter bleiben unverändert daran hängen: in ihnen steckt etwa
 * die Auswahl im Änderungsprotokoll, und ein Link ohne sie zeigt etwas anderes
 * als der, den jemand verschickt hat.
 *
 * Dauerhaft (301), wie für abgelöste Adressen üblich: die Zuordnung hängt an
 * nichts, was sich noch ändern könnte — anders als beim
 * {@see LegacyOrganizationRedirectController}, dessen Ziel die aktive
 * Organisation nennt.
 */
class MovedSettingsRedirectController extends Controller
{
    /**
     * Alter Pfad-Anfang => neuer Pfad-Anfang.
     *
     * Die meisten Einträge setzen nur `einstellungen/` davor. Zwei nicht: das
     * eigene Konto hat im neuen Bereich eine eigene Gruppe bekommen, und die
     * eigenen Benachrichtigungs-Einstellungen heißen dort `eigene` — unter
     * `einstellungen/` wäre `…/einstellungen` eine Adresse, die sich selbst
     * wiederholt.
     *
     * Gewinnt der längste passende Eintrag ({@see target()}), damit die beiden
     * Sonderfälle vor ihrem allgemeineren Nachbarn greifen.
     *
     * @var array<string, string>
     */
    private const MOVED = [
        'organisationen' => 'einstellungen/organisationen',
        'projekte' => 'einstellungen/projekte',
        'teams' => 'einstellungen/teams',
        'benachrichtigungen' => 'einstellungen/benachrichtigungen',
        'benachrichtigungen/einstellungen' => 'einstellungen/benachrichtigungen/eigene',
        'zugriffstoken' => 'einstellungen/konto/zugriffstoken',
        'profile' => 'einstellungen/konto/profil',
    ];

    /**
     * Die alten Wurzelpfade, für die eine Weiterleitung registriert wird
     * ({@see routes/legacy.php}). Die feineren Einträge aus {@see MOVED} liegen
     * unter einem davon und brauchen keine eigene Route.
     *
     * @return list<string>
     */
    public static function roots(): array
    {
        return ['organisationen', 'projekte', 'teams', 'benachrichtigungen', 'zugriffstoken', 'profile'];
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $target = URL::to($this->target($request->path()));
        $query = $request->getQueryString();

        return redirect()->to($query === null ? $target : $target.'?'.$query, 301);
    }

    /**
     * Der neue Pfad zu einem alten: der längste passende Anfang wird ersetzt,
     * der Rest bleibt.
     */
    private function target(string $path): string
    {
        $matches = array_filter(
            array_keys(self::MOVED),
            fn (string $old): bool => $path === $old || str_starts_with($path, $old.'/'),
        );

        if ($matches === []) {
            return $path;
        }

        usort($matches, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $old = $matches[0];

        return self::MOVED[$old].substr($path, strlen($old));
    }
}
