<?php

namespace App\Support\Issues;

use App\Enums\IssueIgnoreMode;
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
