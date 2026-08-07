<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
