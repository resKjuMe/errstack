<?php

namespace App\Support\Replays;

use App\Models\Replay;
use App\Models\ReplaySegment;
use App\Support\Performance\PayloadReader;
use Carbon\CarbonImmutable;

/**
 * Die Spuren neben dem Film: Klicks, Seitenwechsel, Konsole, Netzwerk.
 *
 * Ohne sie wäre eine Aufzeichnung ein Stummfilm. Man sähe den Nutzer auf eine
 * Schaltfläche klicken und danach eine Fehlerseite — was dazwischen an den
 * Server ging und was in der Konsole stand, bliebe unsichtbar. Genau das ist
 * aber die Auskunft, wegen der man sich eine Aufzeichnung ansieht.
 *
 * **Die Deutung passiert hier und nicht im Browser.** Das rrweb-Format ist eine
 * Verabredung zwischen SDK und Abspieler, und die Spuren daraus zu lesen ist
 * Auslegung: welcher Eintrag eine Netzwerkanfrage ist, was als Konsolenzeile
 * gilt, wie ein Klick beschriftet wird. Solche Auslegung gehört auf die Seite,
 * die sich prüfen lässt — im Browser wäre sie unbeobachtet.
 *
 * Bezugspunkt ist der Beginn der Aufzeichnung: jeder Eintrag trägt seinen
 * Abstand dazu in Millisekunden. Die Zeitleiste rechnet damit ohne Zeitzone und
 * ohne Uhr, und ein Sprung zu einer Marke ist derselbe Wert, den der Abspieler
 * ohnehin verlangt.
 */
final class ReplayTimeline
{
    /**
     * rrweb-Ereignisart „eigenes Ereignis". Alles, was nicht Bildinhalt ist —
     * und damit alles, was hier gelesen wird — kommt als solches.
     */
    private const TYPE_CUSTOM = 5;

    /**
     * rrweb-Ereignisart „Kopfdaten": Adresse und Fenstergröße. Der Seitenwechsel
     * steht hier und nicht in einer Spur des SDK.
     */
    private const TYPE_META = 4;

    /**
     * Wie viele Einträge eine Spur höchstens trägt.
     *
     * Die Grenze ist keine Sparmaßnahme, sondern eine Aussage über Lesbarkeit:
     * eine Sitzung an einer Seite mit Umfrage-Widget meldet zehntausend
     * Netzwerkanfragen, und eine Liste mit zehntausend Zeilen beantwortet keine
     * Frage mehr. Was darüber hinausgeht, wird gezählt und dazugesagt — eine
     * Liste, die vollständig aussieht, ohne es zu sein, liest sich wie eine
     * Zusage.
     */
    public const MAX_ENTRIES = 500;

    /** @var list<array<string, mixed>> */
    private array $breadcrumbs = [];

    /** @var list<array<string, mixed>> */
    private array $console = [];

    /** @var list<array<string, mixed>> */
    private array $network = [];

    /** @var array<string, int> */
    private array $truncated = ['breadcrumbs' => 0, 'console' => 0, 'network' => 0];

    /**
     * @param  int  $startMs  Der Beginn der Aufzeichnung, in Millisekunden seit 1970.
     */
    public function __construct(
        private readonly int $startMs,
    ) {}

    /**
     * Nimmt die Ereignisse eines Abschnitts auf.
     *
     * Abschnittsweise und nicht alles auf einmal, weil der Aufrufer die
     * Abschnitte ohnehin einzeln liest: eine Sitzung von zehn Minuten sind
     * einige zehntausend Ereignisse, und sie gleichzeitig im Speicher zu halten
     * wäre die Stelle, an der eine Abspielseite umfällt. So wandert immer nur
     * ein Abschnitt durch.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function consume(array $events, ReplaySegment $segment): void
    {
        foreach ($events as $event) {
            $this->read($event, $segment);
        }
    }

    /**
     * Die fertigen Spuren.
     *
     * @return array{breadcrumbs: list<array<string, mixed>>, console: list<array<string, mixed>>, network: list<array<string, mixed>>, truncated: array<string, int>}
     */
    public function result(): array
    {
        return [
            'breadcrumbs' => $this->sorted($this->breadcrumbs),
            'console' => $this->sorted($this->console),
            'network' => $this->sorted($this->network),
            'truncated' => array_filter($this->truncated),
        ];
    }

