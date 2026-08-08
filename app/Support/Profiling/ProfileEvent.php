<?php

namespace App\Support\Profiling;

use App\Models\Transaction;
use App\Support\Performance\PayloadReader;
use Carbon\CarbonImmutable;

/**
 * Ein gemeldetes Sample-Profil, geprüft und ablagefertig.
 *
 * Hier steht das Wissen über das Profil-Schema von Sentry, und zwar an dieser
 * einen Stelle. Es sieht so aus:
 *
 *     {
 *       "event_id": "…", "version": "1", "platform": "php",
 *       "transaction": {"id": "…", "name": "GET /projects",
 *                       "trace_id": "…", "active_thread_id": "1"},
 *       "profile": {
 *         "frames":  [{"function": "handle", "filename": "…", "lineno": 42}],
 *         "stacks":  [[0, 3, 7]],
 *         "samples": [{"stack_id": 0, "thread_id": "1",
 *                      "elapsed_since_start_ns": 10000000}]
 *       }
 *     }
 *
 * Zwei Dinge daran sind leicht zu übersehen und beide entscheidend: die Stapel
 * stehen **von innen nach außen** (der zuerst genannte Rahmen ist der gerade
 * laufende), und die Stichproben tragen keine Dauer, sondern nur einen
 * Zeitpunkt. Was eine Stichprobe „kostet", ist der Abstand zur nächsten —
 * {@see weights()}.
 *
 * Unterstützt wird ausschließlich das Sample-Format. Was Sentry als
 * `profile_chunk` für durchgehendes Profiling schickt, ist ein anderes Schema
 * mit einem anderen Bezug zur Transaktion; es hier stillschweigend mitzulesen
 * hieße, aus Bruchstücken eine Messung zu bauen, die es nicht gibt.
 */
final class ProfileEvent
{
    /**
     * Die Fassung des Formats, die wir lesen.
     */
    public const VERSION = '1';

    /**
     * Wie viele Stichproben je Profil ausgewertet werden.
     *
     * Die Grenze greift vor dem Aufbau des Baums. Ein Element von einem Megabyte
     * trägt je nach Anwendung einige zehntausend Stichproben; mehr als das
     * ändert am Bild nichts mehr, kostet aber Zeit in der Verarbeitung jeder
     * einzelnen Meldung.
     */
    public const MAX_SAMPLES = 50_000;

    /**
     * Wie tief ein Aufrufstapel abgelegt wird.
     *
     * Tiefer wird abgeschnitten, und zwar **unten** — die inneren Rahmen fallen
     * weg, die Wurzel bleibt. Das ist die Richtung, in der ein Flamegraph noch
     * etwas aussagt: ohne Wurzeln wären die Balken nicht mehr zuzuordnen.
     */
    public const MAX_STACK_DEPTH = 128;

    /**
     * Die Zeit, die eine Stichprobe abdeckt, wenn sich aus den Zeitpunkten
     * nichts ableiten lässt (alle gleich, keiner lesbar).
     *
     * Zehn Millisekunden ist die übliche Abtastrate der SDKs. Der Wert
     * verändert die **Verhältnisse** im Bild nicht — alle Stichproben bekommen
     * dasselbe Gewicht —, sondern nur die absolute Beschriftung. Genau deshalb
     * ist ein Ersatzwert hier vertretbar und ein Weglassen der Messung nicht:
     * „welche Funktion verbraucht den größten Anteil" ist auch ohne verlässliche
     * Absolutzeit zu beantworten.
     */
    public const FALLBACK_INTERVAL_NS = 10_000_000;

    /**
     * @param  string  $transactionEventId  Die Nummer der Transaktion, zu der das Profil gehört.
     * @param  int  $foreignSamples  Stichproben anderer Ausführungsstränge, die nicht eingegangen sind.
     * @param  int  $unreadableSamples  Stichproben ohne brauchbaren Stapel oder Zeitpunkt.
     * @param  int  $truncatedStacks  Stapel, die wegen {@see MAX_STACK_DEPTH} gekürzt wurden.
     * @param  int  $excessSamples  Stichproben über {@see MAX_SAMPLES} hinaus.
     */
    private function __construct(
        public readonly string $profileId,
        public readonly string $transactionEventId,
        public readonly ?string $traceId,
        public readonly ?string $transactionName,
        public readonly ?string $platform,
        public readonly ?string $environment,
        public readonly ?string $release,
        public readonly ?string $threadId,
        public readonly CarbonImmutable $startedAt,
        public readonly CallTree $tree,
        public readonly int $durationUs,
        public readonly int $foreignSamples,
        public readonly int $unreadableSamples,
        public readonly int $truncatedStacks,
        public readonly int $excessSamples,
    ) {}

