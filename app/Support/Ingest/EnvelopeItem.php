<?php

namespace App\Support\Ingest;

use App\Enums\IngestType;

/**
 * Ein Element eines Envelope: sein Kopf und seine Nutzdaten.
 *
 * Die Nutzdaten sind **Bytes**, keine Zeichenkette im Sinne von Text — ein
 * Anhang kann ein Screenshot sein und eine Aufzeichnung ein gepackter Strom.
 * Deshalb wird hier nichts umkodiert, getrimmt oder geprüft: das Element geht
 * so weiter, wie es kam.
 */
final class EnvelopeItem
{
    /**
     * @param  array<string, mixed>  $header  Der Kopf des Elements, unverändert.
     * @param  string  $payload  Die Nutzdaten des Elements, unverändert.
     */
    public function __construct(
        public readonly array $header,
        public readonly string $payload,
    ) {}

    /**
     * Der rohe Typ aus dem Kopf — auch wenn wir ihn nicht kennen. `null`, wenn
     * das Element gar keinen angibt; dann ist es unbrauchbar, denn ohne Typ
     * lässt sich nicht sagen, was die Nutzdaten bedeuten.
     */
    public function rawType(): ?string
    {
        $type = $this->header['type'] ?? null;

        if (! is_string($type)) {
            return null;
        }

        $type = strtolower(trim($type));

        // Gekappt, weil der Wert vom Client kommt und in Zähler und
        // Protokollzeilen weiterwandert.
        return $type === '' ? null : mb_substr($type, 0, 32);
    }

    /**
     * Der Typ, sofern wir ihn verarbeiten können. `null` bei einem unbekannten
     * oder fehlenden Typ — beides führt dazu, dass das Element gezählt und
     * verworfen wird, ohne die übrige Anfrage zu gefährden.
     */
    public function type(): ?IngestType
    {
        $raw = $this->rawType();

        return $raw === null ? null : IngestType::tryFrom($raw);
    }

    /**
     * Die Meldungsnummer aus den Nutzdaten — nur dort, wo der Typ eine
     * mitbringt ({@see IngestType::carriesOwnEventId()}).
     */
    public function eventId(): ?string
    {
        $type = $this->type();

        if ($type === null || ! $type->carriesOwnEventId()) {
            return null;
        }

        $decoded = $this->decoded();

        return $decoded === null ? null : ($decoded['event_id'] ?? null);
    }

    /**
     * Die Nutzdaten als Feld-Baum, sofern sie JSON sind. `null` bei
     * Binärelementen und bei kaputtem JSON.
     *
     * @return array<string, mixed>|null
     */
    public function decoded(): ?array
    {
        // Eine Liste ist kein Element: alle JSON-Typen des Envelope sind
        // Objekte. {@see JsonObject} hält beides auseinander.
        return JsonObject::decode($this->payload);
    }

    public function sizeBytes(): int
    {
        return strlen($this->payload);
    }
}
