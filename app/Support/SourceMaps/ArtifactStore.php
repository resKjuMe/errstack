<?php

namespace App\Support\SourceMaps;

use App\Enums\ReleaseArtifactKind;
use App\Enums\SymbolicationStatus;
use App\Models\EventSymbolication;
use App\Models\Release;
use App\Models\ReleaseArtifact;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Die Ablage der Bauartefakte: hochladen, lesen, löschen.
 *
 * Sie ist die einzige Stelle, die weiß, **wo** der Inhalt liegt. Alles andere
 * arbeitet mit {@see ReleaseArtifact} und fragt hier nach dem Inhalt — dadurch
 * lässt sich das Laufwerk austauschen, ohne die Zuordnung oder die
 * Rückübersetzung anzufassen.
 *
 * Zwei Entscheidungen prägen sie:
 *
 * **Der Ablagepfad ist die Prüfsumme.** Zwei Uploads derselben Datei belegen
 * einmal Platz. Das ist kein Sparen um seiner selbst willen: eine
 * Auslieferungs-Pipeline, die nach einem Fehlschlag noch einmal läuft, lädt
 * dieselben zweihundert Dateien ein zweites Mal hoch, und ohne
 * Inhaltsadressierung wäre jede Wiederholung eine Verdopplung.
 *
 * **Gelöscht wird die Zeile, nicht unbedingt die Datei.** Solange eine andere
 * Zeile auf dieselbe Prüfsumme zeigt — dieselbe Datei unter zwei Pfaden oder in
 * zwei Versionen —, bleibt der Inhalt liegen. Andernfalls wäre das Löschen eines
 * Pfades das stille Entwerten eines anderen.
 */
final class ArtifactStore
{
    /**
     * Legt ein Artefakt ab.
     *
     * Ein zweiter Upload unter demselben Pfad **ersetzt** die Zeile. Das ist die
     * Anforderung und nicht Nachsicht: die Pipeline läuft noch einmal, und ein
     * Konflikt an dieser Stelle wäre ein roter Bauschritt für einen Vorgang, der
     * genau das Richtige tut.
     *
     * Kind, Debug-Kennung und Kartenverweis werden aus dem **Inhalt** gelesen und
     * nicht erfragt. Die Angaben stehen dort, wo sie der Bauvorgang hingeschrieben
     * hat; ein Formularfeld dafür wäre eine zweite Wahrheit — mit der Aussicht,
     * dass beide eines Tages auseinanderlaufen. Eine ausdrücklich mitgeschickte
     * Kennung gewinnt trotzdem: sie kommt von dem Werkzeug, das den Bau kennt.
     */
    public function put(Release $release, string $name, string $content, ?string $debugId = null, ?string $sourceMapRef = null): ReleaseArtifact
    {
        $checksum = sha1($content);
        $path = $this->pathFor($release, $checksum);

        if (! $this->disk()->exists($path)) {
            $this->disk()->put($path, $content);
        }

        $isSourceMap = SourceMap::looksLikeSourceMap($content);

        if ($isSourceMap) {
            $map = SourceMap::fromJson($content);
            $debugId ??= $map?->debugId();
        } else {
            $debugId ??= SourceMap::debugIdFrom($content);
            $sourceMapRef ??= SourceMap::referenceFrom($content);
        }

        $attributes = [
            'project_id' => $release->project_id,
            'kind' => $isSourceMap ? ReleaseArtifactKind::SourceMap : ReleaseArtifactKind::Bundle,
            'debug_id' => ReleaseArtifact::normalizeDebugId($debugId),
            'source_map_ref' => $isSourceMap ? null : $sourceMapRef,
            'size' => strlen($content),
            'checksum' => $checksum,
            'path' => $path,
        ];

        // `createOrFirst()` und nicht `updateOrCreate()`: eine Pipeline lädt
        // parallel hoch, und beim Wiederholungslauf treffen zwei Aufrufe mit
        // demselben Pfad aufeinander. `updateOrCreate()` sieht dann beidesmal
        // „gibt es noch nicht" und lässt einen von beiden am eindeutigen Index
        // auflaufen — mitten in einem Bauschritt, der genau das Richtige tut.
        // Hier entscheidet die Datenbank: wer verliert, bekommt die Zeile des
        // Gewinners und schreibt seinen Inhalt darüber.
        $artifact = ReleaseArtifact::query()->createOrFirst(
            [
                'release_id' => $release->id,
                'name' => $name,
            ],
            $attributes,
        );

        if (! $artifact->wasRecentlyCreated) {
            $artifact->fill($attributes)->save();
        }

        return $artifact;
    }

