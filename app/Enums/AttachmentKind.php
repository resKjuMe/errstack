<?php

namespace App\Enums;

use App\Models\EventAttachment;

/**
 * Was für eine Datei ein Anhang ist — und was die Oberfläche mit ihm tun darf.
 *
 * Entschieden wird das aus dem gemeldeten Inhaltstyp und einmal beim Ablegen
 * ({@see EventAttachment::kindFor()}), nicht bei jeder Anzeige. Der Grund ist
 * nicht die Rechenzeit: an dieser Angabe hängt, ob eine Datei **inline** an den
 * Browser geht, und das ist eine Sicherheitsentscheidung. Sie soll nicht davon
 * abhängen, was gerade in einer Aufzählung in `config/attachments.php` steht —
 * sonst würde eine spätere Ergänzung dort rückwirkend Dateien inline stellen, die
 * beim Eintreffen als Download eingeordnet waren.
 *
 * Drei Fälle, weil es drei Umgangsformen gibt: ein Bild wird gezeigt, ein Text
 * angerissen, alles andere ausschließlich zum Herunterladen angeboten.
 */
enum AttachmentKind: string
{
    /** Ein Bild in einem Format, das der Browser gefahrlos darstellt. */
    case Image = 'image';

    /** Text: Logdatei, JSON, CSV. Ein Anriss davon lässt sich anzeigen. */
    case Text = 'text';

    /**
     * Alles andere: Speicherabbilder, Archive, unbekannte Typen — und
     * ausdrücklich auch alles, was ein Browser als Dokument auslegen würde
     * (HTML, SVG). Nur herunterladen.
     */
    case Binary = 'binary';

    public function label(): string
    {
        return __('enums.attachment_kind.'.$this->value);
    }

    /**
     * Darf die Datei im Browser angesehen werden, statt nur heruntergeladen?
     *
     * Die Frage stellen zwei Stellen: die Anzeige, die eine Vorschau einbaut,
     * und die Auslieferung, die über `Content-Disposition` entscheidet. Beide
     * dieselbe Antwort geben zu lassen ist der Punkt — eine Vorschau, die auf
     * eine Adresse zeigt, die als Download antwortet, wäre eine leere Fläche.
     */
    public function isPreviewable(): bool
    {
        return $this !== self::Binary;
    }
}
