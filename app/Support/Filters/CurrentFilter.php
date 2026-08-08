<?php

namespace App\Support\Filters;

use App\Http\Middleware\HandleInertiaRequests;
use Closure;
use Illuminate\Http\Request;

/**
 * Der globale Filter dieses Aufrufs, an einer Stelle festgehalten.
 *
 * Er wird zweimal gebraucht und darf trotzdem nur einmal aufgelöst werden: die
 * Seite schränkt ihre Abfrage damit ein, der Rahmen zeichnet die Filterleiste
 * daraus. Das Auflösen liest wählbare Projekte und deren Umgebungen aus der
 * Datenbank — zweimal wäre es derselbe Aufwand ein zweites Mal, und schlimmer:
 * zwei Antworten, die auseinanderlaufen können.
 *
 * Festgehalten wird er an der laufenden Anfrage und nicht in einem
 * Behälter-Singleton. Eine Anfrage ist genau der Gültigkeitsbereich, den er hat;
 * ein Singleton überlebte sie — im Test, in dem mehrere Aufrufe dieselbe
 * Anwendung teilen, mit dem Filter des vorherigen Aufrufs.
 *
 * Zugleich ist die Ablage das Kennzeichen, ob die Seite überhaupt eine
 * Auswertungsseite ist: nur wer den Filter angefordert hat, bekommt die Leiste
 * ({@see HandleInertiaRequests}).
 */
final class CurrentFilter
{
    private const ATTRIBUTE = 'errstack.filter';

    /**
     * Den Filter dieses Aufrufs liefern — beim ersten Mal auflösen, danach den
     * gemerkten.
     *
     * @param  Closure(): GlobalFilter  $resolve
     */
    public static function remember(Request $request, Closure $resolve): GlobalFilter
    {
        $filter = self::of($request);

        if ($filter !== null) {
            return $filter;
        }

        $filter = $resolve();

        $request->attributes->set(self::ATTRIBUTE, $filter);

        return $filter;
    }

    /**
     * Der bereits aufgelöste Filter, oder null, wenn dieser Aufruf keinen
     * angefordert hat.
     */
    public static function of(Request $request): ?GlobalFilter
    {
        $filter = $request->attributes->get(self::ATTRIBUTE);

        return $filter instanceof GlobalFilter ? $filter : null;
    }
}
