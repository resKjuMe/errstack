<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use ReflectionParameter;
use Symfony\Component\HttpFoundation\Response;

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
 * 4. Der Routen-Parameter wird **entfernt**, wo der Controller ihn nicht
 *    verlangt — siehe {@see dropWhereUnwanted()}.
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
        $route = $request->route();
        $organization = $this->fromRoute($route);

        if ($organization !== null && $user instanceof User) {
            // Kein 404: dass es diese Organisation gibt, hat die Bindung schon
            // verraten — und ein verschickter Link soll auch sagen können „das
            // gibt es, du darfst nur nicht hinein".
            //
            // Dieselbe Ausnahme, die auch eine Policy wirft: die Meldung soll
            // nicht davon abhängen, an welcher Stelle die Prüfung sitzt.
            if (! $organization->hasMember($user)) {
                throw new AuthorizationException;
            }

            $user->switchOrganization($organization);
        }

        $current = $organization ?? $user?->resolveCurrentOrganization();

        if ($current !== null) {
            URL::defaults(['organization' => $current->getRouteKey()]);

            // Die aufgelöste Organisation an der Anfrage, damit sie auch nach
            // dem Entfernen des Routen-Parameters zu bekommen ist
            // ({@see CurrentOrganization}).
            $request->attributes->set(CurrentOrganization::ATTRIBUTE, $current);
        }

        if ($organization !== null && $route instanceof Route) {
            $this->dropWhereUnwanted($route);
        }

        return $next($request);
    }

    /**
     * Die Organisation aus dem Pfad, sofern die Route eine trägt.
     *
     * Beide Formen sind bedacht: die Bindung liefert das Modell nur, wenn der
     * Controller einen gleichnamigen, typisierten Parameter hat — sonst steht im
     * Routen-Parameter noch der Slug. Auf eine der beiden Formen zu setzen wäre
     * hier besonders unangenehm: bei der falschen Annahme fiele die
     * Rechteprüfung stillschweigend aus.
     */
    private function fromRoute(?Route $route): ?Organization
    {
        $value = $route?->parameter('organization');

        if ($value instanceof Organization) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return Organization::query()->where('slug', $value)->first();
        }

        return null;
    }

    /**
     * Nimmt `organization` aus den Routen-Parametern, wo der Controller ihn nicht
     * verlangt.
     *
     * Der Grund ist die Art, wie Laravel die Parameter an eine Methode gibt:
     * **der Reihe nach**. `feedback.status` liegt unter
     * `…/{organization}/rueckmeldungen/{userReport}/stand`, und
     * `status(Request $request, UserReport $userReport)` bekäme als zweites
     * Argument den ersten Routen-Parameter — also die Organisation. Ohne diesen
     * Griff müsste jede der rund vierzig Methoden einen Parameter aufnehmen, den
     * sie nicht braucht, nur damit die Reihenfolge wieder stimmt.
     *
     * Entfernt wird erst, nachdem die Organisation aufgelöst und geprüft ist: für
     * die Adressen bleibt sie als Vorbelegung hinterlegt, für die Anwendung an
     * der Anfrage. Wer sie ausdrücklich als Parameter führt — die
     * Organisationsverwaltung, die Projekte, die Benachrichtigungswege —, behält
     * sie unverändert.
     */
    private function dropWhereUnwanted(Route $route): void
    {
        foreach ($route->signatureParameters() as $parameter) {
            if ($parameter instanceof ReflectionParameter && $parameter->getName() === 'organization') {
                return;
            }
        }

        $route->forgetParameter('organization');
    }
}
