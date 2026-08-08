<?php

namespace App\Support\SourceMaps;

use App\Enums\SymbolicationDiagnosis;
use App\Enums\SymbolicationStatus;
use App\Models\Event;
use App\Models\Release;
use App\Models\ReleaseArtifact;

/**
 * Übersetzt den minimierten Stacktrace einer Meldung in geschriebenen Quelltext
 * zurück.
 *
 * Aus `a.b.c` in Zeile 1, Spalte 4711 wird `src/warenkorb.ts` Zeile 42,
 * `berechneSumme` — samt der Zeilen darum herum. Was dabei entsteht, ist eine
 * **zweite Fassung** der Ausnahmen in genau der Form, die schon gespeichert ist
 * (`events.exceptions`): dieselben Felder, dieselbe Reihenfolge. Damit nimmt die
 * Anzeige denselben Weg wie für die gemeldete Fassung, und es gibt keine zweite
 * Stelle, an der ein Stacktrace zusammengebaut wird.
 *
 * **Nicht jeder Rahmen kommt in Frage.** Angefasst wird, was nach einer
 * ausgelieferten JavaScript-Datei aussieht und eine Zeile nennt. Ein Rahmen aus
 * dem PHP-Backend derselben Spur, ein Rahmen ohne Datei, ein Rahmen aus dem
 * Browser selbst (`<anonymous>`) — die bleiben unberührt und **erzeugen keine
 * Diagnose**: sie sind nicht gescheitert, sie waren nie gemeint. Diese Trennung
 * ist der Unterschied zwischen einer Diagnose, die man liest, und einer Liste von
 * vierzig Gründen, die man wegklickt.
 */
final class Symbolicator
{
    /**
     * Endungen, die auf eine ausgelieferte JavaScript-Datei hindeuten.
     *
     * Bewusst am Pfad und nicht an der Plattform der Meldung entschieden: eine
     * Meldung aus `node` trägt dieselben Bundles, und eine Anwendung, die ihr SDK
     * ohne `platform` betreibt, hat trotzdem Quellkarten.
     *
     * @var list<string>
     */
    private const EXTENSIONS = ['.js', '.mjs', '.cjs', '.jsx', '.ts', '.tsx'];

    public function __construct(
        private readonly ArtifactStore $store,
    ) {}

