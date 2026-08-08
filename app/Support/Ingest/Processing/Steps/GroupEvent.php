<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Models\Event;
use App\Models\EventGroup;
use App\Models\IssueDiscard;
use App\Support\Ingest\Grouping\Grouper;
use App\Support\Ingest\Normalization\NormalizedEvent;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Ordnet eine ausgewertete Meldung ihrer Gruppe zu.
 *
 * Der Schritt, an dem aus zehntausend gleichen Abstürzen ein Eintrag wird. Er
 * rechnet den Fingerabdruck, sucht oder legt die Gruppe an und schreibt beides
 * samt Begründung an das Ereignis.
 *
 * **Was hier nicht passiert:** zählen. Wie oft ein Fehler auftrat, wann zuerst
 * und wann zuletzt, wie viele Nutzer betroffen sind — das ist die Aggregation
 * (I6) und steht in der Kette danach. Die Trennung ist keine Förmlichkeit: das
 * Zählen muss sperrfrei und nebenläufigkeitssicher sein, das Gruppieren muss
 * dauerhaft dasselbe Ergebnis liefern. Zwei verschiedene Sorgen, zwei Schritte.
 *
 * **Was hier nicht aussortiert wird:** nichts. Eine Meldung, aus der sich kein
 * brauchbarer Fingerabdruck bilden lässt, bekommt trotzdem eine Gruppe — die
 * für Meldungen ohne unterscheidbaren Inhalt. Sie wegzuwerfen hieße, das
 * Merkwürdigste zu verlieren, was ankommt.
 */
final class GroupEvent implements ProcessingStep
{
    /**
     * Der Name, unter dem die Gruppe für die folgenden Schritte bereitliegt.
     *
     * I6 holt sie hier ab, statt sie am Ereignis nachzuschlagen — der Datensatz
     * liegt ohnehin schon vor.
     */
    public const RESULT = 'group';

    /**
     * Der Name, unter dem der Fingerabdruck bereitliegt.
     */
    public const FINGERPRINT = 'fingerprint';

    public function __construct(
        private readonly Grouper $grouper,
    ) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $event = $context->get(NormalizeEvent::RESULT);
        $record = $context->get(NormalizeEvent::RESULT.'_record');

        if (! $event instanceof NormalizedEvent || ! $record instanceof Event) {
            // Kein ausgewertetes Ereignis — ein Anhang, eine Sitzung, eine
            // Meldungsart, für die es (noch) keinen Normalisierer gibt.
            // Durchreichen und **nicht** aussortieren: sie ist kein Fehler, sie
            // gehört nur einem anderen Schritt.
            $next($context);

            return;
        }

        $fingerprint = $this->grouper->fingerprint(
            $event,
            Grouper::rulesFor($record->project_id),
        );

        // „Gelöscht und künftig verwerfen" (S6). Die Prüfung steht **hier** und
        // nicht im Eingangsfilter, weil sie den Fingerabdruck braucht — und sie
        // steht **vor** dem Anlegen der Gruppe, weil das Verwerfen sonst genau
        // das wieder anlegte, was es verhindern soll: Gruppe, Eintrag, Zähler.
        //
        // Das Ereignis ist zu diesem Zeitpunkt bereits gespeichert; es bleibt
        // ohne Gruppe liegen und wird mit der Aufbewahrungsfrist aufgeräumt
        // (O2). Früher abfangen ließe sich das nur vor dem Normalisieren — und
        // dort gibt es den Fingerabdruck noch nicht.
        if (IssueDiscard::blocks($record->project_id, $fingerprint->hash)) {
            $context->drop(DiscardReason::Discarded);

            return;
        }

        $group = EventGroup::forFingerprint($record->project_id, $fingerprint);

        // Der Fingerabdruck **zusätzlich** zur Gruppen-Kennung: nach dem
        // Zusammenführen von Hand (S9) zeigen mehrere Fingerabdrücke auf
        // denselben Eintrag, und ohne den Wert am Ereignis ließe sich nicht mehr
        // sagen, welche Untergruppe es war — das Auftrennen wäre dann nicht mehr
        // verlustfrei.
        $record->forceFill([
            'event_group_id' => $group->id,
            'fingerprint' => $fingerprint->hash,
            'grouping' => $fingerprint->toArray(),
        ])->save();

        $context->with(self::RESULT, $group);
        $context->with(self::FINGERPRINT, $fingerprint);

        $next($context);
    }
}
