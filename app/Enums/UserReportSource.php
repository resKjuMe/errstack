<?php

namespace App\Enums;

use App\Models\UserReport;

/**
 * Die beiden Arten, in denen eine Rückmeldung entsteht.
 *
 * Der Unterschied ist keine Herkunftsangabe des Absenders, sondern eine
 * Eigenschaft der Zuschrift selbst: **hat sie einen Ereignisbezug oder nicht?**
 * Deshalb steht sie auch nicht als Spalte in der Tabelle, sondern wird aus ihr
 * abgeleitet ({@see UserReport::source()}) — eine gespeicherte Herkunft könnte
 * mit der Zeile auseinanderlaufen, eine abgeleitete nicht.
 *
 * Für die Liste ist die Unterscheidung trotzdem wichtig: ein Absturzbericht
 * gehört zu einem Fehler, den es in der Fehlerliste gibt; eine freie Zuschrift
 * steht für sich und ist die einzige Spur dessen, was der Person passiert ist.
 */
enum UserReportSource: string
{
    /** Beschreibung zu einem gemeldeten Ereignis (klassischer Absturzbericht). */
    case CrashReport = 'crash_report';

    /** Freie Zuschrift ohne Ereignisbezug — der Weg des Feedback-Widgets. */
    case Standalone = 'standalone';

    public function label(): string
    {
        return __('enums.user_report_source.'.$this->value);
    }
}
