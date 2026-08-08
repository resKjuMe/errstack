<?php

namespace App\Support\Issues;

use App\Enums\IssueIgnoreMode;
use App\Enums\IssuePriority;
use App\Enums\IssueResolveMode;
use App\Models\Issue;
use Illuminate\Support\Facades\Gate;

/**
 * Was die Oberfläche braucht, um die Aktionsleiste zu bauen: die Adressen, die
 * Auswahllisten und die Frage, ob gelöscht werden darf.
 *
 * Steht an einer Stelle, weil Liste und Detailseite dieselbe Leiste zeigen. Sie
 * je Seite zusammenzustellen wäre zweimal derselbe Block — und die zweite
 * Fassung bekäme die nächste Aktion erfahrungsgemäß nicht mit.
 */
final class IssueActionData
{
    /**
     * @return array<string, mixed>
     */
    public static function forViewer(?Issue $issue = null): array
    {
        return [
            'store' => route('issues.actions.store'),
            'undo' => route('issues.actions.undo'),
            'resolveModes' => IssueResolveMode::options(),
            'ignoreModes' => IssueIgnoreMode::options(),
            // Woher die Auswahlliste der Zuständigkeit ihre Vorschläge holt
            // (S7). Als Adresse und nicht als fertige Liste — die Mitglieder
            // einer Organisation wären in jeder Seitenlast ein Vielfaches der
            // Seite selbst, für ein Feld, das die meisten Aufrufe nie anfassen.
            //
            // Steht ein einzelner Fehler fest, reist seine Kennung mit: dann
            // führen die Autoren der verdächtigen Commits die Liste an (R4). In
            // der Fehlerliste fehlt sie — eine Sammelaktion über zwölf Einträge
            // hat keinen Stacktrace, gegen den sich etwas abgleichen ließe.
            'assignSuggestHref' => $issue === null
                ? route('issues.assignment.suggest')
                : route('issues.assignment.suggest', ['issue' => $issue->id]),
            // Die Stufen zur Auswahl — mit „automatisch" an erster Stelle. Es
            // ist keine vierte Stufe, sondern der Weg zurück: wer von Hand
            // eingeordnet hat, muss die Ableitung wieder zulassen können, und
            // ein zweiter Knopf dafür wäre ein zweiter Begriff für dieselbe
            // Frage (S11).
            'priorities' => [
                ['value' => 'auto', 'label' => __('issues.actions.priority.auto')],
                ...IssuePriority::options(),
            ],
            // Ohne Eintrag — also in der Liste — wird das Löschen angeboten und
            // beim Absenden geprüft. Die Menge kann Einträge aus mehreren
            // Projekten enthalten; eine Schaltfläche, die dann „darf" oder
            // „darf nicht" sagen müsste, hätte für die Hälfte recht.
            'canDelete' => $issue === null || Gate::allows('delete', $issue),
            // Die Vorschläge des Zeitfensters — in Minuten, damit sie ohne
            // Umrechnen ins Formular gehen.
            'windows' => self::windows(),
        ];
    }

    /**
     * Zeitfenster zur Auswahl: eine Stunde, ein Tag, eine Woche.
     *
     * Vorschläge und keine Vorschrift — das Feld nimmt jede Zahl. Sie stehen
     * hier, weil ihre Beschriftung übersetzt ist und die Oberfläche keine
     * Dauern beugen soll.
     *
     * @return list<array{value: int, label: string}>
     */
    private static function windows(): array
    {
        return [
            ['value' => 60, 'label' => __('issues.actions.window.hour')],
            ['value' => 60 * 24, 'label' => __('issues.actions.window.day')],
            ['value' => 60 * 24 * 7, 'label' => __('issues.actions.window.week')],
        ];
    }
}