    /**
     * Der Inhalt eines Artefakts.
     *
     * `null`, wenn die Datei nicht mehr da ist. Das ist kein erdachter Fall: die
     * Ablage kann aufgeräumt worden sein, während die Zeile stehen blieb — und
     * eine Ausnahme mitten in der Rückübersetzung eines Stacktraces wäre die
     * falsche Antwort darauf.
     */
    public function read(ReleaseArtifact $artifact): ?string
    {
        return $this->disk()->get($artifact->path);
    }

    /**
     * Löscht ein Artefakt.
     *
     * Der Inhalt fällt nur mit, wenn keine andere Zeile mehr auf ihn zeigt.
     */
    public function delete(ReleaseArtifact $artifact): void
    {
        $stillUsed = ReleaseArtifact::query()
            ->where('checksum', $artifact->checksum)
            ->where('path', $artifact->path)
            ->whereKeyNot($artifact->getKey())
            ->exists();

        $artifact->delete();

        if (! $stillUsed) {
            $this->disk()->delete($artifact->path);
        }
    }

    /**
     * Wie viele Artefakte diese Version trägt — die Zahl, an der das Mengenlimit
     * hängt.
     */
    public function countFor(Release $release): int
    {
        return ReleaseArtifact::query()->where('release_id', $release->id)->count();
    }

    /**
     * Wirft die Rückübersetzungen weg, die durch einen Upload überholt sind.
     *
     * **Das ist der Schritt, der die Reihenfolge im Betrieb erträglich macht.**
     * Quellkarten kommen fast immer nach den ersten Fehlern: erst knallt es, dann
     * fällt auf, dass niemand die Karten hochlädt. Ohne diesen Schritt bliebe der
     * erste — und meist einzige — Blick auf den Fehler für immer unlesbar, obwohl
     * die Karte inzwischen da ist.
     *
     * Weggeworfen wird nur, was an fehlenden Artefakten lag
     * ({@see SymbolicationStatus::isStaleAfterUpload()}), und nur innerhalb dieser
     * Version. Ein vollständig übersetzter Stacktrace bleibt: er ist nicht
     * überholt, und ihn neu zu rechnen wäre Arbeit für dasselbe Ergebnis.
     *
     * Neu gerechnet wird **nicht** hier, sondern beim nächsten Aufschlagen der
     * Fehlerseite. Ein Upload von zweihundert Dateien würde sonst zweihundertmal
     * die Aufträge für dieselben Meldungen einreihen — und gebraucht wird die
     * Übersetzung erst, wenn jemand hinsieht.
     *
     * @return int Wie viele Zwischenspeicher-Zeilen weggefallen sind.
     */
    public function invalidateSymbolications(Release $release): int
    {
        $stale = array_filter(
            SymbolicationStatus::cases(),
            static fn (SymbolicationStatus $status): bool => $status->isStaleAfterUpload(),
        );

        return EventSymbolication::query()
            ->whereIn('status', array_column($stale, 'value'))
            ->whereIn('event_id', DB::table('events')
                ->select('id')
                ->where('project_id', $release->project_id)
                ->where('release', $release->version))
            ->delete();
    }

    /**
     * Wohin der Inhalt gelegt wird.
     *
     * Die Version steht im Pfad, obwohl die Prüfsumme allein eindeutig wäre. Der
     * Grund ist das Aufräumen: eine gelöschte Version soll sich als Ordner
     * wegwerfen lassen, ohne die Zeilen aller anderen befragen zu müssen.
     */
    private function pathFor(Release $release, string $checksum): string
    {
        return trim((string) config('sourcemaps.path'), '/')
            .'/'.$release->project_id
            .'/'.$release->id
            .'/'.$checksum;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('sourcemaps.disk'));
    }
}
