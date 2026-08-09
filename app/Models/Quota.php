<?php

namespace App\Models;

use App\Enums\QuotaCategory;
use App\Enums\QuotaScope;
use App\Support\Ingest\Quotas\QuotaLimits;
use Database\Factories\QuotaFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Wie viel eine Organisation oder ein Projekt je Datenart aufnehmen darf —
 * im laufenden Monat und in einer Minute.
 *
 * Eine Zeile ist eine Entscheidung, keine Voreinstellung: fehlt sie, gilt
 * „unbegrenzt". Deshalb legt niemand beim Anlegen eines Projekts fünf leere
 * Zeilen an, und deshalb heißt eine leere Tabelle, dass noch nie jemand ein
 * Kontingent gesetzt hat — nicht, dass die Kontingente kaputt sind.
 *
 * Gezählt wird hier nichts. Der Verbrauch steht im Zwischenspeicher
 * ({@see App\Support\Ingest\Quotas\QuotaCounter}), weil er bei **jeder**
 * eingehenden Meldung angefasst wird; was hier steht, ändert sich ein paarmal
 * im Jahr.
 *
 * @property int $id
 * @property QuotaScope $scope
 * @property int $scope_id
 * @property QuotaCategory $category
 * @property int|null $per_month
 * @property int|null $per_minute
 * @property string|null $warned_period
 * @property int|null $warned_percent
 */
class Quota extends Model
{
    /** @use HasFactory<QuotaFactory> */
    use HasFactory;

    /**
     * Setzt das Kontingent einer Ebene für eine Datenart.
     *
     * Sind beide Werte `null`, wird die Zeile gelöscht statt mit lauter `null`
     * stehen zu lassen: „unbegrenzt" ist die Abwesenheit einer Entscheidung,
     * und eine Zeile, die nichts sagt, müsste jede Auswertung erst wieder
     * aussortieren.
     */
    public static function set(
        QuotaScope $scope,
        int $scopeId,
        QuotaCategory $category,
        ?int $perMonth,
        ?int $perMinute,
    ): ?self {
        $existing = self::query()
            ->where('scope', $scope->value)
            ->where('scope_id', $scopeId)
            ->where('category', $category->value)
            ->first();

        if ($perMonth === null && $perMinute === null) {
            $existing?->delete();

            return null;
        }

        $quota = $existing ?? new self;

        $quota->scope = $scope;
        $quota->scope_id = $scopeId;
        $quota->category = $category;

        // Ein geändertes Kontingent macht die Warnungen dieses Monats
        // gegenstandslos: wer von 100.000 auf 500.000 anhebt, hat gerade
        // entschieden, dass die Meldung von gestern erledigt ist — und will bei
        // 400.000 wieder eine bekommen.
        if ($quota->exists && $quota->per_month !== $perMonth) {
            $quota->warned_period = null;
            $quota->warned_percent = null;
        }

        $quota->per_month = $perMonth;
        $quota->per_minute = $perMinute;
        $quota->save();

        return $quota;
    }

    /**
     * Alle Kontingente einer Ebene, nach Datenart abgelegt.
     *
     * @return array<string, self>
     */
    public static function forScope(QuotaScope $scope, int $scopeId): array
    {
        /** @var Collection<int, self> $rows */
        $rows = self::query()
            ->where('scope', $scope->value)
            ->where('scope_id', $scopeId)
            ->get();

        $byCategory = [];

        foreach ($rows as $row) {
            $byCategory[$row->category->value] = $row;
        }

        return $byCategory;
    }

    /**
     * Räumt die Kontingente einer gelöschten Organisation oder eines
     * gelöschten Projekts ab.
     *
     * Von Hand und nicht per Fremdschlüssel: die Tabelle trägt Ebene und
     * Kennung als zwei Spalten, damit die Datenaufnahme beide Ebenen mit einer
     * Abfrage lesen kann — und eine Fremdschlüssel-Bedingung auf eine solche
     * Spalte gibt es nicht.
     */
    public static function forget(QuotaScope $scope, int $scopeId): void
    {
        self::query()
            ->where('scope', $scope->value)
            ->where('scope_id', $scopeId)
            ->delete();
    }

    /**
     * Beansprucht die Warnung für einen Anteil in einem Monat — und gibt
     * zurück, ob **dieser** Aufruf sie beansprucht hat.
     *
     * Der ganze Zweck ist das „genau einmal": die Prüfung läuft bei jeder
     * eingehenden Meldung, und an der Schwelle von 80 % laufen mehrere Arbeiter
     * gleichzeitig hindurch. Deshalb entscheidet die Bedingung der
     * `UPDATE`-Anweisung und nicht ein Vergleich davor — die Datenbank sagt
     * über die Zahl der geänderten Zeilen, wer zuerst da war.
     */
    public static function claimWarning(int $id, string $period, int $percent): bool
    {
        return self::query()
            ->whereKey($id)
            ->where(function ($query) use ($period, $percent): void {
                $query->whereNull('warned_period')
                    ->orWhere('warned_period', '!=', $period)
                    ->orWhereNull('warned_percent')
                    ->orWhere('warned_percent', '<', $percent);
            })
            ->update(['warned_period' => $period, 'warned_percent' => $percent]) === 1;
    }

    /**
     * Eine geänderte Grenze muss sofort gelten — sonst schaltet jemand ein
     * Kontingent ab, weil gerade eine Fehlerwelle läuft, und es passiert eine
     * Minute lang nichts.
     */
    protected static function booted(): void
    {
        self::saved(static fn (self $quota) => QuotaLimits::forget($quota->scope, $quota->scope_id));
        self::deleted(static fn (self $quota) => QuotaLimits::forget($quota->scope, $quota->scope_id));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => QuotaScope::class,
            'category' => QuotaCategory::class,
            'scope_id' => 'integer',
            'per_month' => 'integer',
            'per_minute' => 'integer',
            'warned_percent' => 'integer',
        ];
    }
}
