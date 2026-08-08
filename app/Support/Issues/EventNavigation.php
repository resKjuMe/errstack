<?php

namespace App\Support\Issues;

use App\Models\Event;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Das Blättern zwischen den Meldungen eines Fehler-Eintrags.
 *
 * Ein Eintrag steht für viele Meldungen, und die Detailseite zeigt genau eine.
 * Welche das ist, entscheidet sich hier — und zwar über dieselbe Ordnung, die
 * auch die Liste benutzt: nach Zeitpunkt, bei gleichem Zeitpunkt nach Kennung.
 * Ohne den zweiten Schlüssel hätten zwei Meldungen derselben Sekunde keine
 * feste Reihenfolge, und „ältere" führte im Kreis.
 *
 * **Die Meldungen werden über ihre Gruppen ausgewählt, nicht über einen
 * Verbund.** `Issue::events()` verbindet zwei Tabellen, in denen beide eine
 * Spalte `id` haben — jede Sortierung danach wäre mehrdeutig. Die Untertabelle
 * der Gruppenkennungen liest dieselbe Menge, trifft dabei den Index auf
 * `event_group_id` und lässt die Spaltennamen eindeutig.
 *
 * **Gezählt wird hier nicht.** Wie oft ein Fehler aufgetreten ist, steht am
 * Eintrag (`times_seen`); ein `count(*)` über die Meldungen wäre dieselbe
 * Auskunft zum Preis eines Tabellendurchlaufs.
 */
final class EventNavigation
{
    /**
     * Die Meldung, die aufgeschlagen wird, wenn keine genannt ist: die neueste.
     */
    public static function newest(Issue $issue): ?Event
    {
        return self::ordered($issue, newestFirst: true)->first();
    }

    /**
     * Gehört diese Meldung zu diesem Eintrag?
     *
     * Die Frage steht nicht aus Ordnungsliebe: Kennung des Eintrags und Kennung
     * der Meldung stehen beide in der Adresszeile, und eine vertauschte Zeile
     * darf keine fremde Meldung unter fremdem Kopf zeigen.
     */
    public static function belongsTo(Issue $issue, Event $event): bool
    {
        return self::query($issue)->whereKey($event->getKey())->exists();
    }

    /**
     * Die Wege von dieser Meldung aus — als fertige Adressen für die Oberfläche.
     *
     * `null` heißt „gibt es nicht": an der neuesten Meldung fehlt der Weg nach
     * vorn, an der ältesten der nach hinten. Die Oberfläche schaltet die
     * Schaltfläche dann ab, statt eine Adresse anzubieten, die auf dieselbe
     * Seite führt.
     *
     * @return array{newest: ?string, newer: ?string, older: ?string, oldest: ?string}
     */
    public static function links(Issue $issue, Event $event): array
    {
        return [
            'newest' => self::href($issue, self::newest($issue), $event),
            'newer' => self::href($issue, self::neighbour($issue, $event, newer: true), $event),
            'older' => self::href($issue, self::neighbour($issue, $event, newer: false), $event),
            'oldest' => self::href($issue, self::ordered($issue, newestFirst: false)->first(), $event),
        ];
    }

    /**
     * Die Meldung unmittelbar vor oder nach dieser.
     *
     * Verglichen wird das Paar aus Zeitpunkt und Kennung, nicht nur der
     * Zeitpunkt: bei gleichem Zeitpunkt entscheidet die Kennung, und ohne diesen
     * zweiten Vergleich stünde die Nachbarin von zwei gleichzeitigen Meldungen
     * mal auf der einen, mal auf der anderen Seite.
     */
    private static function neighbour(Issue $issue, Event $event, bool $newer): ?Event
    {
        $comparison = $newer ? '>' : '<';

        return self::ordered($issue, newestFirst: ! $newer)
            ->where(function (Builder $query) use ($event, $comparison): void {
                $query
                    ->where('occurred_at', $comparison, $event->occurred_at)
                    ->orWhere(function (Builder $query) use ($event, $comparison): void {
                        $query
                            ->where('occurred_at', '=', $event->occurred_at)
                            ->where('id', $comparison, $event->getKey());
                    });
            })
            ->first();
    }

    /**
     * @return Builder<Event>
     */
    private static function ordered(Issue $issue, bool $newestFirst): Builder
    {
        $direction = $newestFirst ? 'desc' : 'asc';

        return self::query($issue)
            ->orderBy('occurred_at', $direction)
            ->orderBy('id', $direction);
    }

    /**
     * Die Meldungen dieses Eintrags — **samt** denen seiner Untergruppen.
     *
     * Ein von Hand zusammengeführter Eintrag (S9) steht für mehrere
     * Fingerabdrücke, und das Blättern soll durch alle führen: wer zwei Einträge
     * zusammenführt, will danach eine Geschichte lesen und nicht zwei.
     *
     * @return Builder<Event>
     */
    private static function query(Issue $issue): Builder
    {
        return Event::query()->whereIn('event_group_id', $issue->groupIds());
    }

    /**
     * Die Adresse einer Meldung — oder `null`, wenn es die Meldung nicht gibt
     * oder sie die ist, auf der man ohnehin steht.
     */
    private static function href(Issue $issue, ?Event $target, Event $current): ?string
    {
        if ($target === null || $target->getKey() === $current->getKey()) {
            return null;
        }

        return route('issues.events.show', [$issue, $target]);
    }
}
