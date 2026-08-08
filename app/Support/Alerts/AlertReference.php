<?php

namespace App\Support\Alerts;

use App\Models\IssueAlertRule;
use App\Models\MetricAlert;

/**
 * Die Kennung, unter der die Meldungen einer Regel im Zustellprotokoll stehen.
 *
 * Sie stand schon vorher in jeder Nachricht — dieselbe Zeichenkette über alle
 * Meldungen eines Alarms, damit sich Auslösen und Entwarnung im Kanal einander
 * zuordnen lassen. Neu ist nur, dass jemand danach **sucht**: die
 * Alarm-Detailseite (A4) fragt, was aus einer Regel hinausgegangen ist.
 *
 * Deshalb steht die Bauvorschrift jetzt an einer Stelle und nicht an dreien.
 * Zwei Seiten, die sich denselben Zeichenkettenaufbau merken, sind genau die
 * Art von Verabredung, die beim nächsten Umbenennen still auseinanderfällt —
 * und der Schaden wäre eine Detailseite, die schlicht nichts mehr findet.
 */
final class AlertReference
{
    /**
     * Ein Schwellwert-Alarm: eine Kennung für alle seine Meldungen.
     */
    public static function forMetricAlert(MetricAlert $alert): string
    {
        return 'ALERT-'.$alert->id;
    }

    /**
     * Eine Fehler-Regel meldet **je Fehler**: die Kennung trägt beide, damit
     * Wiederholungen zu demselben Fehler im Kanal zusammenfinden.
     */
    public static function forIssueAlert(IssueAlertRule $rule, int $issueId): string
    {
        return self::issueAlertPrefix($rule).$issueId;
    }

    /**
     * Ein Trendbruch (PF7): eine Kennung je Feststellung.
     *
     * Die Feststellung wird fortgeschrieben und nicht neu angelegt, solange
     * derselbe Bruch gemeint ist — die Kennung bleibt damit über alle Meldungen
     * zu **diesem** Umschlag dieselbe. Schlägt dieselbe Transaktion später
     * erneut um, ist es eine neue Zeile und damit eine neue Kennung; genau so
     * soll es sein: im Kanal stünden sonst zwei verschiedene Vorfälle
     * untereinander, als wäre der zweite eine Ergänzung des ersten.
     */
    public static function forTrendDetection(int $detectionId): string
    {
        return 'TREND-'.$detectionId;
    }

    /**
     * Der gemeinsame Anfang aller Meldungen einer Fehler-Regel — der Weg, ihre
     * Zustellungen über alle Fehler hinweg zu finden.
     *
     * Ein Präfix und kein Muster mit Platzhalter am Anfang: nur so bleibt die
     * Suche eine Bereichsabfrage auf dem Index und wird nicht zum Durchlauf über
     * das gesamte Zustellprotokoll.
     */
    public static function issueAlertPrefix(IssueAlertRule $rule): string
    {
        return 'ISSUE-'.$rule->id.'-';
    }
}