    /**
     * Liest ein Profil aus dem entpackten Rumpf einer Meldung.
     *
     * `null` heißt: als Profil unbrauchbar. Das sind genau drei Fälle — ein
     * anderes Format als das Sample-Format, keine Nummer der Transaktion, oder
     * keine einzige verwertbare Stichprobe. Alles andere wird ersetzt oder
     * gezählt: ein Profil ohne Namen findet seinen über die Transaktion, ein
     * Profil ohne Zeitpunkt beginnt mit dem der Transaktion.
     *
     * Die Nummer der Transaktion ist der harte Fall, und zwar aus einem Grund,
     * der sich nicht umgehen lässt: ein Profil sagt „hier wurde gerechnet". Wozu
     * gerechnet wurde, steht ausschließlich in dieser Nummer.
     *
     * @param  array<mixed>  $data  Der Rumpf, wie IngestPayload::decoded() ihn liefert.
     * @param  string  $eventId  Die Nummer, unter der die Meldung geführt wird.
     */
    public static function fromPayload(array $data, string $eventId): ?self
    {
        $version = PayloadReader::text($data['version'] ?? null, 16);

        if ($version !== null && $version !== self::VERSION) {
            return null;
        }

        $transaction = self::transactionInfo($data);
        $transactionEventId = PayloadReader::hex($transaction['id'] ?? null, 32);

        if ($transactionEventId === null) {
            return null;
        }

        $profile = PayloadReader::map($data['profile'] ?? null);

        if ($profile === null) {
            return null;
        }

        $threadId = self::threadId($transaction['active_thread_id'] ?? null);
        $samples = self::samples($profile['samples'] ?? null, $threadId);

        if ($samples->paths === []) {
            return null;
        }

        $frames = self::frames($profile['frames'] ?? null);
        $stacks = self::stacks($profile['stacks'] ?? null);
        [$paths, $truncated, $unreadable] = self::resolve($samples->paths, $stacks);

        if ($paths === []) {
            return null;
        }

        $tree = CallTree::build($frames, $paths);

        return new self(
            profileId: PayloadReader::hex($data['event_id'] ?? null, 32) ?? $eventId,
            transactionEventId: $transactionEventId,
            traceId: PayloadReader::hex($transaction['trace_id'] ?? null, 32),
            transactionName: PayloadReader::text($transaction['name'] ?? null, Transaction::NAME_LIMIT),
            platform: PayloadReader::text($data['platform'] ?? null, 32),
            environment: PayloadReader::text($data['environment'] ?? null, 64),
            release: PayloadReader::text($data['release'] ?? null, 255),
            threadId: $samples->threadId,
            startedAt: PayloadReader::time($data['timestamp'] ?? null) ?? CarbonImmutable::now(),
            tree: $tree,
            durationUs: intdiv($tree->totalNs, 1000),
            foreignSamples: $samples->foreign,
            unreadableSamples: $samples->unreadable + $unreadable,
            truncatedStacks: $truncated,
            excessSamples: $samples->excess,
        );
    }

    /**
     * Die Angaben zur Transaktion — aus `transaction` oder, bei älteren SDKs,
     * aus dem ersten Eintrag von `transactions`.
     *
     * Die Mehrzahl gab es, als ein Profil noch über mehrere Aufrufe laufen
     * durfte. Genommen wird davon der erste und nicht alle: ein Profil in
     * **einer** Zeile kann nur zu **einer** Transaktion gehören, und die Zeit
     * auf mehrere aufzuteilen wäre geraten — der Aufrufbaum sagt nicht, welcher
     * Ast zu welchem Aufruf gehörte.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private static function transactionInfo(array $data): array
    {
        $single = PayloadReader::map($data['transaction'] ?? null);

        if ($single !== null) {
            return $single;
        }

        $many = $data['transactions'] ?? null;

        if (! is_array($many)) {
            return [];
        }

        foreach ($many as $entry) {
            $entry = PayloadReader::map($entry);

            if ($entry !== null) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * Die Rahmentabelle des Profils.
     *
     * @return list<ProfileFrame>
     */
    private static function frames(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $frames = [];

        foreach ($raw as $frame) {
            // Auch ein unlesbarer Eintrag bekommt seinen Platz: der Baum
            // verweist über die **Position**, und ein übersprungener Eintrag
            // würde alle folgenden Verweise um eins verschieben — jeder Balken
            // trüge danach den Namen seines Nachbarn.
            $frames[] = ProfileFrame::fromArray(is_array($frame) ? $frame : []);
        }

        return $frames;
    }

