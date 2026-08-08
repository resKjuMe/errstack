<?php

namespace App\Support\SourceMaps;

use App\Enums\SymbolicationDiagnosis;
use App\Models\Release;
use App\Models\ReleaseArtifact;

/**
 * Findet die Quellkarte zu einem gemeldeten Rahmen.
 *
 * **Das ist der Teil, an dem Source Maps in der Praxis scheitern**, und deshalb
 * ist er eine eigene Klasse mit einem eigenen Ergebnis: nicht „Karte oder
 * `null`", sondern „Karte oder ein Grund" ({@see ArtifactMatch}). Ein Rahmen, der
 * unübersetzt bleibt, sieht in der Anzeige immer gleich aus — die Ursache liegt
 * aber fast immer außerhalb dieser Anwendung, und ohne die Unterscheidung sucht
 * man an der falschen Stelle.
 *
 * Zwei Wege, und der erste hat Vorrang:
 *
 * **Die Debug-Kennung.** Der Bauvorgang schreibt eine Nummer in Bundle und
 * Karte, das SDK meldet sie mit (`debug_meta.images`). Damit ist die Zuordnung
 * eindeutig und braucht weder Adresse noch Version — der verlässlichere Weg, und
 * der einzige, der ein Bundle hinter einem Auslieferungsnetz mit wechselnden
 * Adressen noch findet.
 *
 * **Der Pfad.** Der Rahmen nennt `https://example.com/static/js/app.js`, gesucht
 * wird das Artefakt `~/static/js/app.js` **dieser Version**. Der ältere Weg, und
 * er hängt an zwei Dingen, die schiefgehen können: die Meldung muss eine Version
 * tragen, und die beim Hochladen angegebenen Pfade müssen zu den geladenen
 * Adressen passen.
 *
 * Geladene Karten werden für die Dauer eines Durchlaufs behalten. Ein Stacktrace
 * hat vierzig Rahmen aus zwei Bundles; ohne das wären es vierzig Mal Einlesen und
 * Zerlegen derselben zwei Karten.
 */
final class ArtifactResolver
{
    /**
     * Geladene Karten dieses Durchlaufs, nach Artefakt-Kennung.
     *
     * `false` steht für „schon versucht, ging nicht" — sonst würde eine kaputte
     * Karte bei jedem Rahmen erneut eingelesen und erneut verworfen.
     *
     * @var array<int, SourceMap|false>
     */
    private array $maps = [];

    /**
     * Bereits aufgelöste Rahmen-Pfade dieses Durchlaufs.
     *
     * @var array<string, ArtifactMatch>
     */
    private array $matches = [];

    public function __construct(
        private readonly ArtifactStore $store,
    ) {}

    /**
     * Sucht die Karte für einen gemeldeten Dateipfad.
     *
     * @param  array<string, string>  $debugIds  Debug-Kennungen je gemeldeter Datei.
     */
    public function resolve(?Release $release, int $projectId, string $reported, array $debugIds): ArtifactMatch
    {
        $key = $reported.'|'.($release === null ? 0 : $release->id);

        if (array_key_exists($key, $this->matches)) {
            return $this->matches[$key];
        }

        return $this->matches[$key] = $this->lookup($release, $projectId, $reported, $debugIds);
    }

    /**
     * @param  array<string, string>  $debugIds
     */
    private function lookup(?Release $release, int $projectId, string $reported, array $debugIds): ArtifactMatch
    {
        $debugId = $this->debugIdFor($reported, $debugIds);

        if ($debugId !== null) {
            $artifact = ReleaseArtifact::matchDebugId($projectId, $debugId);

            if ($artifact === null) {
                return ArtifactMatch::failed(SymbolicationDiagnosis::UnknownDebugId, $debugId);
            }

            // Über die Kennung kann auch das Bundle gefunden werden — beide
            // tragen sie. Dann geht es weiter wie beim Pfad-Weg: der Verweis des
            // Bundles zeigt auf die Karte.
            return $artifact->isSourceMap()
                ? $this->load($artifact)
                : $this->followReference($artifact);
        }

        if ($release === null) {
            return ArtifactMatch::failed(SymbolicationDiagnosis::NoRelease, null);
        }

        $artifact = ReleaseArtifact::matchName($release, $reported);

        if ($artifact === null) {
            // Der Unterschied zwischen „diese Version hat keine Artefakte" und
            // „diese Datei ist nicht darunter" ist der zwischen einem fehlenden
            // Schritt in der Bauumgebung und einem falschen Pfad. Beides führt
            // zu ganz verschiedenen Handgriffen.
            return $this->store->countFor($release) === 0
                ? ArtifactMatch::failed(SymbolicationDiagnosis::NoArtifacts, $release->version)
                : ArtifactMatch::failed(SymbolicationDiagnosis::ArtifactNotFound, $reported);
        }

        return $artifact->isSourceMap()
            ? $this->load($artifact)
            : $this->followReference($artifact);
    }

