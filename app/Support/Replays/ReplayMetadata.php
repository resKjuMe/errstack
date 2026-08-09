<?php

namespace App\Support\Replays;

use App\Models\Environment;
use App\Models\IngestPayload;
use App\Models\Replay;
use App\Support\Performance\PayloadReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Die Kopfdaten einer Sitzungs-Aufzeichnung, aus dem gemeldeten Feld-Baum
 * gelesen.
 *
 * Das Element `replay_event` ist das Begleitpapier zur Aufnahme: es sagt, wem
 * die Sitzung gehört, wo sie unterwegs war, wann sie begann und welche Fehler
 * dabei passiert sind. Die Bilder selbst stehen woanders
 * ({@see ReplayRecording}).
 *
 * Gelesen wird nach derselben Regel wie überall in der Aufnahme: **nichts ist
 * zugesichert.** Die Angaben kommen aus einem fremden SDK in einer fremden
 * Version, und ein fehlendes Feld darf keine Aufzeichnung kosten. Fehlt eine
 * Angabe, bleibt sie leer; fehlt die Nummer der Aufzeichnung, gibt es nichts zu
 * lesen und die Meldung wird ausgesondert.
 *
 * Was hier **nicht** passiert: Maskieren. Das ist im Browser schon geschehen
 * oder gar nicht mehr nachzuholen — siehe {@see $masked} und `config/replays.php`.
 */
final class ReplayMetadata
{
    /**
     * @param  string  $replayId  Die Nummer der Sitzung, 32 Hex-Zeichen.
     * @param  list<string>  $urls  Besuchte Seiten, in der Reihenfolge des Besuchs.
     * @param  list<string>  $errorIds  Nummern der Fehler, die in der Sitzung passierten.
     * @param  array<string, mixed>|null  $user  Wer betroffen war, nach dem Schwärzen.
     * @param  bool  $masked  Hat das SDK die Maskierung als aktiv gemeldet?
     */
    private function __construct(
        public readonly string $replayId,
        public readonly ?CarbonImmutable $startedAt,
        public readonly ?CarbonImmutable $timestamp,
        public readonly ?string $environment,
        public readonly ?string $release,
        public readonly ?string $dist,
        public readonly ?string $platform,
        public readonly ?string $sdk,
        public readonly array $urls,
        public readonly array $errorIds,
        public readonly ?array $user,
        public readonly ?string $browser,
        public readonly ?string $os,
        public readonly ?string $device,
        public readonly bool $masked,
    ) {}

    /**
     * Liest die Kopfdaten. `null`, wenn sich keine Sitzungsnummer finden lässt —
     * dann gehört die Meldung zu keiner Aufzeichnung, und eine anzulegen hieße,
     * eine zu erfinden.
     *
     * Die Nummer steht bei den meisten SDKs unter `replay_id`; ältere schreiben
     * sie nur in die `event_id` des Elements. Beide werden gelesen, und zwar in
     * dieser Reihenfolge: `replay_id` ist die ausdrückliche Angabe, `event_id`
     * die Notlösung.
     *
     * @param  array<mixed>  $data
     */
    public static function fromPayload(array $data, ?string $fallbackId = null): ?self
    {
        $replayId = PayloadReader::hex($data['replay_id'] ?? null, 32)
            ?? PayloadReader::hex($data['event_id'] ?? null, 32)
            ?? PayloadReader::hex($fallbackId, 32);

        if ($replayId === null) {
            return null;
        }

        $contexts = PayloadReader::map($data['contexts'] ?? null) ?? [];

        return new self(
            replayId: $replayId,
            // Der Beginn der **Aufnahme**, nicht der Zeitpunkt dieser Meldung.
            // Beide stehen im Rumpf und liegen bei einer laufenden Sitzung
            // Minuten auseinander: die Kopfdaten werden mit jedem Abschnitt neu
            // geschickt, der Beginn bleibt derselbe.
            startedAt: PayloadReader::time($data['replay_start_timestamp'] ?? null),
            timestamp: PayloadReader::time($data['timestamp'] ?? null),
            environment: Environment::normalizeName(self::string($data['environment'] ?? null)),
            release: PayloadReader::text($data['release'] ?? null, 255),
            dist: PayloadReader::text($data['dist'] ?? null, 64),
            platform: PayloadReader::text($data['platform'] ?? null, 32),
            sdk: self::sdk($data['sdk'] ?? null),
            urls: self::urls($data),
            errorIds: self::eventIds($data['error_ids'] ?? null),
            user: PayloadReader::map($data['user'] ?? null),
            browser: self::describe($contexts['browser'] ?? null),
            os: self::describe($contexts['os'] ?? null),
            device: self::describe($contexts['device'] ?? null, 'model'),
            masked: self::masked($data, $contexts),
        );
    }

