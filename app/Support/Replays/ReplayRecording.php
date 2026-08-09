<?php

namespace App\Support\Replays;

use Carbon\CarbonImmutable;

/**
 * Ein Abschnitt Film, aus den Rohdaten des Elements `replay_recording` gelesen.
 *
 * Das Element ist das einzige der ganzen Aufnahme, das **kein** JSON ist und
 * trotzdem eines enthält. Sein Aufbau:
 *
 *     {"segment_id":7}\n<gepackte rrweb-Ereignisse>
 *
 * Die erste Zeile ist der Kopf, alles danach der Inhalt — meist mit
 * zlib gepackt, bei abgeschalteter Komprimierung im SDK auch blank. Beide
 * Formen sind im Umlauf; wer nur eine liest, verliert die andere ganz.
 *
 * Was hier herauskommt, sind die rrweb-Ereignisse als Feld-Baum. Was sie
 * bedeuten, weiß diese Klasse **nicht** — sie zählt sie, liest ihre Zeitstempel
 * und gibt sie weiter. Die Deutung ist Sache der Zeitleiste
 * ({@see ReplayTimeline}), das Abspielen Sache des Browsers.
 */
final class ReplayRecording
{
    /**
     * Die Kennzeichen der beiden Packverfahren, die vorkommen.
     *
     * zlib beginnt mit `0x78` und einem Prüfbyte, gzip mit `0x1f 0x8b`. Am
     * Anfang zu erkennen und nicht zu erraten ist wichtiger, als es aussieht:
     * `gzuncompress()` auf ungepackte Daten gibt `false` zurück, und ein
     * Abschnitt, der daran scheitert, wäre eine Lücke mitten im Film.
     */
    private const ZLIB_FIRST_BYTE = 0x78;

    private const GZIP_MAGIC = "\x1f\x8b";

    /**
     * @param  int  $segmentId  Die laufende Nummer — die Abspielreihenfolge.
     * @param  list<array<string, mixed>>  $events  Die rrweb-Ereignisse.
     * @param  int  $droppedEvents  Wie viele über die Grenze fielen.
     */
    private function __construct(
        public readonly int $segmentId,
        public readonly array $events,
        public readonly CarbonImmutable $startedAt,
        public readonly CarbonImmutable $endedAt,
        public readonly int $droppedEvents,
    ) {}

