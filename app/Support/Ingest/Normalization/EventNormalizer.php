<?php

namespace App\Support\Ingest\Normalization;

use App\Enums\EventLevel;
use App\Support\Ingest\Normalization\Sections\Breadcrumbs;
use App\Support\Ingest\Normalization\Sections\Contexts;
use App\Support\Ingest\Normalization\Sections\Exceptions;
use App\Support\Ingest\Normalization\Sections\Frames;
use App\Support\Ingest\Normalization\Sections\Message;
use App\Support\Ingest\Normalization\Sections\Request;
use App\Support\Ingest\Normalization\Sections\Sdk;
use App\Support\Ingest\Normalization\Sections\Threads;
use App\Support\Ingest\Normalization\Sections\User;
use Illuminate\Support\Carbon;

/**
 * Macht aus dem, was ein SDK geschickt hat, einen Datensatz, auf den man sich
 * verlassen kann.
 *
 * Der Eingang ist ein loses Versprechen: das Sentry-Schema lässt für fast jedes
 * Feld mehrere Schreibweisen zu, die SDKs nutzen unterschiedliche davon, ältere
 * Fassungen schicken Felder, die es nicht mehr gibt, und eine Anwendung im
 * Absturz füllt manches gar nicht. Hier endet das: danach steht in jedem Feld
 * ein geprüfter Wert oder `null`.
 *
 * Drei Zusagen gibt diese Klasse:
 *
 * 1. **Nichts geht verloren, was zu retten war.** Unbekannte Felder werden
 *    unter `unknown` bewahrt statt weggeworfen — ein SDK, das etwas Neues
 *    schickt, soll seine Angabe wiederfinden, auch bevor wir sie kennen.
 * 2. **Ein kaputter Abschnitt kostet nur diesen Abschnitt.** Eine Anfrage, die
 *    als Zeichenkette statt als Objekt kam, wird verworfen und vermerkt; der
 *    Stacktrace daneben bleibt. Alles andere hieße: je unbrauchbarer die
 *    Anwendung, desto weniger erfahren wir über sie.
 * 3. **Was gekürzt wurde, steht dabei.** Siehe {@see Notes}.
 *
 * Was hier **nicht** passiert: nichts wird entfernt, um es zu verbergen. Das
 * Scrubbing personenbezogener Daten ist ein eigener Schritt (I7) und steht in
 * der Kette **vor** diesem — die Normalisierung findet dann schon vor, was
 * bleiben darf.
 */
final class EventNormalizer
{
    /**
     * Die Felder des Sentry-Schemas, die eigene Fächer haben.
     *
     * Alles, was hier nicht steht, landet unter `unknown`. Die Liste ist
     * deshalb bewusst großzügig: auch Felder, die wir (noch) nicht auswerten,
     * stehen darin, damit `unknown` das bleibt, was der Name sagt — Neues,
     * nicht bloß Unbenutztes.
     *
     * @var list<string>
     */
    private const KNOWN_FIELDS = [
        'event_id', 'timestamp', 'received', 'start_timestamp', 'level', 'platform',
        'logger', 'transaction', 'transaction_info', 'culprit', 'server_name',
        'release', 'dist', 'environment', 'title',
        'message', 'logentry', 'exception', 'stacktrace', 'threads',
        'request', 'user', 'contexts', 'breadcrumbs', 'tags', 'extra',
        'sdk', 'modules', 'fingerprint', 'type', 'errors', 'debug_meta',
        'key_id', 'project', 'version',
    ];

    public function __construct(
        private readonly Sanitizer $sanitizer,
        private readonly Timestamps $timestamps,
        private readonly Frames $frames,
        private readonly Exceptions $exceptions,
        private readonly Threads $threads,
        private readonly Message $message,
        private readonly Request $request,
        private readonly User $user,
        private readonly Contexts $contexts,
        private readonly Breadcrumbs $breadcrumbs,
        private readonly Sdk $sdk,
    ) {}