    /**
     * Die Stapeltabelle: je Eintrag die Rahmen-Plätze, von innen nach außen.
     *
     * @return array<int, list<int>>
     */
    private static function stacks(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $stacks = [];

        foreach ($raw as $position => $stack) {
            if (! is_int($position) || ! is_array($stack)) {
                continue;
            }

            $frames = [];

            foreach ($stack as $frame) {
                if (is_int($frame) && $frame >= 0) {
                    $frames[] = $frame;
                }
            }

            $stacks[$position] = $frames;
        }

        return $stacks;
    }

    /**
     * Macht aus Stapel-Verweisen die Wege, aus denen der Baum entsteht.
     *
     * Hier wird gedreht: aus „innerste Funktion zuerst" wird „Wurzel zuerst".
     *
     * @param  list<array{int, int}>  $samples  Je Stichprobe: Stapel-Platz und Gewicht in Nanosekunden.
     * @param  array<int, list<int>>  $stacks
     * @return array{list<array{list<int>, int}>, int, int}
     */
    private static function resolve(array $samples, array $stacks): array
    {
        $paths = [];
        $truncated = 0;
        $unreadable = 0;

        foreach ($samples as [$stackId, $weightNs]) {
            $stack = $stacks[$stackId] ?? null;

            if ($stack === null || $stack === []) {
                // Ein leerer Stapel ist keine Fehlmeldung, sondern der
                // Regelfall in Leerlaufphasen: das Profil hat gemessen, während
                // nichts lief. Gezählt wird es trotzdem — verschwiegen sähe ein
                // Profil mit 90 % Leerlauf aus wie eines mit voller Auslastung.
                $unreadable++;

                continue;
            }

            if (count($stack) > self::MAX_STACK_DEPTH) {
                $stack = array_slice($stack, -self::MAX_STACK_DEPTH);
                $truncated++;
            }

            $paths[] = [array_reverse($stack), $weightNs];
        }

        return [$paths, $truncated, $unreadable];
    }

    /**
     * Liest die Stichproben des gemessenen Ausführungsstrangs und wiegt sie.
     */
    private static function samples(mixed $raw, ?string $activeThreadId): SampleSet
    {
        if (! is_array($raw)) {
            return SampleSet::empty();
        }

        /** @var array<string, list<array{int, int}>> $byThread  Je Strang: Stapel-Platz und Zeitpunkt. */
        $byThread = [];
        $unreadable = 0;
        $excess = 0;
        $read = 0;

        foreach ($raw as $sample) {
            $sample = is_array($sample) ? $sample : null;
            $stackId = $sample === null ? null : ($sample['stack_id'] ?? null);
            $elapsed = $sample === null ? null : self::nanoseconds($sample['elapsed_since_start_ns'] ?? null);

            if (! is_int($stackId) || $elapsed === null) {
                $unreadable++;

                continue;
            }

            if ($read >= self::MAX_SAMPLES) {
                $excess++;

                continue;
            }

            $read++;
            $thread = self::threadId($sample['thread_id'] ?? null) ?? '';
            $byThread[$thread][] = [$stackId, $elapsed];
        }

        if ($byThread === []) {
            return new SampleSet([], null, 0, $unreadable, $excess);
        }

        $threadId = self::chooseThread($byThread, $activeThreadId);
        $chosen = $byThread[$threadId];
        $foreign = 0;

        foreach ($byThread as $thread => $samples) {
            if ($thread !== $threadId) {
                $foreign += count($samples);
            }
        }

        return new SampleSet(
            paths: self::weights($chosen),
            threadId: $threadId === '' ? null : $threadId,
            foreign: $foreign,
            unreadable: $unreadable,
            excess: $excess,
        );
    }

