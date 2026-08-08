<?php

namespace App\Notifications;

use App\Enums\NotificationLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Die Nachricht selbst — kanalunabhängig. Der Alert-Kern (A2/A3) baut sie und
 * gibt sie an den Versand; wie daraus eine E-Mail, ein Slack-Anhang oder ein
 * Webhook-Rumpf wird, entscheidet allein der jeweilige Kanal.
 *
 * Sie ist unveränderlich und lässt sich verlustfrei in ein Array und zurück
 * überführen: so liegt sie im Zustellprotokoll und übersteht damit auch einen
 * späteren Wiederholungsversuch.
 */
final readonly class NotificationMessage
{
    /**
     * @param  array<string, string>  $context  Zusatzangaben als Beschriftung → Wert,
     *                                          z. B. „Projekt" → „Kasse", „Umgebung" → „produktiv".
     */
    public function __construct(
        public string $title,
        public string $body,
        public NotificationLevel $level = NotificationLevel::Error,
        public ?string $url = null,
        public array $context = [],
        public ?string $reference = null,
        public ?Carbon $occurredAt = null,
        /** Kennung des Zustellversuchs — erst beim Einreihen bekannt. */
        public ?int $deliveryId = null,
        /**
         * Diese Meldung duldet keinen Aufschub: sie wird nie gebündelt (A6),
         * sondern geht einzeln und sofort hinaus.
         *
         * Entschieden wird das vom Absender und nicht am Grad abgelesen:
         * derselbe Grad steht an einer Meldung, die jemanden aus dem Bett holt,
         * und an einer, die genauso gut in der Sammelnachricht von heute Abend
         * stehen könnte.
         */
        public bool $urgent = false,
    ) {}

    /**
     * Beispielnachricht für „Testnachricht senden". Sie durchläuft denselben
     * Weg wie eine echte Meldung — genau das ist der Zweck des Tests.
     */
    public static function test(string $organization): self
    {
        return new self(
            title: __('channels.test.title'),
            body: __('channels.test.body', ['organization' => $organization]),
            level: NotificationLevel::Info,
            context: [
                __('channels.test.context_organization') => $organization,
                __('channels.test.context_reason') => __('channels.test.context_reason_value'),
            ],
            reference: 'TEST-'.Str::upper(Str::random(6)),
            occurredAt: now(),
        );
    }

    /**
     * Dieselbe Nachricht, verknüpft mit einem Zustellversuch. Kanäle geben die
     * Kennung nach außen weiter (Webhook-Kopfzeile), damit der Empfänger einen
     * wiederholten Versuch als denselben erkennt.
     */
    public function forDelivery(int $deliveryId): self
    {
        return new self(
            title: $this->title,
            body: $this->body,
            level: $this->level,
            url: $this->url,
            context: $this->context,
            reference: $this->reference,
            occurredAt: $this->occurredAt,
            deliveryId: $deliveryId,
            urgent: $this->urgent,
        );
    }

    /**
     * Nutzlast fürs Protokoll und für den allgemeinen Webhook. Die Kennung des
     * Zustellversuchs gehört bewusst nicht dazu: sie beschreibt den Versuch,
     * nicht die Nachricht. Aus demselben Grund fehlt der Dringlichkeits-Schalter
     * — er sagt, **wann** zugestellt wird, und nicht, was drinsteht; in einem
     * Wartekorb (A6) liegt ohnehin nur, was nicht dringend war.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'level' => $this->level->value,
            'url' => $this->url,
            'context' => $this->context,
            'reference' => $this->reference,
            'occurredAt' => $this->occurredAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        /** @var array<string, string> $context */
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $occurredAt = $payload['occurredAt'] ?? null;

        return new self(
            title: (string) ($payload['title'] ?? ''),
            body: (string) ($payload['body'] ?? ''),
            level: NotificationLevel::tryFrom((string) ($payload['level'] ?? '')) ?? NotificationLevel::Error,
            url: isset($payload['url']) ? (string) $payload['url'] : null,
            context: $context,
            reference: isset($payload['reference']) ? (string) $payload['reference'] : null,
            occurredAt: is_string($occurredAt) ? Carbon::parse($occurredAt) : null,
        );
    }

    /**
     * Ein Fließtext aus Titel, Text und Zusatzangaben — für Kanäle ohne
     * eigenes Format (Slack-Vorschau, Discord-Inhalt).
     */
    public function asPlainText(): string
    {
        $lines = [$this->title, '', $this->body];

        foreach ($this->context as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        if ($this->url !== null) {
            $lines[] = $this->url;
        }

        return implode("\n", $lines);
    }
}
