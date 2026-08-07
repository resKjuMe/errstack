<?php

namespace App\Support\Ingest;

use App\Exceptions\IngestRejection;
use App\Models\IngestPayload;

/**
 * Ein Envelope, zerlegt in Kopf und Elemente.
 *
 * Das Format ist zeilenorientiert, aber **nicht** zeilenbasiert lesbar:
 *
 *     {"event_id":"…","sent_at":"…"}\n
 *     {"type":"event","length":41}\n
 *     {"event_id":"…","message":"Kaputt"}\n
 *     {"type":"attachment","length":9,"filename":"a.png"}\n
 *     <9 Byte, dürfen \n enthalten>\n
 *     {"type":"session"}\n
 *     {"sid":"…"}
 *
 * Steht im Kopf eines Elements eine `length`, gilt sie: es folgen genau so
 * viele Byte, und die dürfen Zeilenumbrüche enthalten. Fehlt sie, reicht das
 * Element bis zum nächsten Umbruch. Wer stattdessen einfach an `\n` zerlegt,
 * verliert jeden Anhang, der zufällig ein `0x0A` enthält — also praktisch jeden
 * Screenshot.
 *
 * Der abschließende Umbruch nach dem letzten Element ist freigestellt; beide
 * Varianten kommen im Feld vor.
 *
 * **Ein kaputtes Element darf die anderen nicht mitnehmen.** Der Aufnahme-Weg
 * ist die letzte Station eines Fehlers, der in der überwachten Anwendung schon
 * passiert ist — wird hier die ganze Anfrage abgewiesen, sind auch die
 * heilen Elemente weg, und das SDK schickt sie nicht noch einmal. Deshalb
 * scheitert nur, was ohne Kopfzeile gar nicht erst als Envelope durchgeht;
 * alles Weitere wird einzeln verworfen und gezählt.
 */
final class Envelope
{
    /**
     * @param  array<string, mixed>  $header  Der Envelope-Kopf.
     * @param  list<EnvelopeItem>  $items  Die lesbaren Elemente, in Reihenfolge.
     * @param  int  $unreadable  Wie viele Elemente sich nicht lesen ließen.
     */
    private function __construct(
        public readonly array $header,
        public readonly array $items,
        public readonly int $unreadable,
    ) {}

    /**
     * Zerlegt einen Envelope.
     *
     * @throws IngestRejection wenn die Kopfzeile fehlt oder kein JSON-Objekt ist
     */
    public static function parse(string $raw): self
    {
        $offset = 0;
        $headerLine = self::readLine($raw, $offset);
        $header = self::decodeObject($headerLine);

        if ($header === null) {
            // Ohne Kopfzeile ist es kein Envelope. Das ist der einzige Fall, in
            // dem die ganze Anfrage abgewiesen wird: hier lässt sich nicht
            // einmal sagen, wo das erste Element anfängt.
            throw IngestRejection::unreadable('ingest.envelope_header');
        }

        $items = [];
        $unreadable = 0;
        $length = strlen($raw);

        while ($offset < $length) {
            $line = self::readLine($raw, $offset);

            // Leerzeilen zwischen den Elementen kommen vor (ein Element ohne
            // `length` und mit leeren Nutzdaten hinterlässt eine). Sie sind
            // kein Element und kein Fehler.
            if (trim($line) === '') {
                continue;
            }

            $itemHeader = self::decodeObject($line);

            if ($itemHeader === null) {
                // Ohne Kopf des Elements ist unbekannt, wie weit seine
                // Nutzdaten reichen — ab hier lässt sich nicht mehr sinnvoll
                // weiterlesen, ohne zu raten. Der Rest gilt als ein
                // unlesbares Element.
                return new self($header, $items, $unreadable + 1);
            }

            $payload = self::readPayload($raw, $offset, $itemHeader);

            if ($payload === null) {
                // Angekündigte Länge, aber so viel steht nicht mehr da: der
                // Envelope ist unterwegs abgeschnitten worden.
                return new self($header, $items, $unreadable + 1);
            }

            $items[] = new EnvelopeItem($itemHeader, $payload);
        }

        return new self($header, $items, $unreadable);
    }

