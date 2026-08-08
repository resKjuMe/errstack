<?php

namespace App\Enums;

use App\Support\SourceMaps\SourceMap;

/**
 * Was für eine Datei ein hochgeladenes Artefakt ist.
 *
 * Entschieden wird das beim Hochladen und aus dem **Inhalt**, nicht aus dem
 * Namen: `app.js.map` ist die Gewohnheit, keine Zusage. Eine Quellkarte ist ein
 * JSON-Objekt mit einem Feld `mappings` — das ist prüfbar, eine Endung nicht
 * ({@see SourceMap::looksLikeSourceMap()}).
 *
 * Die Unterscheidung steht in der Ablage, weil die Rückübersetzung sonst jede
 * Datei einer Version öffnen müsste, nur um die Karte zu finden.
 */
enum ReleaseArtifactKind: string
{
    /** Das ausgelieferte, minimierte Bundle. */
    case Bundle = 'bundle';

    /** Die Quellkarte dazu. */
    case SourceMap = 'source_map';

    public function label(): string
    {
        return __('enums.release_artifact_kind.'.$this->value);
    }
}