    /**
     * Die Werte, mit denen eine neue Aufzeichnung angelegt wird.
     *
     * Zusammen mit {@see applyTo()} ist das die einzige Stelle, die weiß, welches
     * gemeldete Feld in welche Spalte gehört — der Schritt, der sie ablegt, muss
     * es nicht wissen.
     *
     * @return array<string, mixed>
     */
    public function attributes(CarbonImmutable $fallbackTime): array
    {
        $startedAt = $this->startedAt ?? $this->timestamp ?? $fallbackTime;

        return [
            'environment' => $this->environment ?? 'production',
            'started_at' => $startedAt,
            'last_segment_at' => $this->timestamp ?? $startedAt,
        ];
    }

    /**
     * Trägt die gelesenen Angaben in eine vorhandene Aufzeichnung ein.
     *
     * **Nur was da ist, wird gesetzt.** Die Kopfdaten kommen mit jedem Abschnitt
     * erneut, und eine spätere Meldung, in der ein Feld fehlt, ist keine Aussage
     * darüber, dass es den Wert nicht mehr gibt — sie ist ein SDK, das es diesmal
     * nicht mitgeschickt hat. Würde hier stumpf überschrieben, verlöre eine
     * Sitzung im Lauf ihrer Aufnahme Angaben, die sie am Anfang hatte.
     *
     * Der Beginn wandert dabei nur nach **vorn**: Abschnitte überholen einander,
     * und ein spät eintreffender erster Abschnitt darf den Anfang nicht nach
     * hinten schieben.
     */
    public function applyTo(Replay $replay): void
    {
        $started = $this->startedAt ?? $this->timestamp;

        if ($started !== null && $started->lessThan($replay->started_at)) {
            $replay->started_at = $started;
        }

        $replay->environment = $this->environment ?? $replay->environment;
        $replay->release = $this->release ?? $replay->release;
        $replay->dist = $this->dist ?? $replay->dist;
        $replay->platform = $this->platform ?? $replay->platform;
        $replay->sdk = $this->sdk ?? $replay->sdk;
        $replay->user = $this->user ?? $replay->user;
        $replay->browser = $this->browser ?? $replay->browser;
        $replay->os = $this->os ?? $replay->os;
        $replay->device = $this->device ?? $replay->device;

        // Die Maskierung ist die eine Angabe, die **nicht** nur nach oben
        // wandert: hat ein SDK sie einmal als abgeschaltet gemeldet, bleibt die
        // Sitzung als unmaskiert gekennzeichnet. Eine spätere Meldung ohne die
        // Angabe darf den Hinweis nicht wieder wegnehmen — das wäre eine
        // Warnung, die sich selbst abschaltet.
        $replay->masked = $replay->masked && $this->masked;

        if ($this->urls !== []) {
            $replay->urls = self::mergeUrls($replay->urls ?? [], $this->urls);
            $replay->url ??= $this->urls[0];
        }
    }

    /**
     * Führt die besuchten Seiten zusammen, ohne die Reihenfolge zu verlieren.
     *
     * Doppelte fallen weg — eine Einzelseiten-Anwendung meldet dieselbe Adresse
     * in jedem Abschnitt erneut, und eine Liste aus hundertmal derselben Zeile
     * beantwortet keine Frage.
     *
     * @param  list<string>  $existing
     * @param  list<string>  $incoming
     * @return list<string>
     */
    private static function mergeUrls(array $existing, array $incoming): array
    {
        $merged = array_values(array_unique([...$existing, ...$incoming]));

        return array_slice($merged, 0, Replay::MAX_URLS);
    }

