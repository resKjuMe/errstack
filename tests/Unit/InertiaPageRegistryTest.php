<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Jeder Seitenname, den ein Controller an Inertia gibt, muss in der Registry in
 * resources/js/app.jsx stehen. Fehlt er dort, wirft der Resolver erst im
 * Browser — die Seite bleibt leer, und weder Tests noch Build merken etwas.
 */
class InertiaPageRegistryTest extends TestCase
{
    public function test_every_rendered_page_is_registered(): void
    {
        $registry = (string) file_get_contents(__DIR__.'/../../resources/js/app.jsx');

        $missing = [];

        foreach ($this->renderedPages() as $page) {
            // Namen mit Pfadanteil stehen als Zeichenkette in der Registry, die
            // wenigen ohne als Kurzschreibweise.
            $registered = str_contains($registry, "'{$page}':")
                || (! str_contains($page, '/') && preg_match("/^\s+{$page},$/m", $registry) === 1);

            if (! $registered) {
                $missing[] = $page;
            }
        }

        $this->assertSame([], $missing, 'Nicht in resources/js/app.jsx registriert: '.implode(', ', $missing));
    }

    /**
     * Alle Seitennamen aus `Inertia::render('…')` in app/ und routes/.
     *
     * @return list<string>
     */
    private function renderedPages(): array
    {
        $pages = [];

        foreach (['app', 'routes'] as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(__DIR__.'/../../'.$directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    "/Inertia::render\(\s*'([^']+)'/",
                    (string) file_get_contents($file->getPathname()),
                    $matches
                );

                foreach ($matches[1] as $page) {
                    $pages[$page] = true;
                }
            }
        }

        $this->assertNotEmpty($pages, 'Keine Inertia-Seiten gefunden — der Suchpfad stimmt nicht.');

        return array_keys($pages);
    }
}
