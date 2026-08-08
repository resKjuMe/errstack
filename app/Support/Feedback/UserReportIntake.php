<?php

namespace App\Support\Feedback;

use App\Enums\UserReportStatus;
use App\Models\IngestPayload;
use App\Models\UserReport;

/**
 * Legt eine gelesene Rückmeldung ab und meldet sie weiter.
 *
 * Die Stelle, an der beide Wege zusammenlaufen — der eigene Endpunkt und das
 * Envelope-Element. Beide legen zuerst die Rohdaten ab und lassen die Kette
 * laufen; hier entsteht daraus die Zeile, die in der Liste steht.
 *
 * **Zweimal derselbe Beleg ergibt eine Zeile.** Die Warteschlange darf einen Job
 * erneut ausliefern, und ohne diese Zusage stünde eine Zuschrift nach einem
 * Neustart doppelt in der Liste — samt zweiter Benachrichtigung.
 */
final class UserReportIntake
{
    public function __construct(private readonly UserReportNotifier $notifier) {}

    /**
     * Nimmt die Rückmeldung zu einem abgelegten Beleg an.
     *
     * `null`, wenn der Beleg schon eine Rückmeldung hat — dann ist dieser
     * Durchlauf der zweite, und es gibt nichts zu tun.
     */
    public function accept(IngestPayload $payload, UserReportPayload $report): ?UserReport
    {
        $project = $payload->project;

        if ($project === null) {
            // Das Projekt wurde nach der Annahme gelöscht. Die Rohdaten sind
            // dann nur noch ein Beleg ohne Adressaten.
            return null;
        }

        $existing = UserReport::query()
            ->where('ingest_payload_id', $payload->id)
            ->first();

        if ($existing !== null) {
            return null;
        }

        /** @var UserReport $created */
        $created = UserReport::query()->create(
            // Der Eingang ist der der Rohdaten, nicht der dieses Durchlaufs:
            // zwischen Annahme und Auswertung liegt die Warteschlange, und
            // eingetroffen ist die Zuschrift, als sie ankam.
            $report->attributes($project->id, $payload->id, $payload->created_at) + [
                'status' => UserReportStatus::DEFAULT,
            ],
        );

        // Der Bezug zum Ereignis, sofern es schon ausgewertet ist. Ist es das
        // nicht, bleibt die genannte Nummer stehen und die Verknüpfung wird
        // beim Anzeigen nachgeholt ({@see UserReport::link()}).
        $created->resolveLink();

        $this->notifier->send($created);

        return $created;
    }
}