    /**
     * Welcher Ausführungsstrang ausgewertet wird.
     *
     * Der, in dem die Transaktion lief — das SDK sagt ihn im Feld
     * `active_thread_id`. Ohne diese Angabe der mit den meisten Stichproben.
     *
     * **Einer**, nicht alle zusammengelegt: die übrigen Stränge einer Anwendung
     * warten typischerweise, und ihre Wartezeit im selben Bild würde den Anteil
     * des rechnenden Codes kleinrechnen. Was wegfällt, wird gezählt
     * ({@see ProfileEvent::$foreignSamples}).
     *
     * @param  array<string, list<array{int, int}>>  $byThread
     */
    private static function chooseThread(array $byThread, ?string $activeThreadId): string
    {
        if ($activeThreadId !== null && isset($byThread[$activeThreadId])) {
            return $activeThreadId;
        }

        $best = '';
        $count = -1;

        foreach ($byThread as $thread => $samples) {
            if (count($samples) > $count) {
                $best = (string) $thread;
                $count = count($samples);
            }
        }

        return $best;
    }

    /**
     * Wiegt die Stichproben eines Strangs: was jede von ihnen an Zeit abdeckt.
     *
     * Eine Stichprobe ist ein Augenblick, keine Dauer. Sie steht für die Zeit
     * **bis zur nächsten** — genau deshalb ist der Abstand das Gewicht und nicht
     * etwa ein fester Takt: hält die Anwendung für eine Sekunde an (Speicher
     * aufräumen, auf eine Sperre warten), liegt zwischen zwei Stichproben eine
     * Sekunde, und die gehört zu der Funktion, die zu diesem Zeitpunkt lief.
     * Bei festem Takt gerechnet wäre genau diese Sekunde unsichtbar.
     *
     * Die letzte Stichprobe hat keine nächste; sie bekommt den mittleren
     * Abstand. Ebenso jede, deren Abstand nicht brauchbar ist — Zeitpunkte
     * kommen doppelt vor, und manche SDKs melden sie unsortiert.
     *
     * @param  list<array{int, int}>  $samples
     * @return list<array{int, int}>
     */
    private static function weights(array $samples): array
    {
        usort($samples, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        $count = count($samples);
        $gaps = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $gaps[] = $samples[$i + 1][1] - $samples[$i][1];
        }

        $typical = self::median(array_values(array_filter($gaps, static fn (int $gap): bool => $gap > 0)));

        $weighted = [];

        foreach ($samples as $i => [$stackId, $elapsed]) {
            $gap = $gaps[$i] ?? 0;
            $weighted[] = [$stackId, $gap > 0 ? $gap : $typical];
        }

        return $weighted;
    }

    /**
     * @param  list<int>  $values
     */
    private static function median(array $values): int
    {
        if ($values === []) {
            return self::FALLBACK_INTERVAL_NS;
        }

        sort($values);

        // Der Median und nicht der Mittelwert: eine einzelne Pause von zehn
        // Sekunden würde den Mittelwert so weit hochziehen, dass die letzte
        // Stichprobe mehr Zeit zugeschrieben bekäme als das halbe Profil.
        return $values[intdiv(count($values), 2)];
    }

    /**
     * Ein Zeitpunkt in Nanosekunden seit Beginn der Messung.
     *
     * Auch als Text: die Zahl übersteigt bei längeren Aufrufen den Bereich, in
     * dem JavaScript ganze Zahlen genau darstellt, und die Browser-SDKs schicken
     * sie deshalb als Zeichenkette.
     */
    private static function nanoseconds(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (is_float($value) && is_finite($value) && $value >= 0) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * Die Kennung eines Ausführungsstrangs, vereinheitlicht.
     *
     * Zahl oder Text, je nach SDK — abgelegt wird Text. Ohne diese
     * Vereinheitlichung wäre der Strang `1` aus der Stichprobe ein anderer als
     * der Strang `"1"` aus `active_thread_id`, und das Profil zeigte den
     * falschen.
     */
    private static function threadId(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return PayloadReader::text($value, 64);
    }
}
