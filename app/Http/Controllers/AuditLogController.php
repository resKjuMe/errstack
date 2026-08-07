<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\OrganizationRole;
use App\Http\Requests\AuditLogFilterRequest;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Support\Formats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Änderungsprotokoll einer Organisation: ansehen, filtern, ausgeben. Nur
 * lesend — geschrieben wird ausschließlich über App\Support\AuditLog aus den
 * Controllern der jeweiligen Aktion.
 */
class AuditLogController extends Controller
{
    /** Einträge je Seite. */
    private const PER_PAGE = 50;

    public function index(AuditLogFilterRequest $request, Organization $organization): InertiaResponse
    {
        Gate::authorize('viewAuditLog', $organization);

        $entries = $this->entries($organization, $request)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLogEntry $entry): array => self::present($entry));

        return Inertia::render('organizations/AuditLog', [
            'organization' => [
                'slug' => $organization->slug,
                'name' => $organization->name,
                'href' => route('organizations.show', $organization),
            ],
            'entries' => $entries,
            'filters' => $request->formValues(),
            'actionOptions' => AuditAction::options(),
            'actorOptions' => self::actorOptions($organization),
            'exportHref' => route('organizations.audit-log.export', $organization),
        ]);
    }

    /**
     * Dieselbe Auswahl wie in der Ansicht, nur als CSV. Bewusst nicht seiten-,
     * sondern filterweise: wer exportiert, will den ganzen Zeitraum, nicht die
     * gerade sichtbaren fünfzig Zeilen.
     */
    public function export(AuditLogFilterRequest $request, Organization $organization): StreamedResponse
    {
        Gate::authorize('viewAuditLog', $organization);

        $entries = $this->entries($organization, $request);
        $filename = __('audit.export.filename', [
            'organization' => $organization->slug,
            'date' => now()->format('Y-m-d'),
        ]);

        return response()->streamDownload(function () use ($entries): void {
            $handle = fopen('php://output', 'w');

            // Tabellenprogramme erkennen UTF-8 sonst nicht und zeigen aus „ä"
            // zwei Zeichen. Semikolon als Trenner aus demselben Grund.
            fwrite($handle, "\xEF\xBB\xBF");

            self::putRow($handle, [
                __('audit.export.columns.occurred_at'),
                __('audit.export.columns.actor'),
                __('audit.export.columns.email'),
                __('audit.export.columns.action'),
                __('audit.export.columns.subject'),
                __('audit.export.columns.changes'),
                __('audit.export.columns.ip'),
            ]);

            foreach ($entries->cursor() as $entry) {
                self::putRow($handle, [
                    Formats::dateTimeSeconds($entry->created_at),
                    $entry->actor_name,
                    $entry->actor_email ?? '',
                    $entry->action->label(),
                    $entry->subject_label ?? '',
                    self::changesAsText($entry),
                    $entry->ip_address ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Die gefilterte Abfrage — für Ansicht und Export dieselbe.
     *
     * @return Builder<AuditLogEntry>
     */
    private function entries(Organization $organization, AuditLogFilterRequest $request): Builder
    {
        $filters = $request->filters();

        return AuditLogEntry::query()
            ->whereBelongsTo($organization)
            ->when($filters['actor'] !== null, fn (Builder $query) => $query->where('actor_id', $filters['actor']))
            ->when($filters['action'] !== null, fn (Builder $query) => $query->where('action', $filters['action']))
            ->when($filters['from'] !== null, fn (Builder $query) => $query->where('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== null, fn (Builder $query) => $query->where('created_at', '<=', $filters['to']))
            // Das Neueste zuerst; die laufende Nummer entscheidet innerhalb
            // derselben Sekunde, damit die Reihenfolge über Seiten hinweg hält.
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(AuditLogEntry $entry): array
    {
        $changes = [];

        foreach ($entry->changed_values ?? [] as $field => $change) {
            $changes[] = [
                'field' => self::fieldLabel((string) $field),
                'before' => self::valueLabel((string) $field, $change['before']),
                'after' => self::valueLabel((string) $field, $change['after']),
            ];
        }

        return [
            'id' => $entry->id,
            'occurredAt' => Formats::dateTime($entry->created_at),
            'actorName' => $entry->actor_name,
            'actorEmail' => $entry->actor_email,
            'action' => $entry->action->value,
            'actionLabel' => $entry->action->label(),
            'subject' => $entry->subject_label,
            'ip' => $entry->ip_address,
            'changes' => $changes,
        ];
    }

    /**
     * Name eines geänderten Feldes in der Sprache des Betrachters. Gespeichert
     * ist ein neutraler Schlüssel (`role`); ein Schlüssel ohne Übersetzung
     * bleibt stehen, wie er ist — ein Eintrag aus der Zeit vor dieser Regel
     * soll lesbar bleiben statt zu verschwinden.
     */
    private static function fieldLabel(string $field): string
    {
        $key = 'audit.fields.'.$field;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $field;
    }

    /**
     * Der Wert dazu. Nur wo der Wert selbst eine Aufzählung ist, gibt es etwas
     * zu übersetzen — Namen und Adressen sind Daten und bleiben, wie sie sind.
     */
    private static function valueLabel(string $field, ?string $value): ?string
    {
        if ($field !== 'role' || $value === null) {
            return $value;
        }

        return OrganizationRole::tryFrom($value)?->label() ?? $value;
    }

    /**
     * Wer im Protokoll dieser Organisation überhaupt vorkommt. Nur diese Namen
     * stehen im Filter — ein Konto ohne Eintrag wäre eine leere Auswahl.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function actorOptions(Organization $organization): array
    {
        return $organization->auditLogEntries()
            ->whereNotNull('actor_id')
            ->orderBy('actor_name')
            ->get(['actor_id', 'actor_name'])
            ->unique('actor_id')
            ->values()
            ->map(fn (AuditLogEntry $entry): array => [
                'value' => (string) $entry->actor_id,
                'label' => $entry->actor_name,
            ])
            ->all();
    }

    private static function changesAsText(AuditLogEntry $entry): string
    {
        $parts = [];

        foreach ($entry->changed_values ?? [] as $field => $change) {
            $parts[] = sprintf(
                '%s: %s → %s',
                self::fieldLabel((string) $field),
                self::valueLabel((string) $field, $change['before']) ?? '—',
                self::valueLabel((string) $field, $change['after']) ?? '—',
            );
        }

        return implode('; ', $parts);
    }

    /**
     * @param  resource  $handle
     * @param  list<string>  $row
     */
    private static function putRow($handle, array $row): void
    {
        // Alle Parameter ausgeschrieben: der frühere Fluchtmechanismus von
        // fputcsv ist abgekündigt, ein leerer Wert schaltet ihn ab.
        fputcsv($handle, $row, ';', '"', '');
    }
}