    /**
     * Lohnt eine Rückübersetzung für diese Meldung?
     *
     * Die Frage wird zweimal gestellt: vor dem Einreihen des Auftrags — ein
     * Auftrag je Meldung aus einem PHP-Backend wäre eine Warteschlange voller
     * Nichts — und in der Anzeige, um zu entscheiden, ob überhaupt eine zweite
     * Sicht zu erwarten ist.
     */
    public static function isApplicable(Event $event): bool
    {
        foreach ($event->exceptions ?? [] as $exception) {
            foreach (self::framesOf($exception) as $frame) {
                if (self::isCandidate($frame)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Übersetzt die Ausnahmen einer Meldung zurück.
     */
    public function symbolicate(Event $event): SymbolicationResult
    {
        $release = $this->releaseOf($event);
        $debugIds = self::debugIds($event);
        $resolver = new ArtifactResolver($this->store);

        $limit = (int) config('sourcemaps.max_frames');
        $contextLines = (int) config('sourcemaps.context_lines');

        $exceptions = [];
        $diagnoses = [];
        $total = 0;
        $mapped = 0;

        foreach ($event->exceptions ?? [] as $exception) {
            $frames = [];

            foreach (self::framesOf($exception) as $frame) {
                if (! self::isCandidate($frame)) {
                    $frames[] = $frame;

                    continue;
                }

                $total++;

                if ($total > $limit) {
                    // Über der Grenze wird der Rahmen unverändert übernommen. Er
                    // fehlt damit nicht in der Anzeige — nur übersetzt ist er
                    // nicht, und das steht als Grund daneben.
                    $frames[] = $frame;
                    $diagnoses[] = [SymbolicationDiagnosis::FrameLimitReached, (string) $limit];

                    continue;
                }

                $reported = self::pathOf($frame) ?? '';
                $match = $resolver->resolve($release, $event->project_id, $reported, $debugIds);

                $map = $match->map;

                if ($map === null) {
                    $frames[] = $frame;
                    $diagnoses[] = [$match->diagnosis ?? SymbolicationDiagnosis::ArtifactNotFound, $match->detail];

                    continue;
                }

                $lineno = is_int($frame['lineno'] ?? null) ? $frame['lineno'] : null;

                if ($lineno === null) {
                    $frames[] = $frame;
                    $diagnoses[] = [SymbolicationDiagnosis::NoPosition, $reported];

                    continue;
                }

                $location = $map->lookup($lineno, is_int($frame['colno'] ?? null) ? $frame['colno'] : null);

                if ($location === null) {
                    $frames[] = $frame;
                    $diagnoses[] = [SymbolicationDiagnosis::NoMappingForPosition, $reported];

                    continue;
                }

                $context = $map->context($location->sourceIndex, $location->line, $contextLines);

                if ($context === null) {
                    $diagnoses[] = [SymbolicationDiagnosis::NoSourceContent, $location->file];
                }

                $frames[] = self::translate($frame, $location, $context);
                $mapped++;
            }

            $exception['frames'] = $frames;
            $exceptions[] = $exception;
        }

        return SymbolicationResult::make(
            status: self::status($mapped, $total),
            exceptions: $exceptions,
            mappedFrames: $mapped,
            totalFrames: $total,
            diagnoses: $diagnoses,
        );
    }

    /**
     * Ein Rahmen, wie er nach der Übersetzung aussieht.
     *
     * **Der minimierte Rahmen geht nicht verloren**, er wandert nach
     * `minified`. Das ist die Rückfahrkarte: stimmt die Übersetzung nicht — eine
     * Karte aus einem anderen Bau —, ist der einzige Weg, das zu merken, der
     * Vergleich mit dem, was gemeldet wurde.
     *
     * Der Funktionsname kommt aus der Karte, wenn sie einen kennt, und bleibt
     * sonst der minimierte. Ein leerer Name wäre schlechter als `a.b.c`: dass
     * dort einmal ein Name stand, ist selbst eine Auskunft.
     *
     * @param  array<string, mixed>  $frame
     * @param  array{pre: list<string>, current: string, post: list<string>}|null  $context
     * @return array<string, mixed>
     */
    private static function translate(array $frame, SourceLocation $location, ?array $context): array
    {
        $translated = [
            'filename' => $location->file,
            'abs_path' => $location->file,
            'function' => $location->function ?? (is_string($frame['function'] ?? null) ? $frame['function'] : null),
            'lineno' => $location->line,
            'colno' => $location->column,
            'in_app' => $location->isInApp(),
            'minified' => array_filter([
                'filename' => self::pathOf($frame),
                'function' => is_string($frame['function'] ?? null) ? $frame['function'] : null,
                'lineno' => is_int($frame['lineno'] ?? null) ? $frame['lineno'] : null,
                'colno' => is_int($frame['colno'] ?? null) ? $frame['colno'] : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];

        if ($context !== null) {
            $translated['pre_context'] = $context['pre'];
            $translated['context_line'] = $context['current'];
            $translated['post_context'] = $context['post'];
        }

        // Die örtlichen Variablen stammen von der Aufnahmestelle und nicht aus
        // der Karte — sie gelten für denselben Rahmen und werden mitgenommen.
        if (is_array($frame['vars'] ?? null) && $frame['vars'] !== []) {
            $translated['vars'] = $frame['vars'];
        }

        return array_filter($translated, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Der Gesamtausgang aus den beiden Zahlen.
     */
    private static function status(int $mapped, int $total): SymbolicationStatus
    {
        if ($total === 0 || $mapped === 0) {
            return SymbolicationStatus::Unmapped;
        }

        return $mapped === $total ? SymbolicationStatus::Mapped : SymbolicationStatus::Partial;
    }

    /**
     * Die Version, zu der die Meldung gehört.
     *
     * Über die Versionsangabe am Ereignis und nicht über den Verweis am
     * Fehler-Eintrag: der zeigt auf die Version, in der der **Eintrag** zuerst
     * oder zuletzt gesehen wurde, und das ist bei einer älteren Meldung eine
     * andere als ihre eigene. Übersetzt wird mit den Artefakten der Version, aus
     * der die Meldung stammt — mit einer anderen kämen falsche Zeilen heraus.
     */
    private function releaseOf(Event $event): ?Release
    {
        $version = Release::normalizeVersion($event->release);

        if ($version === null) {
            return null;
        }

        return Release::query()
            ->where('project_id', $event->project_id)
            ->where('version', $version)
            ->first();
    }

    /**
     * Die Debug-Kennungen einer Meldung, nach gemeldeter Datei.
     *
     * Sie stehen in `debug_meta.images` — je geladener Datei ein Eintrag mit
     * ihrer Adresse (`code_file`) und der Kennung. Die Adresse wird gleich in all
     * ihre Schreibweisen aufgefaltet ({@see ReleaseArtifact::candidatesFor()}),
     * damit die Suche später nicht raten muss, in welcher Form ein Rahmen
     * dieselbe Datei nennt.
     *
     * @return array<string, string>
     */
    private static function debugIds(Event $event): array
    {
        $images = $event->debug_meta['images'] ?? null;

        if (! is_array($images)) {
            return [];
        }

        $ids = [];

        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }

            $debugId = ReleaseArtifact::normalizeDebugId(
                is_string($image['debug_id'] ?? null) ? $image['debug_id'] : null
            );

            $file = is_string($image['code_file'] ?? null) ? $image['code_file'] : null;

            if ($debugId === null || $file === null || trim($file) === '') {
                continue;
            }

            foreach (ReleaseArtifact::candidatesFor($file) as $candidate) {
                // Der erste Eintrag gewinnt: zwei Bilder, die auf dieselbe Datei
                // zeigen, sind ein Widerspruch in der Meldung, und die Kennung
                // der zuerst genannten ist so gut wie die andere.
                $ids[$candidate] ??= $debugId;
            }
        }

        return $ids;
    }

    /**
     * Die Rahmen einer Ausnahme.
     *
     * @return list<array<string, mixed>>
     */
    private static function framesOf(mixed $exception): array
    {
        if (! is_array($exception) || ! is_array($exception['frames'] ?? null)) {
            return [];
        }

        $frames = [];

        foreach ($exception['frames'] as $frame) {
            if (is_array($frame)) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    /**
     * Kommt dieser Rahmen für eine Übersetzung in Frage?
     *
     * @param  array<string, mixed>  $frame
     */
    private static function isCandidate(array $frame): bool
    {
        if (! is_int($frame['lineno'] ?? null)) {
            return false;
        }

        $path = self::pathOf($frame);

        if ($path === null) {
            return false;
        }

        // Ein bereits übersetzter Rahmen kommt nicht zweimal dran. Das ist kein
        // erdachter Fall: eine Meldung kann eine zweite Fassung ihrer Rahmen
        // schon mitbringen, wenn das SDK selbst übersetzt hat.
        if (isset($frame['minified'])) {
            return false;
        }

        $lower = strtolower((string) preg_replace('/[?#].*$/', '', $path));

        foreach (self::EXTENSIONS as $extension) {
            if (str_ends_with($lower, $extension)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Der Pfad, den ein Rahmen nennt.
     *
     * `abs_path` zuerst: er trägt die vollständige Adresse, `filename` bei
     * manchen SDKs nur den Dateinamen — und je genauer die Angabe, desto besser
     * die Zuordnung.
     *
     * @param  array<string, mixed>  $frame
     */
    private static function pathOf(array $frame): ?string
    {
        foreach (['abs_path', 'filename'] as $field) {
            $value = $frame[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
