<?php

namespace App\Enums;

/**
 * Was ein Commit mit einer Datei gemacht hat.
 *
 * Die Werte sind die Buchstaben, die von außen ankommen (`A`, `M`, `D`) — so
 * schickt sentry-cli seinen `patch_set`, und so liefern es die Anbieter. Sie
 * hier in ausgeschriebene Wörter zu übersetzen und beim Ausliefern
 * zurückzuübersetzen wäre eine Umrechnung ohne Gewinn; gelesen wird der Wert
 * ohnehin nie roh, sondern über {@see label()}.
 *
 * Umbenennungen fehlen mit Absicht. Git kennt sie nicht als eigenen Vorgang —
 * es errät sie beim Anzeigen aus Löschung und Hinzufügung. Ein vierter Fall
 * hier wäre also eine Angabe, die je nach Anbieter kommt oder nicht, und damit
 * ein Unterschied in der Anzeige, der nichts über die Änderung aussagt.
 */
enum CommitFileChange: string
{
    case Added = 'A';

    case Modified = 'M';

    case Removed = 'D';

    /**
     * Der Fall, in dem eine Angabe fehlt.
     *
     * Eine Datei ohne Angabe ist geändert worden — das ist der weitaus häufigste
     * Fall und die einzige Annahme, die niemandem etwas vormacht: „hinzugefügt"
     * oder „gelöscht" zu raten wäre eine Aussage über die Änderung, „geändert"
     * ist die Aussage „sie war beteiligt".
     */
    public const DEFAULT = self::Modified;

    public function label(): string
    {
        return __('enums.commit_file_change.'.$this->value);
    }

    /**
     * Die Angabe von außen, nachsichtig gelesen.
     *
     * Groß- und Kleinschreibung spielt keine Rolle, und ein unbekannter Wert
     * fällt auf {@see DEFAULT} zurück statt die Übergabe abzuweisen: die
     * Commits kommen aus einer Auslieferungs-Pipeline, und ein unerwarteter
     * Buchstabe in einer von dreihundert Dateien soll nicht den ganzen Baulauf
     * rot färben.
     */
    public static function fromInput(?string $value): self
    {
        return self::tryFrom(strtoupper(trim((string) $value))) ?? self::DEFAULT;
    }
}
