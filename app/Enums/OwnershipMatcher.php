<?php

namespace App\Enums;

use App\Support\Ownership\OwnershipSubjects;

/**
 * Worauf sich eine Zuständigkeits-Regel bezieht.
 *
 * Vier Arten, und die Auswahl ist geschlossen. Sie folgt der Frage „woran
 * erkennt man, wem ein Fehler gehört?" und nicht dem, was eine Meldung alles
 * hergibt: der Ort im Code ({@see self::Path}), die Stelle in der Anwendung
 * ({@see self::Url}), das Paket ({@see self::Module}) und — als Notausgang für
 * alles Übrige — ein Merkmal ({@see self::Tag}).
 *
 * **Warum nicht mehr:** jede weitere Art ist ein weiterer Vergleich je Fehler
 * und, schlimmer, eine weitere Schreibweise, die jemand kennen muss, um die
 * Liste zu lesen. Was sich mit einem Merkmal ausdrücken lässt, braucht keine
 * eigene Art — und was sich nicht ausdrücken lässt, ist in aller Regel keine
 * Zuständigkeit, sondern ein Alarm.
 *
 * Welche Werte einer Meldung zu welcher Art gehören, steht an einer Stelle:
 * {@see OwnershipSubjects}.
 */
enum OwnershipMatcher: string
{
    /**
     * Der Ort im Code — die Dateipfade aus dem Stacktrace.
     *
     * Die wichtigste Art, weil sie dieselbe Frage beantwortet wie eine
     * CODEOWNERS-Datei und sich deshalb aus ihr importieren lässt.
     */
    case Path = 'path';

    /**
     * Die aufgerufene Adresse — `request.url` der Meldung.
     *
     * Für alles, was im Browser passiert: dort steht im Stacktrace der Name
     * eines gebündelten Skripts und nicht der Pfad, unter dem der Code im
     * Repository liegt. Die Adresse ist dann die einzige Angabe, die noch etwas
     * über den Bereich der Anwendung sagt.
     */
    case Url = 'url';

    /**
     * Das Modul — `module` der Stacktrace-Rahmen.
     *
     * Bei Sprachen mit Namensräumen (Java, C#, PHP) ist es die genauere Angabe
     * als der Pfad: `com.acme.billing.*` bleibt richtig, auch wenn jemand die
     * Verzeichnisse umräumt.
     */
    case Module = 'module';

    /**
     * Ein Merkmal der Meldung (`server_name`, `environment`, eigene Merkmale).
     *
     * Der Notausgang für alles, was sich weder am Pfad noch an der Adresse
     * festmachen lässt — etwa eine Anwendung, die ihre Fachbereiche selbst als
     * Merkmal mitschickt. Diese Art ist die einzige mit einem zweiten Feld: dem
     * Namen des Merkmals.
     */
    case Tag = 'tag';

    public function label(): string
    {
        return __('enums.ownership_matcher.'.$this->value);
    }

    /**
     * Braucht diese Art zusätzlich den Namen eines Merkmals?
     *
     * Als Frage an die Art und nicht als `=== self::Tag` an vier Stellen: kommt
     * je eine zweite Art mit Schlüssel dazu, ist das hier eine Zeile.
     */
    public function needsKey(): bool
    {
        return $this === self::Tag;
    }

    /**
     * Die Auswahl für die Oberfläche.
     *
     * @return list<array{value: string, label: string, needsKey: bool}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $matcher): array => [
                'value' => $matcher->value,
                'label' => $matcher->label(),
                'needsKey' => $matcher->needsKey(),
            ],
            self::cases(),
        );
    }
}