    /**
     * Die Spuren einer ganzen Aufzeichnung — der bequeme Weg für alles, was die
     * Abschnitte nicht ohnehin schon durchläuft.
     *
     * @return array{breadcrumbs: list<array<string, mixed>>, console: list<array<string, mixed>>, network: list<array<string, mixed>>, truncated: array<string, int>}
     */
    public static function forReplay(Replay $replay, ReplayStore $store): array
    {
        $timeline = new self($replay->started_at->getTimestampMs());

        foreach ($replay->segments as $segment) {
            $timeline->consume($store->segmentEvents($segment), $segment);
        }

        return $timeline->result();
    }

    /**
     * Hat das SDK in seinen mitgesendeten Einstellungen gemeldet, dass es
     * maskiert?
     *
     * Das Sentry-Replay legt seine Einstellungen als eigenes Ereignis in den
     * ersten Abschnitt — und darin steht die Antwort auf die einzige Frage, die
     * diese Anwendung zum Datenschutz einer Aufzeichnung überhaupt selbst
     * beantworten kann: **wurde im Browser maskiert?** Sie ist damit die
     * verlässlichste Quelle, die es gibt, und deutlich besser als die
     * Kopfdaten, in denen die Angabe gar nicht vorgesehen ist.
     *
     * `null`, wenn das Ereignis fehlt (ältere SDKs). Dann bleibt es bei der
     * Vorgabe — siehe {@see ReplayMetadata}.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public static function maskingFrom(array $events): ?bool
    {
        foreach ($events as $event) {
            $payload = self::customPayload($event, 'options');

            if ($payload === null) {
                continue;
            }

            $text = $payload['maskAllText'] ?? null;
            $inputs = $payload['maskAllInputs'] ?? null;

            if ($text === null && $inputs === null) {
                continue;
            }

            // Beides muss zutreffen. Maskierte Texte bei unmaskierten
            // Eingabefeldern sind kein halber Schutz — das Eingabefeld ist
            // gerade die Stelle, an der ein Kennwort steht.
            return ($text === null || self::bool($text)) && ($inputs === null || self::bool($inputs));
        }

        return null;
    }

    /**
     * Liest ein einzelnes rrweb-Ereignis in die passende Spur.
     *
     * @param  array<string, mixed>  $event
     */
    private function read(array $event, ReplaySegment $segment): void
    {
        $type = $event['type'] ?? null;
        $at = self::offset($event['timestamp'] ?? null, $this->startMs)
            ?? $segment->started_at->getTimestampMs() - $this->startMs;

        if ($type === self::TYPE_META) {
            $this->add($this->breadcrumbs, 'breadcrumbs', $this->navigation($event, $at));

            return;
        }

        if ($type !== self::TYPE_CUSTOM) {
            return;
        }

        $crumb = self::customPayload($event, 'breadcrumb');

        if ($crumb !== null) {
            $entry = $this->breadcrumb($crumb, $at);

            // Konsolenzeilen sind Breadcrumbs und stehen trotzdem in einer
            // eigenen Spur: „was hat der Nutzer getan" und „was hat die
            // Anwendung gemeldet" sind zwei Fragen, und in einer gemeinsamen
            // Liste verdeckt die zweite die erste — eine Anwendung, die
            // fleißig protokolliert, drängt jeden Klick heraus.
            if (($entry['category'] ?? null) === 'console') {
                $this->add($this->console, 'console', $entry);
            } else {
                $this->add($this->breadcrumbs, 'breadcrumbs', $entry);
            }

            return;
        }

        $span = self::customPayload($event, 'performanceSpan');

        if ($span !== null) {
            $this->add($this->network, 'network', $this->span($span, $at));
        }
    }