    /**
     * Die Meldungsnummer des Envelope: die aus dem Kopf, ersatzweise die des
     * ersten Elements, das eine mitbringt.
     *
     * Sie steht in der Antwort und ist die Nummer, unter der Anhänge und
     * Aufzeichnungen zu ihrer Meldung gehören. `null`, wenn der Envelope keine
     * enthält — bei einem reinen Sitzungs- oder Verworfen-Envelope ist das der
     * Normalfall.
     */
    public function eventId(): ?string
    {
        $fromHeader = IngestPayload::normalizeEventId($this->header['event_id'] ?? null);

        if ($fromHeader !== null) {
            return $fromHeader;
        }

        foreach ($this->items as $item) {
            $fromItem = IngestPayload::normalizeEventId($item->eventId());

            if ($fromItem !== null) {
                return $fromItem;
            }
        }

        return null;
    }

    /**
     * Name und Fassung des SDK aus dem Envelope-Kopf (`{"sdk":{"name":…,
     * "version":…}}`), in derselben Form wie `sentry_client` der Zugangsdaten.
     *
     * Steht dort nichts Brauchbares, gilt weiter die Angabe aus den
     * Zugangsdaten — der Aufrufer entscheidet das.
     */
    public function sdk(): ?string
    {
        $sdk = $this->header['sdk'] ?? null;

        if (! is_array($sdk)) {
            return null;
        }

        $name = $sdk['name'] ?? null;
        $version = $sdk['version'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        $client = is_string($version) && $version !== '' ? "{$name}/{$version}" : $name;

        return mb_substr($client, 0, 255);
    }

    /**
     * Liest eine Zeile ab `$offset` und setzt `$offset` hinter deren Umbruch.
     * Am Ende ohne Umbruch endet die Zeile beim Dateiende.
     */
    private static function readLine(string $raw, int &$offset): string
    {
        $break = strpos($raw, "\n", $offset);

        if ($break === false) {
            $line = substr($raw, $offset);
            $offset = strlen($raw);

            return $line;
        }

        $line = substr($raw, $offset, $break - $offset);
        $offset = $break + 1;

        return $line;
    }

    /**
     * Liest die Nutzdaten eines Elements. `null`, wenn eine Länge angekündigt
     * war, die der Envelope nicht mehr hergibt.
     *
     * @param  array<string, mixed>  $itemHeader
     */
    private static function readPayload(string $raw, int &$offset, array $itemHeader): ?string
    {
        $length = self::declaredLength($itemHeader);

        if ($length === null) {
            return self::readLine($raw, $offset);
        }

        $payload = substr($raw, $offset, $length);

        if (strlen($payload) < $length) {
            $offset = strlen($raw);

            return null;
        }

        $offset += $length;

        // Der Umbruch hinter den Nutzdaten gehört zum Format und nicht zum
        // Element. Am Ende darf er fehlen.
        if (($raw[$offset] ?? null) === "\n") {
            $offset++;
        }

        return $payload;
    }

    /**
     * Die angekündigte Länge, sofern sie brauchbar ist.
     *
     * Eine unsinnige Angabe (Text, Bruch, negativ) wird behandelt, als stünde
     * sie nicht da: dann endet das Element am nächsten Umbruch. Das ist die
     * mildere Auslegung — bei Binärdaten geht dabei zwar etwas kaputt, aber ein
     * Element mit kaputtem Kopf soll nicht die folgenden verschieben.
     *
     * @param  array<string, mixed>  $itemHeader
     */
    private static function declaredLength(array $itemHeader): ?int
    {
        $length = $itemHeader['length'] ?? null;

        if (is_int($length)) {
            return $length >= 0 ? $length : null;
        }

        // Manche SDKs schreiben die Länge als Zeichenkette.
        if (is_string($length) && preg_match('/^\d+$/', $length) === 1) {
            return (int) $length;
        }

        return null;
    }

    /**
     * Eine Zeile als JSON-Objekt. `null` bei allem anderen — auch bei einer
     * Liste oder einem nackten Wert, denn beides ist kein Kopf.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeObject(string $line): ?array
    {
        return JsonObject::decode(trim($line));
    }
}
