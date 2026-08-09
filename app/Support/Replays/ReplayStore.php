<?php

namespace App\Support\Replays;

use App\Models\IngestPayload;
use App\Models\Replay;
use App\Models\ReplaySegment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Die Ablage der Bilddaten: schreiben, lesen, wegwerfen.
 *
 * Sie ist die einzige Stelle, die weiß, **wo** ein Film liegt und in welcher
 * Form. Alles andere arbeitet mit {@see ReplaySegment} und fragt hier nach dem
 * Inhalt — dadurch lässt sich das Laufwerk austauschen, ohne die Aufnahme oder
 * die Abspielseite anzufassen.
 *
 * Drei Entscheidungen prägen sie:
 *
 * **Ein Ordner je Aufzeichnung.** Der Pfad ist
 * `<pfad>/<projekt>/<aufzeichnung>/<abschnitt>.json.gz`, obwohl die
 * Abschnittsnummer allein eindeutig wäre. Der Grund ist das Wegwerfen: die
 * Einheit, die eine Aufbewahrungsfrist löscht, ist die Sitzung — und die soll
 * ein Ordner sein und keine Suche über Millionen Dateien.
 *
 * **Auf der Platte liegt eine einzige Form.** Angekommen ist der Abschnitt
 * gepackt, blank, mit zlib oder mit gzip — je nach SDK und Einstellung. Abgelegt
 * wird er als gzip-gepacktes JSON. Das kostet einen Packvorgang je Abschnitt und
 * erspart der Abspielseite die Frage, mit welchem Verfahren ein Abschnitt vor
 * Monaten ankam.
 *
 * **Ausgeliefert wird ohne Umweg über PHP-Feldbäume.** Für das Abspielen wird
 * der Inhalt eines Abschnitts nur entpackt und weitergereicht
 * ({@see segmentJson()}); zerlegt wird er ausschließlich dort, wo etwas
 * ausgewertet werden soll ({@see segmentEvents()}). Eine Sitzung von zehn
 * Minuten sind einige zehntausend Ereignisse — sie in Feldbäume zu verwandeln,
 * nur um sie sofort wieder zu JSON zu machen, wäre die teuerste Zeile dieser
 * Anwendung.
 */
final class ReplayStore
{
    /**
     * Legt einen Abschnitt ab und schreibt die Sitzung fort.
     *
     * Ein zweiter Durchlauf derselben Rohdaten **ersetzt** den Abschnitt. Der
     * Fall ist nicht theoretisch: ein gescheiterter Job wird wiederholt, und
     * nach einer Änderung an der Auswertung sollen sich Rohdaten erneut
     * durchlaufen lassen. Zwei Zeilen für denselben Abschnitt hießen, dieselbe
     * Sekunde zweimal im Film zu haben.
     */
    public function put(Replay $replay, ReplayRecording $recording, ?IngestPayload $payload = null): ReplaySegment
    {
        $content = (string) gzencode($recording->toJson(), 6);
        $path = $this->pathFor($replay, $recording->segmentId);

        $this->disk()->put($path, $content);

        $segment = ReplaySegment::query()
            ->where('replay_id', $replay->id)
            ->where('segment_id', $recording->segmentId)
            ->first() ?? new ReplaySegment;

        // Was der Abschnitt vorher wog, geht aus der Summe der Sitzung wieder
        // heraus. Ohne diesen Abzug würde ein Wiederholungslauf den
        // Speicherbedarf jeder betroffenen Sitzung verdoppeln — und die Grenze,
        // die sich auf diese Summe stützt, träfe irgendwann Sitzungen, die sie
        // nie gerissen haben.
        $previousBytes = $segment->exists ? $segment->size_bytes : 0;
        $previousEvents = $segment->exists ? $segment->event_count : 0;

        $segment->replay_id = $replay->id;
        $segment->project_id = $replay->project_id;
        $segment->ingest_payload_id = $payload?->id;
        $segment->segment_id = $recording->segmentId;
        $segment->path = $path;
        $segment->size_bytes = strlen($content);
        $segment->event_count = count($recording->events);
        $segment->started_at = $recording->startedAt;
        $segment->ended_at = $recording->endedAt;
        $segment->save();

        $this->refresh($replay, $segment, $previousBytes, $previousEvents);

        return $segment;
    }

    /**
     * Der Inhalt eines Abschnitts als JSON — die Form, in der ihn der Browser
     * erwartet.
     *
     * `null`, wenn die Datei nicht mehr da ist. Das ist kein erdachter Fall: die
     * Ablage kann aufgeräumt worden sein, während die Zeile stehen blieb. Ein
     * fehlender Abschnitt ist eine Lücke im Film und kein Grund, das Abspielen
     * abzubrechen.
     */
    public function segmentJson(ReplaySegment $segment): ?string
    {
        $content = $this->disk()->exists($segment->path) ? $this->disk()->get($segment->path) : null;

        if ($content === null || $content === '') {
            return null;
        }

        $json = @gzdecode($content);

        return $json === false ? null : $json;
    }

