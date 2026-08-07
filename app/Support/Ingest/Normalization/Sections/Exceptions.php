<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Sanitizer;

/**
 * Die Ausnahme und ihre Ursachen.
 *
 * Eine geworfene Ausnahme trägt oft eine zweite in sich („caused by"), und
 * diese wieder eine — der Verbindungsfehler ganz unten ist die eigentliche
 * Ursache, die Anwendungsausnahme ganz oben das, was der Nutzer gemerkt hat.
 * Die Kette ist deshalb kein Beiwerk, sondern die Diagnose selbst.
 *
 * **Die Reihenfolge bleibt, wie sie kam.** Sentry zählt von der ältesten
 * Ursache zur zuletzt geworfenen Ausnahme; die letzte ist damit die, die die
 * Anwendung gesehen hat. Wer die Liste umdreht — auch nur, um sie „richtig
 * herum" anzuzeigen —, kehrt Ursache und Wirkung um, und ab I5 hinge daran
 * auch die Zuordnung zur Fehlergruppe.
 */
final class Exceptions
{
    public function __construct(
        private readonly Sanitizer $sanitizer,
        private readonly Frames $frames,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $exception, string $path): array
    {
        $raw = $this->sanitizer->valueList($exception, $path, $this->sanitizer->limits()->exceptions);

        $exceptions = [];

        foreach ($raw as $index => $entry) {
            $normalized = $this->exception($entry, $path.'.'.$index);

            if ($normalized !== null) {
                $exceptions[] = $normalized;
            }
        }

        return $exceptions;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exception(mixed $exception, string $path): ?array
    {
        $exception = $this->sanitizer->map($exception, $path);

        if ($exception === null) {
            return null;
        }

        $normalized = [];

        foreach (['type', 'value', 'module'] as $field) {
            $value = $this->sanitizer->text($exception[$field] ?? null, $path.'.'.$field);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        $threadId = $this->sanitizer->text($exception['thread_id'] ?? null, $path.'.thread_id', 100);

        if ($threadId !== null) {
            $normalized['thread_id'] = $threadId;
        }

        $mechanism = $this->mechanism($exception['mechanism'] ?? null, $path.'.mechanism');

        if ($mechanism !== null) {
            $normalized['mechanism'] = $mechanism;
        }

        $frames = $this->frames->normalize($exception['stacktrace'] ?? null, $path.'.stacktrace');

        if ($frames !== []) {
            $normalized['frames'] = $frames;
        }

        // Ohne Art, Text und Stacktrace bleibt nichts, was eine Ausnahme
        // ausmacht. Ein solcher Eintrag entsteht durch ein leeres Objekt in der
        // Liste; ihn zu behalten hieße, eine Ursache vorzutäuschen, über die
        // nichts bekannt ist.
        return $normalized === [] ? null : $normalized;
    }

    /**
     * Wie die Ausnahme zu uns kam: unbehandelt aus dem Fehler-Auffangnetz, von
     * Hand gemeldet, aus einem Signal des Betriebssystems.
     *
     * Vor allem `handled` zählt: eine unbehandelte Ausnahme hat die Anfrage
     * abgebrochen, eine behandelte wurde vom Code aufgefangen. Beide gleich zu
     * behandeln, hieße Abstürze und Notizen in denselben Topf zu werfen.
     *
     * @return array<string, mixed>|null
     */
    private function mechanism(mixed $mechanism, string $path): ?array
    {
        $mechanism = $this->sanitizer->map($mechanism, $path);

        if ($mechanism === null) {
            return null;
        }

        $normalized = [];

        foreach (['type', 'description', 'help_link', 'source'] as $field) {
            $value = $this->sanitizer->text($mechanism[$field] ?? null, $path.'.'.$field);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        foreach (['handled', 'synthetic'] as $field) {
            $value = $this->sanitizer->boolean($mechanism[$field] ?? null, $path.'.'.$field);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        $data = $this->sanitizer->map($mechanism['data'] ?? null, $path.'.data');

        if ($data !== null) {
            $normalized['data'] = $this->sanitizer->freeform($data, $path.'.data');
        }

        $meta = $this->sanitizer->map($mechanism['meta'] ?? null, $path.'.meta');

        if ($meta !== null) {
            $normalized['meta'] = $this->sanitizer->freeform($meta, $path.'.meta');
        }

        return $normalized === [] ? null : $normalized;
    }
}