    /**
     * Baut den Normalisierer samt seiner Abschnitte.
     *
     * Von Hand und nicht über den Dienstbehälter, weil {@see Notes} je Meldung
     * neu sein muss: die Notizen gehören zu **dieser** Meldung, und ein
     * gemeinsamer Gegenstand über mehrere Durchläufe hinweg würde die Kürzungen
     * der einen Meldung an die nächste hängen. Der Behälter kann das nicht
     * wissen — also entsteht die Kette hier, einmal je Meldung.
     */
    public static function make(?Limits $limits = null): self
    {
        $sanitizer = new Sanitizer($limits ?? Limits::fromConfig(), new Notes);
        $timestamps = new Timestamps;
        $frames = new Frames($sanitizer);

        return new self(
            $sanitizer,
            $timestamps,
            $frames,
            new Exceptions($sanitizer, $frames),
            new Threads($sanitizer, $frames),
            new Message($sanitizer),
            new Request($sanitizer),
            new User($sanitizer),
            new Contexts($sanitizer),
            new Breadcrumbs($sanitizer, $timestamps),
            new Sdk($sanitizer),
        );
    }

    /**
     * @param  array<mixed>  $data  Der Rumpf der Meldung als Feld-Baum.
     * @param  string  $eventId  Die vereinheitlichte Nummer aus der Eingangsablage.
     * @param  Carbon|null  $receivedAt  Wann die Meldung angenommen wurde — der
     *                                   Vorgabewert für einen fehlenden Zeitpunkt.
     */
    public function normalize(array $data, string $eventId, ?Carbon $receivedAt = null): NormalizedEvent
    {
        $notes = $this->sanitizer->notes();
        $receivedAt ??= Carbon::now();

        // Eine Meldung, deren Rumpf eine Liste statt eines Objekts ist, hat
        // keine Felder. Weiterzuarbeiten hieße, jeden Abschnitt einzeln gegen
        // dieselbe Ursache scheitern zu lassen — also einmal vermerken und mit
        // einem leeren Feld-Baum weiter. Was bleibt, ist eine Meldung mit
        // Nummer und Zeitpunkt; verworfen wird sie nicht.
        if (array_is_list($data) && $data !== []) {
            $notes->invalid('');

            $data = [];
        }

        /** @var array<string, mixed> $data */
        $exceptions = $this->exceptions->normalize($data['exception'] ?? null, 'exception');

        // Ein Stacktrace kann auch **neben** der Ausnahme stehen — bei einer
        // Nachricht ohne Ausnahme ist das die einzige Stelle, an der er
        // vorkommt. Gibt es beides, gilt der an der Ausnahme: er gehört zu
        // ihr, der andere ist die Aufnahmestelle im SDK.
        if ($exceptions === [] || ! isset($exceptions[array_key_last($exceptions)]['frames'])) {
            $loose = $this->frames->normalize($data['stacktrace'] ?? null, 'stacktrace');

            if ($loose !== []) {
                $exceptions = $this->attachFrames($exceptions, $loose);
            }
        }

        $message = $this->message->normalize($data['message'] ?? null, $data['logentry'] ?? null, '');

        $sdk = $this->sdk->normalize($data['sdk'] ?? null, 'sdk');

        return new NormalizedEvent(
            eventId: $eventId,
            level: EventLevel::normalize($data['level'] ?? null),
            platform: $this->platform($data['platform'] ?? null),
            timestamp: $this->timestamps->required($data['timestamp'] ?? null, 'timestamp', $notes, $receivedAt),
            title: $this->title($exceptions, $message),
            culprit: $this->culprit($data, $exceptions),
            transaction: $this->sanitizer->text($data['transaction'] ?? null, 'transaction', 400),
            logger: $this->sanitizer->text($data['logger'] ?? null, 'logger', 200),
            environment: $this->sanitizer->text($data['environment'] ?? null, 'environment', 200),
            release: $this->sanitizer->text($data['release'] ?? null, 'release', 200),
            dist: $this->sanitizer->text($data['dist'] ?? null, 'dist', 200),
            serverName: $this->sanitizer->text($data['server_name'] ?? null, 'server_name', 200),
            message: $message,
            exceptions: $exceptions,
            threads: $this->threads->normalize($data['threads'] ?? null, 'threads'),
            request: $this->request->normalize($data['request'] ?? null, 'request'),
            user: $this->user->normalize($data['user'] ?? null, 'user'),
            contexts: $this->contexts->normalize($data['contexts'] ?? null, 'contexts'),
            breadcrumbs: $this->breadcrumbs->normalize($data['breadcrumbs'] ?? null, 'breadcrumbs', $notes),
            tags: $this->tags($data['tags'] ?? null),
            extra: $this->extra($data['extra'] ?? null),
            sdk: $sdk,
            modules: $this->sanitizer->entries($data['modules'] ?? null, 'modules'),
            unknown: $this->unknown($data),
            notes: $notes->toArray(),
        );
    }

