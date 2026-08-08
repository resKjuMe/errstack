<?php

namespace App\Support\Feedback;

use App\Models\IngestPayload;
use App\Models\UserReport;
use Illuminate\Support\Carbon;

/**
 * Eine Rückmeldung, wie sie hereinkam — aus drei verschiedenen Formen gelesen.
 *
 * Die drei sind nicht ausgedacht, sie sind alle im Umlauf:
 *
 *   1. **Der klassische Absturzbericht.** Flach, vier Felder:
 *      `{"event_id":"…","name":"…","email":"…","comments":"…"}`. So schickt ihn
 *      der Endpunkt `/user-feedback/` und so steht er im Envelope-Element
 *      `user_report`.
 *   2. **Die neue Form.** Heutige SDKs schicken ein Element `feedback`, dessen
 *      Nutzdaten wie eine Meldung aussehen; der Text steht unter
 *      `contexts.feedback`, das gemeinte Ereignis unter
 *      `associated_event_id`. Die Nummer **oben** im Element ist dann die der
 *      Rückmeldung selbst und ausdrücklich nicht die des Fehlers — wer sie
 *      dafür hält, verknüpft jede Zuschrift mit sich selbst.
 *   3. **Das mitgelieferte Widget.** Wie (1), zusätzlich mit der Adresse der
 *      Seite und ohne Ereignisnummer.
 *
 * Gelesen wird großzügig und geschrieben streng: Feldnamen werden in den
 * bekannten Schreibweisen angenommen, alles Zulässige wird auf die Länge der
 * Spalte gekürzt. Eine Rückmeldung wegen eines zu langen Namens abzuweisen
 * hieße, den Text wegzuwerfen, um den es geht.
 */
final readonly class UserReportPayload
{
    private function __construct(
        public ?string $eventReference,
        public ?string $name,
        public ?string $email,
        public string $comments,
        public ?string $url,
    ) {}

    /**
     * Liest eine der drei Formen. `null`, wenn kein Text darin steht — eine
     * Rückmeldung ohne Beschreibung ist keine.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $feedback = self::section($data);

        $comments = self::text($feedback, ['comments', 'message', 'comment']);

        if ($comments === null) {
            return null;
        }

        return new self(
            eventReference: self::eventReference($data, $feedback),
            name: self::string($feedback, ['name', 'sender_name'], UserReport::NAME_LIMIT),
            email: self::string($feedback, ['email', 'contact_email', 'sender_email'], UserReport::EMAIL_LIMIT),
            comments: mb_substr($comments, 0, UserReport::COMMENTS_LIMIT),
            url: self::url($data, $feedback),
        );
    }

    /**
     * Der Abschnitt, in dem die Angaben stehen: bei der neuen Form
     * `contexts.feedback`, sonst der Rumpf selbst.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function section(array $data): array
    {
        $contexts = $data['contexts'] ?? null;
        $feedback = is_array($contexts) ? ($contexts['feedback'] ?? null) : null;

        return is_array($feedback) ? $feedback : $data;
    }

    /**
     * Die Nummer des Ereignisses, um das es geht.
     *
     * Vorrang hat `associated_event_id` aus der neuen Form: steht sie da, ist
     * die Nummer oben im Element die der Rückmeldung. Bei der flachen Form gibt
     * es nur `event_id`, und die meint den Fehler.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $feedback
     */
    private static function eventReference(array $data, array $feedback): ?string
    {
        $associated = IngestPayload::normalizeEventId($feedback['associated_event_id'] ?? null);

        if ($associated !== null) {
            return $associated;
        }

        // Nur wenn die Angaben **nicht** in einem eigenen Abschnitt stehen:
        // sonst gehört die Nummer oben zur Rückmeldung selbst.
        if ($feedback !== $data) {
            return null;
        }

        return IngestPayload::normalizeEventId($data['event_id'] ?? null);
    }

    /**
     * Die Seite, auf der die Rückmeldung entstand. Das Widget gibt sie direkt
     * an; ein SDK legt sie dorthin, wo es auch bei einer Meldung die Adresse
     * ablegt.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $feedback
     */
    private static function url(array $data, array $feedback): ?string
    {
        $direct = self::string($feedback, ['url', 'page_url'], UserReport::URL_LIMIT);

        if ($direct !== null) {
            return $direct;
        }

        $request = $data['request'] ?? null;

        return is_array($request)
            ? self::string($request, ['url'], UserReport::URL_LIMIT)
            : null;
    }

    /**
     * Der erste der genannten Schlüssel, der einen nicht-leeren Text trägt.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function text(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Dasselbe, auf die Länge der Spalte gekürzt.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function string(array $data, array $keys, int $limit): ?string
    {
        $value = self::text($data, $keys);

        return $value === null ? null : mb_substr($value, 0, $limit);
    }

    /**
     * Die Zeile, wie sie in der Ablage steht.
     *
     * @return array<string, mixed>
     */
    public function attributes(int $projectId, ?int $ingestPayloadId, Carbon $receivedAt): array
    {
        return [
            'project_id' => $projectId,
            'ingest_payload_id' => $ingestPayloadId,
            'event_reference' => $this->eventReference,
            'name' => $this->name,
            'email' => $this->email,
            'comments' => $this->comments,
            'url' => $this->url,
            'received_at' => $receivedAt,
        ];
    }
}
