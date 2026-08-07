<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Sanitizer;

/**
 * Die Ausführungsstränge zum Zeitpunkt des Fehlers.
 *
 * Bei den einsträngigen Sprachen — PHP, gewöhnliches JavaScript — bleibt der
 * Abschnitt leer. Bei Java, Go, Swift und .NET ist er dagegen oft die einzige
 * Erklärung: der abgestürzte Strang zeigt, *wo* es knallte, die übrigen
 * zeigen, *worauf* alle warteten. Eine Verklemmung ist ohne diesen Abschnitt
 * nicht zu erkennen.
 */
final class Threads
{
    public function __construct(
        private readonly Sanitizer $sanitizer,
        private readonly Frames $frames,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $threads, string $path): array
    {
        $raw = $this->sanitizer->valueList($threads, $path, $this->sanitizer->limits()->threads);

        $normalized = [];

        foreach ($raw as $index => $thread) {
            $entry = $this->thread($thread, $path.'.'.$index);

            if ($entry !== null) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function thread(mixed $thread, string $path): ?array
    {
        $thread = $this->sanitizer->map($thread, $path);

        if ($thread === null) {
            return null;
        }

        $normalized = [];

        // Die Kennung kommt als Zahl und als Zeichenkette; ein Strang darf
        // „0" heißen, und in Sprachen mit langen Kennungen passt sie nicht
        // immer in eine Ganzzahl. Deshalb durchweg als Text — verglichen wird
        // sie nur mit `exception.thread_id`, nie gerechnet.
        foreach (['id', 'name', 'state'] as $field) {
            $value = $this->sanitizer->text($thread[$field] ?? null, $path.'.'.$field, 200);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        foreach (['crashed', 'current', 'main'] as $field) {
            $value = $this->sanitizer->boolean($thread[$field] ?? null, $path.'.'.$field);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        $frames = $this->frames->normalize($thread['stacktrace'] ?? null, $path.'.stacktrace');

        if ($frames !== []) {
            $normalized['frames'] = $frames;
        }

        return $normalized === [] ? null : $normalized;
    }
}