    /**
     * Die Plattform, kleingeschrieben und auf die erlaubte Länge gekürzt.
     *
     * Keine Aufzählung, sondern eine Zeichenkette — die Begründung steht an
     * {@see NormalizedEvent::PLATFORM_FALLBACK}.
     */
    private function platform(mixed $platform): string
    {
        $value = $this->sanitizer->text($platform, 'platform', 64);

        if ($value === null) {
            return NormalizedEvent::PLATFORM_FALLBACK;
        }

        return strtolower($value);
    }

    /**
     * Die Marken.
     *
     * Sie kommen als Objekt und als Liste von Paaren — Letzteres, weil dieselbe
     * Marke mehrfach vorkommen darf. Vereinheitlicht wird auf das Objekt; bei
     * doppelten Namen gewinnt der letzte Wert, wie es Sentry auch tut.
     *
     * @return array<string, string>
     */
    private function tags(mixed $tags): array
    {
        if (is_array($tags) && array_is_list($tags)) {
            $pairs = [];

            foreach ($tags as $pair) {
                if (is_array($pair) && array_is_list($pair) && count($pair) === 2 && is_scalar($pair[0])) {
                    $pairs[(string) $pair[0]] = $pair[1];
                }
            }

            $tags = $pairs;
        }

        return $this->sanitizer->entries($tags, 'tags');
    }

    /**
     * Das Beiwerk: was die Anwendung für nützlich hielt.
     *
     * @return array<string, mixed>|null
     */
    private function extra(mixed $extra): ?array
    {
        $map = $this->sanitizer->map($extra, 'extra');

        if ($map === null) {
            return null;
        }

        $normalized = $this->sanitizer->freeform($map, 'extra');

        /** @var array<string, mixed>|null */
        return is_array($normalized) && $normalized !== [] ? $normalized : null;
    }

