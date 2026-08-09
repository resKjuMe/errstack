<?php

namespace App\Http\Middleware;

use App\Support\ShellData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Markiert die laufende Anfrage als Einstellungsseite.
 *
 * Der Einstellungsbereich ist keine eigene Anwendung, sondern eine Ecke
 * derselben: dieselbe Seitenleiste, dieselbe Organisation, nur ein anderer
 * Rahmen darin — eine eigene Unter-Navigation und keine Filterleiste. Woran die
 * Hülle das erkennt, steht damit an genau einer Stelle: an der Routen-Gruppe in
 * routes/settings.php. Eine Liste von Routen-Namen anderswo wäre die Liste, die
 * beim nächsten neuen Einstellungsformular niemand nachträgt.
 *
 * Gelesen wird die Marke von {@see ShellData::build()} — dort
 * entscheidet sie, ob die Nutzlast eine Unter-Navigation trägt.
 */
class SettingsArea
{
    /**
     * Schlüssel der Marke an der Anfrage.
     */
    public const ATTRIBUTE = 'settings_area';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, true);

        return $next($request);
    }

    /**
     * Liegt die laufende Anfrage im Einstellungsbereich?
     *
     * Über die Anfrage und nicht über einen statischen Zustand: in den Tests
     * laufen mehrere Anfragen in einem Prozess, und ein einmal gesetztes Flag
     * bliebe für die nächste stehen.
     */
    public static function active(): bool
    {
        return request()->attributes->getBoolean(self::ATTRIBUTE);
    }
}
