<?php

namespace App\Enums;

use App\Models\EventSymbolication;

/**
 * Wie es der Rückübersetzung eines Stacktraces ergangen ist.
 *
 * Vier Ausgänge, und der Unterschied zwischen ihnen ist der Grund, den Zustand
 * überhaupt zu speichern: „nichts übersetzt" ohne hochgeladene Quellkarte ist
 * eine Auskunft, „nichts übersetzt" trotz vorhandener Karte ein Hinweis auf
 * einen falschen Pfad, und ein Fehlschlag ist ein Defekt. Eine Anzeige, die alle
 * drei als leeren Stacktrace zeigt, hilft niemandem.
 *
 * @see EventSymbolication
 */
enum SymbolicationStatus: string
{
    /**
     * Läuft noch. Der Zustand steht in der Anzeige, statt sie leer zu lassen:
     * die Übersetzung läuft im Hintergrund, und wer die Seite in derselben
     * Sekunde aufschlägt, soll „wird übersetzt" lesen und nicht „nicht möglich".
     */
    case Pending = 'pending';

    /** Jeder in Frage kommende Rahmen wurde zurückübersetzt. */
    case Mapped = 'mapped';

    /**
     * Ein Teil der Rahmen wurde übersetzt, ein Teil nicht. Der Regelfall bei
     * einer Anwendung, die eigenen Code und fremde Bibliotheken aus
     * verschiedenen Bauvorgängen lädt.
     */
    case Partial = 'partial';

    /**
     * Kein einziger Rahmen ließ sich zuordnen. Warum, steht in den Diagnosen —
     * ohne sie wäre dieser Zustand die unbrauchbarste Auskunft von allen.
     */
    case Unmapped = 'unmapped';

    /**
     * Die Übersetzung selbst ist gescheitert (unlesbare Ablage, Datei weg). Ein
     * Defekt, kein Ergebnis — und ausdrücklich von {@see self::Unmapped}
     * getrennt, damit er sich finden lässt.
     */
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.symbolication_status.'.$this->value);
    }

    /**
     * Ist an dieser Übersetzung etwas zu sehen?
     *
     * Die Frage der Anzeige: nur dann gibt es eine zweite Sicht auf den
     * Stacktrace, zwischen der sich umschalten lässt.
     */
    public function hasFrames(): bool
    {
        return $this === self::Mapped || $this === self::Partial;
    }

    /**
     * Darf noch einmal gerechnet werden?
     *
     * Ein Ergebnis, das an fehlenden Artefakten lag, ist nach einem Upload
     * überholt — ein vollständig übersetzter Stacktrace nicht. Das ist der
     * Unterschied zwischen einem Zwischenspeicher, der sich erneuert, und einem,
     * der bei jedem Upload alles wegwirft.
     */
    public function isStaleAfterUpload(): bool
    {
        return $this === self::Unmapped || $this === self::Partial || $this === self::Failed;
    }
}