    /**
     * Die Felder, die wir nicht kennen.
     *
     * Sie werden nicht bloß geduldet, sondern ausdrücklich aufgehoben: Sentry
     * erweitert sein Schema laufend, und ein Feld, das wir heute wegwerfen,
     * fehlt rückwirkend, wenn wir es morgen auswerten wollen. Die Rohdaten
     * lägen zwar noch da — aber ein erneuter Durchlauf über Monate alter
     * Meldungen ist eine ganz andere Aufgabe als eine neue Spalte zu lesen.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function unknown(array $data): ?array
    {
        $unknown = [];

        foreach ($data as $key => $value) {
            if (! in_array($key, self::KNOWN_FIELDS, true)) {
                $unknown[$key] = $value;
            }
        }

        if ($unknown === []) {
            return null;
        }

        $normalized = $this->sanitizer->freeform($unknown, 'unknown');

        /** @var array<string, mixed>|null */
        return is_array($normalized) && $normalized !== [] ? $normalized : null;
    }

    /**
     * Die Überschrift der Meldung.
     *
     * Die zuletzt geworfene Ausnahme gibt sie ab, sonst der Meldungstext. Sie
     * wird hier gebildet und nicht bei der Anzeige, weil sie auch in
     * Benachrichtigungen, im Betreff einer E-Mail und in der Suche gebraucht
     * wird — dreimal dieselbe Ableitung wäre dreimal die Gelegenheit, sie
     * unterschiedlich zu machen.
     *
     * @param  list<array<string, mixed>>  $exceptions
     * @param  array<string, mixed>|null  $message
     */
    private function title(array $exceptions, ?array $message): ?string
    {
        if ($exceptions !== []) {
            $last = $exceptions[array_key_last($exceptions)];

            $type = is_string($last['type'] ?? null) ? $last['type'] : null;
            $value = is_string($last['value'] ?? null) ? $last['value'] : null;

            if ($type !== null && $value !== null) {
                return mb_substr($type.': '.$value, 0, 500);
            }

            if ($type !== null || $value !== null) {
                return mb_substr((string) ($type ?? $value), 0, 500);
            }
        }

        $formatted = $message['formatted'] ?? $message['template'] ?? null;

        return is_string($formatted) ? mb_substr($formatted, 0, 500) : null;
    }

    /**
     * Wo der Fehler herkam, in einer Zeile.
     *
     * Vorrang hat, was die Anwendung selbst angibt (`culprit`, sonst
     * `transaction`) — sie weiß es besser als jede Ableitung. Fehlt beides,
     * wird der unterste Rahmen des eigenen Codes genommen: bei einer Ausnahme
     * aus zweihundert Rahmen ist der Rahmenwerks-Code darüber für niemanden
     * die Antwort auf „wo".
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $exceptions
     */
    private function culprit(array $data, array $exceptions): ?string
    {
        $explicit = $this->sanitizer->text($data['culprit'] ?? null, 'culprit', 400)
            ?? $this->sanitizer->text($data['transaction'] ?? null, 'transaction', 400);

        if ($explicit !== null) {
            return $explicit;
        }

        $frame = $this->culpritFrame($exceptions);

        if ($frame === null) {
            return null;
        }

        $where = $frame['filename'] ?? $frame['abs_path'] ?? $frame['module'] ?? null;
        $what = $frame['function'] ?? null;

        if (is_string($where) && is_string($what)) {
            return mb_substr($what.' ('.$where.')', 0, 400);
        }

        $single = $what ?? $where;

        return is_string($single) ? mb_substr($single, 0, 400) : null;
    }

    /**
     * Der Rahmen, der die Meldung am besten verortet: der letzte aus eigenem
     * Code, ersatzweise der letzte überhaupt.
     *
     * @param  list<array<string, mixed>>  $exceptions
     * @return array<string, mixed>|null
     */
    private function culpritFrame(array $exceptions): ?array
    {
        if ($exceptions === []) {
            return null;
        }

        $frames = $exceptions[array_key_last($exceptions)]['frames'] ?? null;

        if (! is_array($frames) || $frames === []) {
            return null;
        }

        foreach (array_reverse($frames) as $frame) {
            if (is_array($frame) && ($frame['in_app'] ?? null) === true) {
                /** @var array<string, mixed> $frame */
                return $frame;
            }
        }

        $last = $frames[array_key_last($frames)];

        /** @var array<string, mixed>|null */
        return is_array($last) ? $last : null;
    }

    /**
     * Hängt einen frei stehenden Stacktrace an die Ausnahme, zu der er gehört.
     *
     * Gibt es keine Ausnahme, entsteht ein Eintrag nur für den Stacktrace: eine
     * Nachricht mit Stacktrace ist keine Ausnahme, aber ihre Rahmen gehören an
     * dieselbe Stelle wie die einer Ausnahme — sonst müsste jede spätere
     * Auswertung an zwei Orten nachsehen.
     *
     * @param  list<array<string, mixed>>  $exceptions
     * @param  list<array<string, mixed>>  $frames
     * @return list<array<string, mixed>>
     */
    private function attachFrames(array $exceptions, array $frames): array
    {
        if ($exceptions === []) {
            return [['frames' => $frames]];
        }

        $last = array_key_last($exceptions);
        $exceptions[$last]['frames'] = $frames;

        return $exceptions;
    }
}
