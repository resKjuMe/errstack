<?php

namespace App\Support\Ingest\Normalization\Sections;

use App\Support\Ingest\Normalization\Notes;
use App\Support\Ingest\Normalization\Sanitizer;
use App\Support\Ingest\Normalization\Timestamps;

/**
 * Die Spuren: was vor dem Fehler geschah.
 *
 * Ein Stacktrace sagt, wo der Fehler auftrat; die Spuren sagen, wie es dazu
 * kam — der Klick, die Datenbankabfrage, die Weiterleitung, die
 * Protokollzeile. Bei Fehlern, die sich nicht nachstellen lassen, sind sie
 * regelmäßig das Einzige, woraus sich der Ablauf rekonstruieren lässt.
 *
 * Die Reihenfolge ist zeitlich aufsteigend; die letzte Spur ist die, die dem
 * Fehler unmittelbar vorausging. Wird die Liste gekappt, fällt deshalb das
 * **Älteste** weg und nicht das Jüngste — anders als bei Stacktrace und
 * Ursachenkette, wo hinten abgeschnitten wird. Der Unterschied ist kein
 * Versehen: dort steht das Wichtige am Ende, hier ebenfalls, aber die Liste
 * wächst am Ende weiter.
 */
final class Breadcrumbs
{
    public function __construct(
        private readonly Sanitizer $sanitizer,
        private readonly Timestamps $timestamps,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $breadcrumbs, string $path, Notes $notes): array
    {
        if (is_array($breadcrumbs) && ! array_is_list($breadcrumbs) && array_key_exists('values', $breadcrumbs)) {
            $breadcrumbs = $breadcrumbs['values'];
        }

        // Ungekappt geholt und erst danach beschnitten: `items()` schneidet
        // hinten ab, hier muss vorn abgeschnitten werden.
        $raw = $this->sanitizer->items($breadcrumbs, $path, PHP_INT_MAX);

        $limit = $this->sanitizer->limits()->breadcrumbs;

        if (count($raw) > $limit) {
            $notes->truncated($path);

            $raw = array_slice($raw, -$limit);
        }

        $normalized = [];

        foreach ($raw as $index => $breadcrumb) {
            $entry = $this->breadcrumb($breadcrumb, $path.'.'.$index, $notes);

            if ($entry !== null) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function breadcrumb(mixed $breadcrumb, string $path, Notes $notes): ?array
    {
        $breadcrumb = $this->sanitizer->map($breadcrumb, $path);

        if ($breadcrumb === null) {
            return null;
        }

        $normalized = [];

        foreach (['type', 'category'] as $field) {
            $value = $this->sanitizer->text($breadcrumb[$field] ?? null, $path.'.'.$field, 200);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        $message = $this->sanitizer->text($breadcrumb['message'] ?? null, $path.'.message');

        if ($message !== null) {
            $normalized['message'] = $message;
        }

        // Der Grad einer Spur ist derselbe wie der einer Meldung, aber ohne
        // Vorgabewert: eine Spur ohne Angabe ist eine Notiz, kein Fehler.
        $level = $this->sanitizer->text($breadcrumb['level'] ?? null, $path.'.level', 20);

        if ($level !== null) {
            $normalized['level'] = strtolower($level);
        }

        $timestamp = $this->timestamps->optional($breadcrumb['timestamp'] ?? null, $path.'.timestamp', $notes);

        if ($timestamp !== null) {
            $normalized['timestamp'] = $timestamp->toIso8601ZuluString();
        }

        $data = $this->sanitizer->map($breadcrumb['data'] ?? null, $path.'.data');

        if ($data !== null) {
            $normalized['data'] = $this->sanitizer->freeform($data, $path.'.data');
        }

        return $normalized === [] ? null : $normalized;
    }
}
