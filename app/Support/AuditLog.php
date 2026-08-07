<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Enums\OrganizationRole;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Schreibt Einträge ins Änderungsprotokoll. Einziger Weg dorthin — die
 * Controller rufen `record()` direkt nach der Änderung auf.
 *
 * Bewusst kein Model-Observer: was protokolliert wird, ist eine fachliche
 * Entscheidung („die Verwaltung hat die Rolle geändert"), keine technische
 * („eine Zeile in organization_user wurde geschrieben"). Am Aufrufer steht
 * ohnehin, was gemeint war, und nur dort sind die Vorher-Werte noch bekannt.
 */
final class AuditLog
{
    /**
     * @param  Model|null  $subject  Betroffener Datensatz, sofern es einen gibt
     * @param  string|null  $subjectLabel  Klartext für die Anzeige (bleibt lesbar, wenn es den Betreff nicht mehr gibt)
     * @param  array<string, array{before: string|null, after: string|null}>  $changes
     */
    public static function record(
        AuditAction $action,
        Organization $organization,
        ?Model $subject = null,
        ?string $subjectLabel = null,
        array $changes = [],
    ): AuditLogEntry {
        $actor = Auth::user();

        return $organization->auditLogEntries()->create([
            'actor_id' => $actor?->getKey(),
            // Ohne angemeldetes Konto stammt die Aktion aus dem Betrieb selbst
            // (Konsole, geplanter Lauf) — auch das gehört ins Protokoll.
            'actor_name' => $actor === null ? 'System' : $actor->name,
            'actor_email' => $actor?->email,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subjectLabel,
            'changed_values' => $changes === [] ? null : $changes,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Ein geändertes Feld im Format des Protokolls. `null` als Vorher-Wert
     * steht für „gab es noch nicht", als Nachher-Wert für „gibt es nicht mehr".
     *
     * @return array<string, array{before: string|null, after: string|null}>
     */
    public static function change(string $field, ?string $before, ?string $after): array
    {
        return [$field => ['before' => $before, 'after' => $after]];
    }

    /**
     * Eine Rollenänderung. Gespeichert wird der Wert der Aufzählung, nicht ihre
     * Beschriftung: sonst stünde in derselben Spalte einmal „Mitglied“ und
     * einmal „Member“, je nachdem in welcher Sprache gerade jemand geklickt
     * hat. Übersetzt wird beim Anzeigen.
     *
     * @return array<string, array{before: string|null, after: string|null}>
     */
    public static function roleChange(?OrganizationRole $before, ?OrganizationRole $after): array
    {
        return self::change('role', $before?->value, $after?->value);
    }
}