    /**
     * Ein Seitenwechsel, aus den Kopfdaten gelesen.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function navigation(array $event, int $at): array
    {
        $data = PayloadReader::map($event['data'] ?? null) ?? [];

        return [
            'offsetMs' => $at,
            'category' => 'navigation',
            'message' => PayloadReader::text($data['href'] ?? null, Replay::URL_LIMIT),
            'level' => null,
        ];
    }

    /**
     * Eine Spur des Nutzers: Klick, Eingabe, Seitenwechsel, Konsolenzeile.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function breadcrumb(array $payload, int $at): array
    {
        $data = PayloadReader::map($payload['data'] ?? null) ?? [];

        // Der Zeitstempel eines Breadcrumbs kommt in Sekunden mit Bruchteilen,
        // während rrweb in Millisekunden zählt — die beiden Einheiten stehen
        // hier unmittelbar nebeneinander. Fehlt er, gilt der des umgebenden
        // Ereignisses; die Abweichung ist dann höchstens die Länge eines
        // Abschnitts.
        $own = PayloadReader::time($payload['timestamp'] ?? null);

        return [
            'offsetMs' => $own !== null ? $own->getTimestampMs() - $this->startMs : $at,
            'category' => PayloadReader::text($payload['category'] ?? null, 64),
            'message' => PayloadReader::text($payload['message'] ?? null, 512),
            'level' => PayloadReader::text($payload['level'] ?? null, 16),
            // Die Argumente einer Konsolenzeile: `console.log('x', {y:1})` ist
            // ohne sie nur „x". Gekürzt, weil ein einziger Aufruf ein ganzes
            // Objekt-Geflecht mitbringen kann.
            'arguments' => $this->arguments($data['arguments'] ?? null),
        ];
    }

    /**
     * Eine Netzwerkanfrage oder eine Messung des Browsers.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function span(array $payload, int $at): array
    {
        $data = PayloadReader::map($payload['data'] ?? null) ?? [];
        $start = PayloadReader::time($payload['startTimestamp'] ?? null);
        $end = PayloadReader::time($payload['endTimestamp'] ?? null);

        return [
            'offsetMs' => $start !== null ? $start->getTimestampMs() - $this->startMs : $at,
            'op' => PayloadReader::text($payload['op'] ?? null, 64),
            'description' => PayloadReader::text($payload['description'] ?? null, Replay::URL_LIMIT),
            'method' => PayloadReader::text($data['method'] ?? null, 16),
            'status' => is_int($data['statusCode'] ?? null) ? $data['statusCode'] : null,
            'size' => is_int($data['size'] ?? null) ? $data['size'] : null,
            'durationMs' => $start !== null && $end !== null
                ? max(0, $end->getTimestampMs() - $start->getTimestampMs())
                : null,
        ];
    }

    /**
     * Die Argumente einer Konsolenzeile, auf lesbare Zeilen gebracht.
     *
     * @return list<string>
     */
    private function arguments(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $arguments = [];

        foreach (array_slice($value, 0, 10) as $argument) {
            $text = is_scalar($argument) || $argument === null
                ? var_export($argument, true)
                : (string) json_encode($argument, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $arguments[] = (string) PayloadReader::text($text, 512);
        }

        return array_values(array_filter($arguments, static fn (string $text): bool => $text !== ''));
    }

    /**
     * Nimmt einen Eintrag in eine Spur auf — oder zählt ihn, wenn sie voll ist.
     *
     * @param  list<array<string, mixed>>  $track
     * @param  array<string, mixed>  $entry
     */
    private function add(array &$track, string $name, array $entry): void
    {
        if (count($track) >= self::MAX_ENTRIES) {
            $this->truncated[$name]++;

            return;
        }

        $track[] = $entry;
    }

    /**
     * Sortiert eine Spur nach dem Abstand zum Beginn.
     *
     * Nötig, obwohl die Abschnitte in Reihenfolge gelesen werden: die
     * Zeitstempel innerhalb eines Abschnitts stammen aus verschiedenen Quellen —
     * der Breadcrumb bringt seinen eigenen mit, der umgebende rrweb-Rahmen einen
     * anderen —, und eine Zeitleiste, die rückwärts springt, ist keine.
     *
     * @param  list<array<string, mixed>>  $track
     * @return list<array<string, mixed>>
     */
    private function sorted(array $track): array
    {
        usort($track, static fn (array $a, array $b): int => ($a['offsetMs'] ?? 0) <=> ($b['offsetMs'] ?? 0));

        return array_values($track);
    }

    /**
     * Der Abstand eines rrweb-Zeitstempels (Millisekunden) zum Beginn.
     */
    private static function offset(mixed $timestamp, int $startMs): ?int
    {
        if (! is_int($timestamp) && ! is_float($timestamp)) {
            return null;
        }

        if (! is_finite((float) $timestamp) || $timestamp <= 0) {
            return null;
        }

        return CarbonImmutable::createFromTimestampMs((int) $timestamp)->getTimestampMs() - $startMs;
    }

    /**
     * Die Nutzdaten eines eigenen rrweb-Ereignisses mit dieser Kennzeichnung.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private static function customPayload(array $event, string $tag): ?array
    {
        if (($event['type'] ?? null) !== self::TYPE_CUSTOM) {
            return null;
        }

        $data = PayloadReader::map($event['data'] ?? null);

        if ($data === null || ($data['tag'] ?? null) !== $tag) {
            return null;
        }

        return PayloadReader::map($data['payload'] ?? null) ?? [];
    }

    private static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
