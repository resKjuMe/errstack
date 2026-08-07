<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Sanitizer;

/**
 * Welches SDK die Meldung geschickt hat.
 *
 * Klingt nach Beiwerk, ist aber die erste Frage bei jeder Meldung, die nicht
 * stimmt: fehlt der Stacktrace bei allen Meldungen einer bestimmten
 * SDK-Fassung, liegt es nicht an der Anwendung. Ohne Name und Fassung ist das
 * nicht zu erkennen.
 */
final class Sdk
{
    public function __construct(
        private readonly Sanitizer $sanitizer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function normalize(mixed $sdk, string $path): ?array
    {
        $sdk = $this->sanitizer->map($sdk, $path);

        if ($sdk === null) {
            return null;
        }

        $normalized = [];

        foreach (['name', 'version'] as $field) {
            $value = $this->sanitizer->text($sdk[$field] ?? null, $path.'.'.$field, 200);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        // Welche Ergänzungen aktiv waren — die Erklärung dafür, warum eine
        // Meldung Spuren aus dem Rahmenwerk trägt und die nächste nicht.
        $integrations = [];

        foreach ($this->sanitizer->items($sdk['integrations'] ?? null, $path.'.integrations', 100) as $index => $integration) {
            $value = $this->sanitizer->text($integration, $path.'.integrations.'.$index, 200);

            if ($value !== null) {
                $integrations[] = $value;
            }
        }

        if ($integrations !== []) {
            $normalized['integrations'] = $integrations;
        }

        $packages = [];

        foreach ($this->sanitizer->items($sdk['packages'] ?? null, $path.'.packages', 100) as $index => $package) {
            $package = $this->sanitizer->map($package, $path.'.packages.'.$index);

            if ($package === null) {
                continue;
            }

            $name = $this->sanitizer->text($package['name'] ?? null, $path.'.packages.'.$index.'.name', 200);
            $version = $this->sanitizer->text($package['version'] ?? null, $path.'.packages.'.$index.'.version', 100);

            if ($name !== null) {
                $packages[] = array_filter(['name' => $name, 'version' => $version], static fn (?string $v): bool => $v !== null);
            }
        }

        if ($packages !== []) {
            $normalized['packages'] = $packages;
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Die Kurzform „name/version", wie sie in der Eingangsablage steht.
     *
     * Zwei Felder in einer Spalte, weil sie nur gemeinsam etwas aussagen und
     * immer zusammen abgefragt werden — getrennt bräuchte jede Auswertung eine
     * Verknüpfung für eine Angabe, die auf jeden Bildschirm als ein Wort passt.
     *
     * @param  array<string, mixed>|null  $sdk
     */
    public static function identifier(?array $sdk): ?string
    {
        if ($sdk === null) {
            return null;
        }

        $name = $sdk['name'] ?? null;
        $version = $sdk['version'] ?? null;

        if (! is_string($name)) {
            return null;
        }

        return is_string($version) ? $name.'/'.$version : $name;
    }
}
