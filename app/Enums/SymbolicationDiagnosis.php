<?php

namespace App\Enums;

/**
 * Warum ein Rahmen nicht zurückübersetzt werden konnte.
 *
 * **Das ist der Teil dieser Aufgabe, der über den Nutzen entscheidet.** Eine
 * Rückübersetzung, die nichts findet, sieht ohne Begründung genauso aus wie
 * eine, die nicht angestoßen wurde — und die Ursache liegt fast immer außerhalb
 * dieser Anwendung: eine Karte, die der Bauvorgang nicht hochgeladen hat, ein
 * Pfad, der mit einer anderen Adresse gemeldet wird, eine Debug-Kennung aus
 * einem anderen Bauvorgang. Wer das nicht gesagt bekommt, sucht an der falschen
 * Stelle.
 *
 * Die Gründe sind von außen nach innen geordnet: erst was vor der Karte
 * schiefgeht, dann die Karte selbst, dann die Suche in ihr.
 */
enum SymbolicationDiagnosis: string
{
    /**
     * Zu der Version gehört kein einziges Artefakt. Der häufigste Grund — und
     * der einzige, der keine Fehlersuche verlangt, sondern einen Schritt in der
     * Bauumgebung.
     */
    case NoArtifacts = 'no_artifacts';

    /**
     * Die Meldung nennt keine Version. Ohne sie ist nicht zu sagen, welche
     * Artefakte gemeint sind — der Pfad allein reicht nicht, denn jede
     * Auslieferung hat denselben.
     */
    case NoRelease = 'no_release';

    /**
     * Zu dem gemeldeten Pfad gibt es kein Artefakt. Die Version hat welche, aber
     * nicht dieses — meist, weil das SDK eine andere Adresse meldet als die, die
     * beim Hochladen als Pfad angegeben wurde.
     */
    case ArtifactNotFound = 'artifact_not_found';

    /**
     * Die Meldung nennt eine Debug-Kennung, zu der kein Artefakt vorliegt. Ein
     * anderer Fall als der fehlende Pfad: hier ist die Zuordnung eindeutig
     * gemeint, und was fehlt, ist genau diese Datei — in der Regel ein
     * Bauvorgang, dessen Karten nicht hochgeladen wurden.
     */
    case UnknownDebugId = 'unknown_debug_id';

    /**
     * Das Bundle ist da, aber es verweist auf keine Quellkarte — kein
     * `sourceMappingURL`, keine Kopfzeile beim Upload, keine Datei mit `.map`
     * daneben.
     */
    case NoSourceMapReference = 'no_source_map_reference';

    /**
     * Das Bundle verweist auf eine Quellkarte, die es nicht gibt. Der Verweis
     * zeigt ins Leere: die Karte wurde gebaut, aber nicht hochgeladen.
     */
    case SourceMapMissing = 'source_map_missing';

    /** Die Quellkarte ließ sich nicht lesen — kein JSON, keine Fassung 3, keine `mappings`. */
    case InvalidSourceMap = 'invalid_source_map';

    /**
     * Der Rahmen nennt keine Zeile. Ohne Zeile und Spalte gibt es nichts
     * nachzuschlagen — das ist kein Fehler der Artefakte, sondern eine Lücke in
     * der Meldung.
     */
    case NoPosition = 'no_position';

    /**
     * Die Karte ist gültig und enthält für diese Stelle keinen Eintrag. Das
     * passiert bei einer Karte, die zu einem **anderen** Bau desselben Bundles
     * gehört: die Zeilen stimmen dann nicht mehr überein.
     */
    case NoMappingForPosition = 'no_mapping_for_position';

    /**
     * Der Stacktrace hat mehr Rahmen, als je Meldung übersetzt werden
     * (`sourcemaps.max_frames`). Die restlichen stehen unverändert da — bei einer
     * Endlos-Rekursion mit tausend Rahmen ist das die richtige Antwort, aber sie
     * gehört gesagt.
     */
    case FrameLimitReached = 'frame_limit_reached';

    /**
     * Die Stelle wurde gefunden, aber der Quelltext liegt nicht in der Karte
     * (`sourcesContent` fehlt). Datei, Zeile und Funktionsname stehen dann da,
     * nur der Ausschnitt fehlt — die Karte ist ohne `--include-sources` gebaut.
     */
    case NoSourceContent = 'no_source_content';

    public function label(): string
    {
        return __('enums.symbolication_diagnosis.'.$this->value);
    }
}
