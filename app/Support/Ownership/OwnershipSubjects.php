<?php

namespace App\Support\Ownership;

use App\Enums\OwnershipMatcher;
use App\Models\Event;

/**
 * Die Werte einer Meldung, gegen die eine Zuständigkeits-Regel verglichen wird.
 *
 * Eine eigene Klasse und nicht ein paar `??`-Zugriffe in der Auswertung, weil
 * dieselbe Frage an drei Stellen gestellt wird und dreimal dieselbe Antwort
 * geben muss: beim Aufnehmen einer Meldung, in der Vorschau („wer wäre
 * zuständig?") und bei den Vorschlägen im Zuweisungs-Dialog. Stünde die
 * Herleitung an jeder dieser Stellen, wäre die Vorschau genau das, was eine
 * Vorschau nicht sein darf — eine zweite Meinung.
 *
 * **Mehrere Werte je Art, nicht einer.** Ein Fehler hat nicht *einen* Pfad,
 * sondern einen Stacktrace: die Bibliothek, das Rahmenwerk, die eigene Datei.
 * Eine Regel trifft zu, wenn sie auf **irgendeinen** davon passt — alles andere
 * hieße zu raten, welcher Rahmen der gemeinte ist, und genau dafür gibt es die
 * Regel.
 */
final class OwnershipSubjects
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $urls
     * @param  list<string>  $modules
     * @param  array<string, string>  $tags
     */
    private function __construct(
        private readonly array $paths,
        private readonly array $urls,
        private readonly array $modules,
        private readonly array $tags,
    ) {}

    /**
     * Die Werte einer gespeicherten Meldung.
     *
     * Das Model und nicht die ausgewertete Fassung ({@see App\Support\Ingest\Normalization\NormalizedEvent}),
     * obwohl beim Aufnehmen beides vorliegt: der Zuweisungs-Dialog hat nur den
     * Datensatz, und zwei Herleitungen für dieselbe Frage wären eine zu viel.
     * Der Datensatz trägt alles Nötige — die Ausnahmen samt Rahmen, die Anfrage
     * und die Merkmale.
     */
    public static function fromEvent(Event $event): self
    {
        $paths = [];
        $modules = [];

        // **Alle** Ausnahmen der Ursachenkette und nicht nur die zuletzt
        // geworfene. Der Unterschied ist der Alltag: geworfen wird oben im
        // Rahmenwerk, die Ursache steht weiter unten — und zuständig ist, wem
        // die Ursache gehört.
        foreach ($event->exceptions ?? [] as $exception) {
            $frames = $exception['frames'] ?? null;

            if (! is_array($frames)) {
                continue;
            }

            foreach ($frames as $frame) {
                if (! is_array($frame)) {
                    continue;
                }

                // Beide Ortsangaben und nicht die erste beste: die SDKs füllen
                // mal `abs_path`, mal `filename`, oft beide mit verschiedenen
                // Fassungen desselben Pfades. Wer eine Regel schreibt, soll
                // nicht wissen müssen, welche gerade kam — dieselbe Überlegung
                // wie bei der Gruppierung.
                foreach (['abs_path', 'filename'] as $field) {
                    $value = $frame[$field] ?? null;

                    if (is_string($value) && trim($value) !== '') {
                        $paths[] = $value;
                    }
                }

                $module = $frame['module'] ?? null;

                if (is_string($module) && trim($module) !== '') {
                    $modules[] = $module;
                }
            }
        }

        $url = $event->request['url'] ?? null;

        return new self(
            self::unique($paths),
            is_string($url) && trim($url) !== '' ? [$url] : [],
            self::unique($modules),
            self::strings($event->tags ?? []),
        );
    }

    /**
     * Die Werte eines gedachten Ereignisses — für die Vorschau.
     *
     * Sie nimmt keinen Datensatz, weil die Frage vor dem ersten Fehler gestellt
     * wird: „wenn etwas in `src/billing/Invoice.php` passiert, wer bekommt es?"
     * Ein Beispiel dafür zu erfinden und in die Datenbank zu schreiben wäre der
     * umständlichere Weg zu derselben Antwort.
     *
     * @param  array<string, string>  $tags
     */
    public static function of(
        ?string $path = null,
        ?string $url = null,
        ?string $module = null,
        array $tags = [],
    ): self {
        return new self(
            self::unique(self::texts($path)),
            self::unique(self::texts($url)),
            self::unique(self::texts($module)),
            self::strings($tags),
        );
    }

    /**
     * Die Werte, gegen die eine Regel dieser Art zu vergleichen ist.
     *
     * Bei einem Merkmal ist die Antwort höchstens **ein** Wert — der des
     * benannten Schlüssels. Ohne Schlüssel gibt es nichts zu vergleichen: eine
     * Merkmalsregel ohne Schlüssel wäre „irgendein Merkmal hat diesen Wert",
     * und das ist keine Zuständigkeit, sondern ein Zufall.
     *
     * @return list<string>
     */
    public function for(OwnershipMatcher $matcher, ?string $tagKey = null): array
    {
        return match ($matcher) {
            OwnershipMatcher::Path => $this->paths,
            OwnershipMatcher::Url => $this->urls,
            OwnershipMatcher::Module => $this->modules,
            OwnershipMatcher::Tag => $tagKey === null || ! isset($this->tags[$tagKey])
                ? []
                : [$this->tags[$tagKey]],
        };
    }

    /**
     * Ist überhaupt etwas da, woran sich eine Zuständigkeit festmachen ließe?
     *
     * Die Frage lohnt sich vor dem Auswerten: eine Meldung ohne Stacktrace,
     * ohne Anfrage und ohne Merkmale — eine nackte Textmeldung — kann keine
     * Regel treffen, und die Liste durchzugehen wäre dann Arbeit für nichts.
     */
    public function isEmpty(): bool
    {
        return $this->paths === [] && $this->urls === [] && $this->modules === [] && $this->tags === [];
    }

    /**
     * @return list<string>
     */
    private static function texts(?string $value): array
    {
        return $value === null || trim($value) === '' ? [] : [trim($value)];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique($values));
    }

    /**
     * Merkmale kommen aus einer gespeicherten Spalte und sind deshalb nicht
     * verlässlich Text — eine Zahl als Wert ist keine Ausnahme.
     *
     * @param  array<array-key, mixed>  $tags
     * @return array<string, string>
     */
    private static function strings(array $tags): array
    {
        $out = [];

        foreach ($tags as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