    /**
     * Vom Bundle zur Karte.
     *
     * Drei Möglichkeiten, in dieser Reihenfolge: der Verweis aus dem Bundle
     * (`sourceMappingURL`, beim Hochladen gelesen), derselbe Name mit `.map`
     * daneben — die Gewohnheit jedes Bauwerkzeugs — und, wenn die Version genau
     * **eine** Karte hat, diese. Der letzte Schritt ist eine Vermutung und
     * bewusst auf den eindeutigen Fall beschränkt: bei zwei Karten wäre die Wahl
     * geraten, und eine falsche Karte liefert falsche Zeilen statt keiner.
     */
    private function followReference(ReleaseArtifact $bundle): ArtifactMatch
    {
        $release = $bundle->release;
        $candidates = [];

        $reference = $bundle->sourceMapName();

        if ($reference !== null) {
            $candidates[] = $reference;
        }

        $candidates[] = $bundle->name.'.map';

        foreach ($candidates as $candidate) {
            $map = ReleaseArtifact::matchName($release, $candidate);

            if ($map !== null && $map->isSourceMap()) {
                return $this->load($map);
            }
        }

        $only = ReleaseArtifact::query()
            ->where('release_id', $release->id)
            ->sourceMaps()
            ->limit(2)
            ->get();

        if ($only->count() === 1) {
            $single = $only->first();

            if ($single instanceof ReleaseArtifact) {
                return $this->load($single);
            }
        }

        // Ein Verweis, der ins Leere zeigt, ist etwas anderes als kein Verweis:
        // im ersten Fall wurde die Karte gebaut und nicht hochgeladen, im
        // zweiten gar nicht gebaut.
        return ArtifactMatch::failed(
            $reference === null
                ? SymbolicationDiagnosis::NoSourceMapReference
                : SymbolicationDiagnosis::SourceMapMissing,
            $reference ?? $bundle->name,
        );
    }

    /**
     * Holt eine Karte von der Platte und zerlegt sie — einmal je Durchlauf.
     */
    private function load(ReleaseArtifact $artifact): ArtifactMatch
    {
        $cached = $this->maps[$artifact->id] ?? null;

        if ($cached instanceof SourceMap) {
            return ArtifactMatch::found($cached, $artifact);
        }

        if ($cached === false) {
            return ArtifactMatch::failed(SymbolicationDiagnosis::InvalidSourceMap, $artifact->name);
        }

        // Eine Karte, die nicht mehr in den Speicher passt, ist kein Defekt der
        // Karte — aber auch kein anderes Ergebnis: übersetzen lässt sich mit ihr
        // nichts. Die Grenze steht in der Konfiguration und ist bewusst kleiner
        // als die Uploadgrenze: aufbewahren darf man auch, was sich nicht mehr
        // verarbeiten lässt.
        if ($artifact->size > (int) config('sourcemaps.max_map_bytes')) {
            $this->maps[$artifact->id] = false;

            return ArtifactMatch::failed(SymbolicationDiagnosis::InvalidSourceMap, $artifact->name);
        }

        $content = $this->store->read($artifact);
        $map = $content === null ? null : SourceMap::fromJson($content);

        if ($map === null) {
            $this->maps[$artifact->id] = false;

            return ArtifactMatch::failed(SymbolicationDiagnosis::InvalidSourceMap, $artifact->name);
        }

        $this->maps[$artifact->id] = $map;

        return ArtifactMatch::found($map, $artifact);
    }

    /**
     * Die Debug-Kennung, die zu einem gemeldeten Pfad gehört.
     *
     * Die Kennungen stehen an der Meldung je **geladener Datei** — und die ist
     * unter der Adresse verzeichnet, unter der der Browser sie geladen hat. Ein
     * Rahmen nennt dieselbe Adresse, aber nicht immer in derselben Schreibweise;
     * gesucht wird deshalb über dieselbe Bewerberliste wie beim Pfad-Weg
     * ({@see ReleaseArtifact::candidatesFor()}), damit beide Wege denselben
     * Begriff von „dieselbe Datei" haben.
     *
     * @param  array<string, string>  $debugIds
     */
    private function debugIdFor(string $reported, array $debugIds): ?string
    {
        if ($debugIds === []) {
            return null;
        }

        foreach (ReleaseArtifact::candidatesFor($reported) as $candidate) {
            if (isset($debugIds[$candidate])) {
                return $debugIds[$candidate];
            }
        }

        return null;
    }
}