    /**
     * Die besuchten Seiten.
     *
     * Zwei Quellen, weil die SDKs sich uneinig sind: die neueren führen `urls`
     * als Liste, die älteren schreiben die Seite in `request.url`. Wer nur eine
     * liest, hat bei der Hälfte der Absender eine Liste ohne Adressen.
     *
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private static function urls(array $data): array
    {
        $urls = [];

        foreach ((array) ($data['urls'] ?? []) as $url) {
            $text = PayloadReader::text($url, Replay::URL_LIMIT);

            if ($text !== null) {
                $urls[] = $text;
            }
        }

        $request = PayloadReader::map($data['request'] ?? null);
        $requestUrl = PayloadReader::text($request['url'] ?? null, Replay::URL_LIMIT);

        if ($requestUrl !== null && ! in_array($requestUrl, $urls, true)) {
            $urls[] = $requestUrl;
        }

        return array_slice(array_values(array_unique($urls)), 0, Replay::MAX_URLS);
    }

    /**
     * Die Nummern der Fehler, die während der Sitzung passiert sind.
     *
     * Vereinheitlicht wie jede Ereignis-Nummer ({@see IngestPayload::normalizeEventId()}):
     * dieselbe Nummer mit und ohne Bindestriche wäre für die Verknüpfung zwei
     * verschiedene, und der Fehler fände seine Aufzeichnung nicht.
     *
     * @return list<string>
     */
    private static function eventIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $entry) {
            $id = IngestPayload::normalizeEventId($entry);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Name und Fassung eines Kontexts als eine Zeile — „Chrome 120".
     *
     * Zusammengesetzt und nicht als zwei Spalten: gefragt wird danach nie
     * einzeln, gezeigt wird es immer zusammen, und zwei Spalten wären zwei
     * Stellen, an denen dieselbe Anzeige zusammengebaut werden müsste.
     */
    private static function describe(mixed $context, string $nameField = 'name'): ?string
    {
        $context = PayloadReader::map($context);

        if ($context === null) {
            return null;
        }

        $name = PayloadReader::text($context[$nameField] ?? null, 96);

        if ($name === null) {
            return null;
        }

        $version = PayloadReader::text($context['version'] ?? null, 24);

        return PayloadReader::text($version === null ? $name : $name.' '.$version, 128);
    }

    /**
     * Name und Fassung des SDK — „sentry.javascript.browser 8.42.0".
     */
    private static function sdk(mixed $sdk): ?string
    {
        return self::describe($sdk);
    }

    /**
     * Hat das SDK gemeldet, dass es maskiert?
     *
     * Die Angabe ist **nicht** Teil des Sentry-Protokolls, und deshalb ist die
     * Vorgabe hier `true`: die Maskierung ist im SDK eingeschaltet, solange
     * niemand sie ausschaltet, und ältere Fassungen sagen dazu nichts. Wer aus
     * dem Schweigen „unmaskiert" machte, würde jede Aufzeichnung eines älteren
     * SDK mit einer Warnung versehen — und eine Warnung, die immer leuchtet,
     * wird nicht gelesen.
     *
     * Gelesen werden die beiden Stellen, an denen SDKs und eigene
     * Integrationen die Angabe unterbringen: der Replay-Kontext und die Marken.
     * Ausdrücklich gemeldetes „nein" wird übernommen — das ist der Fall, für den
     * die Anzeige da ist.
     *
     * @param  array<mixed>  $data
     * @param  array<mixed>  $contexts
     */
    private static function masked(array $data, array $contexts): bool
    {
        $replayContext = PayloadReader::map($contexts['replay'] ?? null) ?? [];
        $tags = PayloadReader::map($data['tags'] ?? null) ?? [];

        foreach ([$replayContext['masked'] ?? null, $tags['replayMasked'] ?? null] as $value) {
            if ($value === null) {
                continue;
            }

            return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        }

        return true;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? Str::limit(trim($value), 255, '') : null;
    }
}
