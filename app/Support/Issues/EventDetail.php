<?php

namespace App\Support\Issues;

use App\Enums\SymbolicationDiagnosis;
use App\Http\Controllers\IssueDetailController;
use App\Models\Event;
use App\Models\EventSymbolication;
use App\Support\Formats;
use App\Support\Translations;
use Illuminate\Support\Carbon;

/**
 * Eine einzelne Meldung, fertig für die Detailseite.
 *
 * Die Klasse rechnet nichts aus, sie ordnet: aus dem ausgewerteten Datensatz
 * ({@see Event}) wird die Reihenfolge, in der jemand einen Fehler liest —
 * Ausnahme und Ursachen, Stacktrace, letzte Schritte, Anfrage, Nutzer, Umgebung.
 *
 * **Zwei Reihenfolgen werden gedreht, und beide bewusst.** Gespeichert liegen
 * Ursachenkette und Stacktrace so, wie die SDKs sie schicken: die Ursachenkette
 * von der ältesten Ursache an, der Stacktrace vom äußersten Aufruf an. Gelesen
 * wird beides andersherum — zuerst die Ausnahme, die die Anwendung gesehen hat,
 * und zuerst die Stelle, an der es geknallt hat. Gedreht wird deshalb hier, in
 * der Anzeige, und nicht in den Daten: an der gespeicherten Reihenfolge hängt
 * die Gruppierung (I5), und die Rohansicht soll zeigen, was ankam.
 */
