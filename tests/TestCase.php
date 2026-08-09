<?php

namespace Tests;

use App\Http\Middleware\ResolveOrganization;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\URL;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Jede Seite rendert über das Root-Blade mit @vite. Ohne diesen Aufruf
        // bräuchte die Testsuite ein zuvor gebautes Manifest (public/build) —
        // die Tests sollen aber auch vor dem ersten `npm run build` laufen.
        $this->withoutVite();

        // Der Test-Client gibt sich sonst als englischer Browser aus
        // (Accept-Language: en-us) — und genau dieser Kopf entscheidet seit der
        // Zweisprachigkeit, welche Sprache eine Seite ohne angemeldete Wahl
        // spricht. Ohne die Vorgabe hier hinge jede Textprüfung an einem
        // Symfony-Detail statt an der Anwendung. Wem die Sprache wichtig ist,
        // der gibt den Kopf selbst mit (er schlägt diesen) oder setzt sie am
        // Konto.
        $this->withHeader('Accept-Language', 'de');
    }

    /**
     * Wie im Rahmenwerk — und zusätzlich mit der Organisation dieses Kontos als
     * Vorbelegung für `route()`.
     *
     * Die Fachseiten liegen unter `/organisationen/{organisation}/…` (U5). In
     * der laufenden Anwendung hinterlegt {@see ResolveOrganization} den Slug, und
     * deshalb steht in den Verlinkungen weiterhin nur `route('issues.show',
     * $issue)`. Ein Test baut seine Adressen aber **vor** der Anfrage, also
     * bevor diese Middleware gelaufen ist; ohne dieselbe Vorbelegung fehlte dort
     * die Organisation.
     *
     * Damit steht `$this->actingAs($user)->get(route('issues.index'))` für
     * dasselbe wie in der Anwendung: die Fehlerliste **dieses** Kontos. Wer eine
     * andere Organisation meint — der Zugriff auf eine fremde etwa —, gibt sie
     * ausdrücklich an: `route('issues.index', $fremde)`.
     *
     * Eine vorhandene Vorbelegung wird nie **genommen**, nur gesetzt. Ein Test,
     * der zwischendurch als Außenstehender auftritt („darf nicht"), meint dessen
     * Konto und weiterhin die Adresse der Organisation, um die es geht — genau
     * die Anfrage, die dort 403 bekommen soll.
     *
     * @param  string|null  $guard
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        $organization = $user instanceof User ? $user->resolveCurrentOrganization() : null;

        if ($organization !== null) {
            URL::defaults(['organization' => $organization->getRouteKey()]);
        }

        return parent::actingAs($user, $guard);
    }
}