    /**
     * Liest einen Abschnitt.
     *
     * `null` bei allem, was sich nicht als Abschnitt deuten lässt: kein lesbarer
     * Inhalt, keine Liste von Ereignissen, keine brauchbaren Zeitstempel. Der
     * Aufrufer verwirft die Meldung dann und zählt sie — liegenzulassen wäre die
     * schlechtere Antwort, denn ein Abschnitt ohne Zeit ist im Film nicht
     * einzuordnen.
     *
     * @param  int|null  $fallbackSegmentId  Nummer aus dem Element-Kopf, falls der
     *                                       Rumpf keine mitbringt.
     * @param  int  $maxEvents  Obergrenze für die Zahl der Ereignisse.
     */
    public static function fromBytes(string $raw, ?int $fallbackSegmentId, int $maxEvents): ?self
    {
        [$header, $body] = self::split($raw);

        $payload = self::inflate($body);

        if ($payload === null) {
            return null;
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $segmentId = self::segmentId($header) ?? $fallbackSegmentId;

        if ($segmentId === null) {
            return null;
        }

        $events = [];
        $dropped = 0;
        $first = null;
        $last = null;

        foreach ($decoded as $event) {
            if (! is_array($event) || array_is_list($event)) {
                // Ein Eintrag, der kein rrweb-Ereignis ist. Er wird übergangen
                // und nicht gezählt wie ein Überschuss: er ist kein verlorener
                // Bildinhalt, sondern Unrat.
                continue;
            }

            if (count($events) >= $maxEvents) {
                $dropped++;

                continue;
            }

            $at = self::timestamp($event['timestamp'] ?? null);

            if ($at !== null) {
                $first = $first === null || $at->lessThan($first) ? $at : $first;
                $last = $last === null || $at->greaterThan($last) ? $at : $last;
            }

            /** @var array<string, mixed> $event */
            $events[] = $event;
        }

        if ($events === [] || $first === null || $last === null) {
            return null;
        }

        return new self(
            segmentId: max(0, $segmentId),
            events: $events,
            startedAt: $first,
            endedAt: $last,
            droppedEvents: $dropped,
        );
    }

    /**
     * Die Ereignisse als JSON, so wie sie abgelegt und später wieder ausgeliefert
     * werden.
     *
     * Bewusst neu geschrieben statt die Eingangs-Bytes durchzureichen: dann liegt
     * auf der Platte genau **eine** Form, und das Abspielen muss nicht wissen,
     * mit welchem Verfahren ein Abschnitt vor Monaten gepackt ankam. Der Preis
     * ist ein Kodiervorgang je Abschnitt — im Vergleich zum Packen, das ohnehin
     * folgt, nicht der Rede wert.
     *
     * `JSON_UNESCAPED_UNICODE`, weil der Bildinhalt aus Text besteht: die
     * Umlaute einer deutschen Anwendung als `\uXXXX` zu schreiben, verdoppelt
     * ihre Größe für nichts.
     */
    public function toJson(): string
    {
        return (string) json_encode($this->events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Trennt Kopfzeile und Inhalt.
     *
     * Ohne Zeilenumbruch gibt es keinen Kopf — dann ist alles Inhalt, und die
     * Nummer muss aus dem Element-Kopf kommen. Dieser Fall ist nicht theoretisch:
     * ältere SDKs schicken den Abschnitt ohne eigene Kopfzeile.
     *
     * @return array{string|null, string}
     */
    private static function split(string $raw): array
    {
        $newline = strpos($raw, "\n");

        if ($newline === false) {
            return [null, $raw];
        }

        $header = substr($raw, 0, $newline);

        // Nur eine Kopfzeile, die auch wie eine aussieht. Ein gepackter
        // Datenstrom enthält Zeilenumbrüche als gewöhnliche Bytes, und den
        // ersten davon für einen Kopf zu halten hieße, den Anfang des Films
        // abzuschneiden.
        if (! str_starts_with(ltrim($header), '{')) {
            return [null, $raw];
        }

        return [$header, substr($raw, $newline + 1)];
    }

    /**
     * Packt den Inhalt aus, sofern er gepackt ist.
     *
     * Entschieden wird am Inhalt und nicht an einer Angabe des Absenders: das
     * SDK packt je nach Fassung und Einstellung, und ein Kopffeld dafür gibt es
     * nicht.
     */
    private static function inflate(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        if (str_starts_with($body, self::GZIP_MAGIC)) {
            $decoded = @gzdecode($body);

            return $decoded === false ? null : $decoded;
        }

        if (ord($body[0]) === self::ZLIB_FIRST_BYTE) {
            $decoded = @gzuncompress($body);

            return $decoded === false ? null : $decoded;
        }

        return $body;
    }

    /**
     * Die Nummer des Abschnitts aus der Kopfzeile.
     */
    private static function segmentId(?string $header): ?int
    {
        if ($header === null) {
            return null;
        }

        $decoded = json_decode($header, true);

        if (! is_array($decoded)) {
            return null;
        }

        $segmentId = $decoded['segment_id'] ?? null;

        return is_int($segmentId) || (is_string($segmentId) && ctype_digit($segmentId))
            ? (int) $segmentId
            : null;
    }

    /**
     * Der Zeitstempel eines rrweb-Ereignisses.
     *
     * rrweb zählt in **Millisekunden** seit 1970 und nicht wie die SDKs im
     * Übrigen in Sekunden mit Bruchteilen. Das ist der eine Stolperstein an
     * diesen Daten, und er fällt nicht auf: eine Sekunden-Auslegung ergibt
     * Zeitpunkte im Jahr 58026, die keine Datenbank annimmt — und der Abschnitt
     * wäre ein Fehlschlag statt eines Films.
     */
    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        if (! is_finite((float) $value) || $value <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMs((int) $value)->utc();
    }
}