final class EventDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Event $event): array
    {
        return [
            'id' => $event->id,
            'eventId' => $event->event_id,
            'title' => $event->title,
            'culprit' => $event->culprit,
            'transaction' => $event->transaction,
            'logger' => $event->logger,
            'level' => $event->level->value,
            'levelLabel' => $event->level->label(),
            'platform' => $event->platform,
            'occurredAt' => $event->occurred_at->toIso8601String(),
            'occurredAtLabel' => Formats::dateTimeSeconds($event->occurred_at),
            'receivedAt' => $event->received_at->toIso8601String(),
            'receivedAtLabel' => Formats::dateTimeSeconds($event->received_at),
            'message' => self::message($event),
            'exceptions' => self::exceptions($event),
            // Der zurückübersetzte Stacktrace (R5): eine zweite Sicht **neben**
            // der gemeldeten, zwischen der sich umschalten lässt — nicht an ihrer
            // Stelle.
            'symbolication' => self::symbolication($event),
            'breadcrumbs' => self::breadcrumbs($event),
            'request' => $event->request,
            'user' => $event->user,
            'contexts' => self::contexts($event),
            'tags' => self::pairs($event->tags),
            'extra' => $event->extra,
            'modules' => self::pairs($event->modules),
            'sdk' => $event->sdkIdentifier(),
            'environment' => $event->environment,
            'release' => $event->release,
            'dist' => $event->dist,
            'serverName' => $event->server_name,
            // Ein gekürzter Stacktrace sieht aus wie ein kurzer. Was unterwegs
            // wegfiel, gehört deshalb an die Anzeige und nicht nur ins Protokoll.
            'notes' => $event->wasReduced() ? $event->notes : null,
        ];
    }

    /**
     * Der Meldungstext einer Meldung ohne Ausnahme.
     *
     * @return array<string, mixed>|null
     */
    private static function message(Event $event): ?array
    {
        $message = $event->message;

        return is_array($message) && $message !== [] ? $message : null;
    }

    /**
     * Der zurückübersetzte Stacktrace, sofern es einen gibt.
     *
     * **Die Zeile wird nicht nachgeladen, sondern erwartet.** Die Beziehung wird
     * vom Aufrufer mitgeladen ({@see IssueDetailController});
     * hier nachzufragen hieße, eine Abfrage in der Darstellung auszulösen — und
     * zwar genau eine je aufgeschlagener Fehlerseite.
     *
     * `null` heißt „für diese Meldung ist keine Übersetzung vorgesehen". Der
     * Unterschied zu einer vorhandenen Zeile mit `unmapped` ist der zwischen
     * „kommt hier nicht vor" und „wurde versucht" — und nur im zweiten Fall hat
     * die Anzeige etwas zu sagen.
     *
     * @return array<string, mixed>|null
     */
    private static function symbolication(Event $event): ?array
    {
        $record = $event->symbolication;

        if ($record === null) {
            return null;
        }

        $exceptions = is_array($record->exceptions) ? $record->exceptions : [];

        return [
            'status' => $record->status->value,
            'statusLabel' => $record->status->label(),
            'mappedFrames' => $record->mapped_frames,
            'totalFrames' => $record->total_frames,
            // Die Rahmen gehen durch dieselbe Darstellung wie die gemeldeten: sie
            // liegen in derselben Form, und ein zweiter Weg wäre ein zweiter Ort
            // für Fehler.
            'exceptions' => $record->status->hasFrames()
                ? self::presentExceptions($exceptions)
                : [],
            'diagnostics' => self::diagnostics($record),
        ];
    }

    /**
     * Die Gründe, warum Rahmen unübersetzt blieben — mit ihrem Text.
     *
     * Der Text kommt von hier und nicht aus der Oberfläche: übersetzt wird
     * serverseitig, und die Aufzählungs-Texte gehören nicht zu den Gruppen, die
     * React überhaupt bekommt ({@see Translations::GROUPS}). Ein
     * unbekannter Grund — eine Zeile aus einer älteren Fassung — fällt weg statt
     * die Anzeige mit einem Schlüssel zu füllen.
     *
     * @return list<array{reason: string, reasonLabel: string, detail: string|null, count: int}>
     */
    private static function diagnostics(EventSymbolication $record): array
    {
        $presented = [];

        foreach ($record->diagnostics ?? [] as $diagnosis) {
            // `tryFrom` und nicht `from`: in der Zeile kann ein Grund aus einer
            // älteren Fassung stehen. Er fällt weg, statt die Anzeige mit einem
            // Schlüssel zu füllen oder den Aufruf scheitern zu lassen.
            $reason = SymbolicationDiagnosis::tryFrom($diagnosis['reason']);

            if ($reason === null) {
                continue;
            }

            $presented[] = [
                'reason' => $reason->value,
                'reasonLabel' => $reason->label(),
                'detail' => self::text($diagnosis['detail']),
                'count' => $diagnosis['count'],
            ];
        }

        return $presented;
    }

    /**
     * Die Ausnahme und ihre Ursachen — zuletzt Geworfenes zuerst.
     *
     * @return list<array<string, mixed>>
     */
    private static function exceptions(Event $event): array
    {
        return self::presentExceptions($event->exceptions ?? []);
    }

    /**
     * Ausnahmen in Leserichtung — aus den gemeldeten wie aus den
     * zurückübersetzten.
     *
     * Eine Methode für beide, weil beide dieselbe Form haben und dieselbe
     * Drehung brauchen. Zwei Wege hätten zur Folge, dass die zweite Sicht eines
     * Tages anders aussieht als die erste, ohne dass es jemand entschieden hat.
     *
     * @param  list<array<string, mixed>>|array<mixed>  $exceptions
     * @return list<array<string, mixed>>
     */
    private static function presentExceptions(array $exceptions): array
    {
        $presented = [];

        foreach (array_reverse($exceptions) as $index => $exception) {
            if (! is_array($exception)) {
                continue;
            }

            $frames = $exception['frames'] ?? null;

            $presented[] = [
                'type' => self::text($exception['type'] ?? null),
                'value' => self::text($exception['value'] ?? null),
                'module' => self::text($exception['module'] ?? null),
                'mechanism' => is_array($exception['mechanism'] ?? null) ? $exception['mechanism'] : null,
                // Der erste Eintrag ist die Ausnahme, die die Anwendung gesehen
                // hat; jeder weitere hat den vorigen ausgelöst.
                'isCause' => $index > 0,
                'frames' => self::frames(is_array($frames) ? $frames : []),
            ];
        }

        return $presented;
    }

    /**
     * Ein Stacktrace, innerste Stelle zuerst.
     *
     * @param  list<array<string, mixed>>  $frames
     * @return list<array<string, mixed>>
     */
    private static function frames(array $frames): array
    {
        $presented = [];

        foreach (array_reverse($frames) as $frame) {
            $lineno = is_int($frame['lineno'] ?? null) ? $frame['lineno'] : null;

            $presented[] = [
                // Ein Rahmen ohne Datei ist keiner ohne Herkunft: bei
                // kompilierten Sprachen steht dort das Modul oder das Paket.
                'filename' => self::text($frame['filename'] ?? null)
                    ?? self::text($frame['abs_path'] ?? null)
                    ?? self::text($frame['module'] ?? null)
                    ?? self::text($frame['package'] ?? null),
                'absPath' => self::text($frame['abs_path'] ?? null),
                'function' => self::text($frame['function'] ?? null)
                    ?? self::text($frame['raw_function'] ?? null),
                'module' => self::text($frame['module'] ?? null),
                'package' => self::text($frame['package'] ?? null),
                'lineno' => $lineno,
                'colno' => is_int($frame['colno'] ?? null) ? $frame['colno'] : null,
                // Fehlt die Angabe, gilt der Rahmen als fremder: eigener Code
                // ist die Ausnahme, die ein SDK ausdrücklich kennzeichnet.
                'inApp' => ($frame['in_app'] ?? false) === true,
                'context' => self::context($frame, $lineno),
                'vars' => is_array($frame['vars'] ?? null) && $frame['vars'] !== [] ? $frame['vars'] : null,
                // Woher dieser Rahmen kam, wenn er zurückübersetzt ist (R5). Er
                // steht an einem übersetzten Rahmen und nirgends sonst — und er
                // ist die einzige Möglichkeit zu erkennen, dass eine Übersetzung
                // aus einem anderen Bauvorgang stammt und daneben liegt.
                'minified' => self::minified($frame),
            ];
        }

        return $presented;
    }

    /**
     * Die minimierte Stelle, aus der ein übersetzter Rahmen entstanden ist.
     *
     * @param  array<string, mixed>  $frame
     * @return array{filename: string|null, function: string|null, lineno: int|null, colno: int|null}|null
     */
    private static function minified(array $frame): ?array
    {
        $minified = $frame['minified'] ?? null;

        if (! is_array($minified) || $minified === []) {
            return null;
        }

        return [
            'filename' => self::text($minified['filename'] ?? null),
            'function' => self::text($minified['function'] ?? null),
            'lineno' => is_int($minified['lineno'] ?? null) ? $minified['lineno'] : null,
            'colno' => is_int($minified['colno'] ?? null) ? $minified['colno'] : null,
        ];
    }

    /**
     * Die Code-Umgebung eines Rahmens, Zeile für Zeile mit ihrer Nummer.
     *
     * Ohne Zeilennummer bleiben die Nummern leer statt geraten zu werden: die
     * Zeilen stehen dann als Ausschnitt da, und das ist ehrlicher als eine
     * Nummerierung, die bei 1 beginnt und nirgends hinzeigt.
     *
     * @param  array<string, mixed>  $frame
     * @return list<array{number: int|null, text: string, current: bool}>
     */
    private static function context(array $frame, ?int $lineno): array
    {
        $before = self::lines($frame['pre_context'] ?? null);
        $current = self::text($frame['context_line'] ?? null);
        $after = self::lines($frame['post_context'] ?? null);

        if ($before === [] && $current === null && $after === []) {
            return [];
        }

        $lines = [];
        $number = $lineno === null ? null : $lineno - count($before);

        foreach ($before as $text) {
            $lines[] = ['number' => $number, 'text' => $text, 'current' => false];
            $number = $number === null ? null : $number + 1;
        }

        if ($current !== null) {
            $lines[] = ['number' => $number, 'text' => $current, 'current' => true];
            $number = $number === null ? null : $number + 1;
        }

        foreach ($after as $text) {
            $lines[] = ['number' => $number, 'text' => $text, 'current' => false];
            $number = $number === null ? null : $number + 1;
        }

        return $lines;
    }

    /**
     * Die letzten Schritte vor dem Fehler, ältester zuerst — eine Zeitleiste
     * liest sich vorwärts.
     *
     * @return list<array<string, mixed>>
     */
    private static function breadcrumbs(Event $event): array
    {
        $presented = [];

        foreach ($event->breadcrumbs ?? [] as $breadcrumb) {
            $timestamp = self::text($breadcrumb['timestamp'] ?? null);

            $presented[] = [
                'type' => self::text($breadcrumb['type'] ?? null),
                'category' => self::text($breadcrumb['category'] ?? null),
                'level' => self::text($breadcrumb['level'] ?? null),
                'message' => self::text($breadcrumb['message'] ?? null),
                'timestamp' => $timestamp,
                'timestampLabel' => $timestamp === null
                    ? null
                    : Formats::dateTimeSeconds(Carbon::parse($timestamp)),
                'data' => is_array($breadcrumb['data'] ?? null) && $breadcrumb['data'] !== []
                    ? $breadcrumb['data']
                    : null,
            ];
        }

        return $presented;
    }

    /**
     * Gerät, Betriebssystem, Browser, Laufzeit — und was ein SDK sonst mitgibt.
     *
     * Die Fächer kommen als benannte Abschnitte heraus statt als eine Tabelle:
     * `type` sagt, worum es geht, und ohne diese Trennung stünden Bildschirm-
     * auflösung und Sprachversion in derselben Liste.
     *
     * @return list<array{key: string, type: string, values: array<string, mixed>}>
     */
    private static function contexts(Event $event): array
    {
        $presented = [];

        foreach ($event->contexts ?? [] as $key => $values) {
            if (! is_array($values) || $values === []) {
                continue;
            }

            $type = self::text($values['type'] ?? null) ?? (string) $key;

            unset($values['type']);

            if ($values === []) {
                continue;
            }

            $presented[] = [
                'key' => (string) $key,
                'type' => $type,
                'values' => $values,
            ];
        }

        return $presented;
    }

    /**
     * Ein Schlüssel-Wert-Feld als Liste — die Oberfläche soll die Reihenfolge
     * nicht dem Zufall der Objekt-Schlüssel überlassen.
     *
     * @param  array<string, string>|null  $values
     * @return list<array{key: string, value: string}>
     */
    private static function pairs(?array $values): array
    {
        if ($values === null || $values === []) {
            return [];
        }

        ksort($values);

        $pairs = [];

        foreach ($values as $key => $value) {
            $pairs[] = ['key' => (string) $key, 'value' => (string) $value];
        }

        return $pairs;
    }

    /**
     * @param  list<string>|mixed  $lines
     * @return list<string>
     */
    private static function lines(mixed $lines): array
    {
        if (! is_array($lines)) {
            return [];
        }

        $texts = [];

        foreach ($lines as $line) {
            if (is_string($line)) {
                $texts[] = $line;
            }
        }

        return $texts;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
