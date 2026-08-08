<?php

namespace App\Enums;

use App\Jobs\DetectPerformanceIssues;
use App\Jobs\SymbolicateEvent;

/**
 * Warteschlangen der Anwendung. Die Reihenfolge der Cases ist zugleich die
 * Abarbeitungs-Priorität des Workers: `ingest` zuerst, damit eingehende
 * Fehlermeldungen nie hinter Benachrichtigungen warten.
 */
enum QueueName: string
{
    /** Entgegennahme und Verarbeitung eingehender Fehlermeldungen. */
    case Ingest = 'ingest';

    /** Benachrichtigungen und Broadcasts an offene Ansichten. */
    case Notifications = 'notifications';

    /**
     * Die nachgelagerte Auswertung gespeicherter Abläufe: die Suche nach
     * Leistungsmustern ({@see DetectPerformanceIssues}).
     *
     * Eine eigene Warteschlange und nicht `default`, weil hier je Transaktion
     * ein Auftrag anfällt — bei hundert Aufrufen je Sekunde also hundert. Läge
     * das in derselben Schlange wie alles Übrige, würde ein Rückstau in der
     * Erkennung jede andere Hintergrundarbeit mitziehen. Hinter
     * `notifications`, weil ein Alarm nie hinter einer Auswertung warten soll.
     */
    case Performance = 'performance';

    /**
     * Das Zurückübersetzen minimierter Stacktraces über Quellkarten
     * ({@see SymbolicateEvent}).
     *
     * Eine eigene Warteschlange, weil dieser Auftrag als einziger große Dateien
     * einliest: eine Quellkarte mit eingebettetem Quelltext bringt zweistellige
     * Megabyte mit, und ihr Zerlegen dauert. In `default` würde jede
     * Aufräumarbeit dahinter warten, in `ingest` die Aufnahme selbst.
     *
     * Hinter `performance` und vor `default`: die Übersetzung ist nachgelagert —
     * niemand wartet auf sie, solange die Fehlerseite „wird übersetzt" zeigen
     * kann.
     */
    case Symbolication = 'symbolication';

    /** Alles Übrige. */
    case Default = 'default';

    /**
     * Warteschlangen in Prioritätsreihenfolge, wie sie `queue:work --queue=…`
     * erwartet: `ingest,notifications,performance,symbolication,default`.
     */
    public static function priority(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }
}
