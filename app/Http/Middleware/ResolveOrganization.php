<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Bindet die laufende Anfrage an genau eine Organisation — die aus der Adresse.
 *
 * Die Fachseiten liegen unter `/organisationen/{organisation}/…`, damit ein Link
 * für sich steht: wer ihn verschickt, verschickt die Organisation mit, und beim
 * Empfänger öffnet sich dasselbe. Damit das trägt, muss die Organisation aus dem
 * Pfad kommen und nicht aus der zuletzt gewählten. Genau das tut diese Stelle:
 *
 * 1. Steht eine Organisation im Pfad, gilt sie — und nur sie. Wer dort kein
 *    Mitglied ist, bekommt 403 und keine Daten. Die Prüfung steht bewusst hier
 *    und nicht in jedem Controller: eine Seite, die sie vergisst, zeigt sonst
 *    fremde Daten, und das fällt niemandem auf.
 * 2. Die zuletzt gewählte Organisation wird auf die aus der Adresse nachgezogen.
 *    Sie ist damit eine Folge des Aufrufs und nicht seine Grundlage: wer den
 *    Link eines Kollegen öffnet, landet in dessen Organisation, und die
 *    Navigation zeigt danach dieselbe.
 * 3. Der Slug wird als Vorbelegung für `route()` hinterlegt
 *    ({@see URL::defaults()}). Deshalb bleiben die Verlinkungen im Code
 *    unverändert `route('issues.show', $issue)` — ohne die Organisation an jeder
 *    einzelnen Stelle mitzuschleppen. Außerhalb einer Anfrage (Mails,
 *    Warteschlangen-Jobs) gibt es diese Vorbelegung nicht; dort wird die
 *    Organisation ausdrücklich übergeben.
 *
 * Ohne Organisation im Pfad — auf den Seiten, die zu keiner gehören
 * (Organisationsliste, Anmeldung) — wird nur die Vorbelegung gesetzt, damit die
 * Navigation dort trotzdem auf die Fachseiten der aktiven Organisation zeigt.
 */
class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = $this->fromRoute($request);

        if ($organization !== null && $user instanceof User) {
            // Kein 404: dass es diese Organisation gibt, hat die Bindung schon
            // verraten — und ein verschickter Link soll auch sagen können „das
            // gibt es, du darfst nur nicht hinein".
            if (! $organization->hasMember($user)) {
                throw new AccessDeniedHttpException(__('organizations.errors.not_a_member'));
            }

            $user->switchOrganization($organization);
        }

        $current = $organization ?? $user?->resolveCurrentOrganization();

        if ($current !== null) {
            URL::defaults(['organization' => $current->getRouteKey()]);
        }

        return $next($request);
    }

    /**
     * Die Organisation aus dem Pfad, sofern die Route eine trägt.
     *
     * Diese Stelle hängt in der Gruppe „web" und damit **hinter**
     * `SubstituteBindings`: der Parameter ist bereits das Modell. Der Fall „noch
     * ein Slug" ist trotzdem bedacht — auf eine Reihenfolge zu setzen, deren
     * Bruch die Prüfung stillschweigend ausfallen lässt, wäre hier besonders
     * unangenehm.
     */
    private function fromRoute(Request $request): ?Organization
    {
        $value = $request->route()?->parameter('organization');

        if ($value instanceof Organization) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return Organization::query()->where('slug', $value)->first();
        }

        return null;
    }
}