    /**
     * Die Ereignisse eines Abschnitts als Feld-Baum.
     *
     * Nur für das, was ausgewertet werden soll — die Zeitleiste. Zum Ausliefern
     * ist {@see segmentJson()} der Weg.
     *
     * @return list<array<string, mixed>>
     */
    public function segmentEvents(ReplaySegment $segment): array
    {
        $json = $this->segmentJson($segment);

        if ($json === null) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $event): bool => is_array($event) && ! array_is_list($event),
        ));
    }

    /**
     * Wirft eine Aufzeichnung weg — Bilddaten zuerst, dann die Zeilen.
     *
     * Die Reihenfolge ist Absicht. Bricht es dazwischen ab, steht eine Zeile
     * ohne Dateien da: die Abspielseite zeigt dann eine leere Aufzeichnung, und
     * der nächste Durchlauf des Aufräumens räumt sie weg. Andersherum stünden
     * Dateien ohne Zeile da — und die findet niemand mehr, weil der Weg zu ihnen
     * über die Zeile führte.
     */
    public function forget(Replay $replay): void
    {
        $this->disk()->deleteDirectory($this->directoryFor($replay));

        // Die Abschnitte und Verknüpfungen fallen über den Fremdschlüssel mit.
        $replay->delete();
    }

    /**
     * Wirft alles weg, was zu einem Projekt gehört, das es nicht mehr gibt.
     *
     * Die Zeilen sind beim Löschen des Projekts über den Fremdschlüssel
     * verschwunden — die Dateien nicht, denn eine Kaskade in der Datenbank
     * erreicht kein Laufwerk. Ohne diesen Schritt bliebe der schwerste Teil
     * dieser Anwendung für immer liegen, und zwar unauffindbar: der Weg zu den
     * Dateien führte über die Zeilen.
     *
     * @param  list<int>  $existingProjectIds
     * @return int Wie viele Ordner weggefallen sind.
     */
    public function forgetOrphanedProjects(array $existingProjectIds): int
    {
        $root = $this->root();
        $removed = 0;

        foreach ($this->disk()->directories($root) as $directory) {
            $name = basename($directory);

            if (! ctype_digit($name) || in_array((int) $name, $existingProjectIds, true)) {
                continue;
            }

            $this->disk()->deleteDirectory($directory);
            $removed++;
        }

        return $removed;
    }

    /**
     * Schreibt die Kennzahlen der Sitzung fort, nachdem ein Abschnitt dazukam.
     *
     * Gerechnet und nicht neu gezählt: die Alternative wäre eine Summe über alle
     * Abschnitte bei jedem eintreffenden Abschnitt — bei einer Sitzung mit
     * tausend Abschnitten also tausend Summen über bis zu tausend Zeilen.
     *
     * Die Zeitgrenzen wandern dabei nur nach außen. Abschnitte überholen
     * einander in der Warteschlange, und ein spät eintreffender erster Abschnitt
     * darf den Beginn nicht nach hinten schieben.
     */
    private function refresh(Replay $replay, ReplaySegment $segment, int $previousBytes, int $previousEvents): void
    {
        if ($segment->wasRecentlyCreated) {
            $replay->segment_count++;
        }

        $replay->size_bytes = max(0, $replay->size_bytes - $previousBytes + $segment->size_bytes);
        $replay->event_count = max(0, $replay->event_count - $previousEvents + $segment->event_count);

        if ($segment->started_at->lessThan($replay->started_at)) {
            $replay->started_at = $segment->started_at;
        }

        if ($segment->ended_at->greaterThan($replay->last_segment_at)) {
            $replay->last_segment_at = $segment->ended_at;
        }

        $replay->duration_ms = max(
            $replay->duration_ms,
            $replay->last_segment_at->getTimestampMs() - $replay->started_at->getTimestampMs(),
        );

        $replay->save();
    }

    /**
     * Wohin ein Abschnitt gelegt wird.
     *
     * Die Nummer wird auf sechs Stellen aufgefüllt. Das ist keine Kosmetik: die
     * Ordner werden auch von Hand angesehen, und ohne Auffüllung stünde
     * `10.json.gz` zwischen `1.json.gz` und `2.json.gz` — genau dort, wo jemand
     * die Reihenfolge des Films sucht.
     */
    private function pathFor(Replay $replay, int $segmentId): string
    {
        return $this->directoryFor($replay).'/'.str_pad((string) $segmentId, 6, '0', STR_PAD_LEFT).'.json.gz';
    }

    private function directoryFor(Replay $replay): string
    {
        return $this->root().'/'.$replay->project_id.'/'.$replay->replay_id;
    }

    private function root(): string
    {
        return trim((string) config('replays.path'), '/');
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('replays.disk'));
    }
}
