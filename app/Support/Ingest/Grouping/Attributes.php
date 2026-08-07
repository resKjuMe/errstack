<?php

namespace App\Support\Ingest\Grouping;

use App\Support\Ingest\Normalization\NormalizedEvent;

/**
 * Der Zugriff auf die Felder einer Meldung unter stabilen Namen.
 *
 * Diese Namen sind die Sprache, in der projektweite Fingerprint-Regeln
 * geschrieben werden — sowohl auf der Bedingungsseite (`error.type` ist
 * `TimeoutException`) als auch auf der Ergebnisseite (`{{ error.type }}` als
 * Bestandteil des Fingerabdrucks). Beides über **eine** Klasse, und zwar
 * absichtlich: eine Regel, die nach einem Feld sucht, das sie anschließend
 * nicht einsetzen darf, wäre für den Schreibenden nicht erklärbar.
 *
 * Die Namen folgen Sentry, damit ein bestehendes Regelwerk übernommen werden
 * kann, ohne es zu übersetzen.
 *
 * Ein Feld, das die Meldung nicht hat, ist eine leere Liste — nicht `null` und
 * nicht ein leerer Text. Der Unterschied entscheidet: eine Bedingung auf ein
 * fehlendes Feld greift **nicht**, statt auf den leeren Text zu passen.
 */
final class Attributes
{
    /**
     * Die Rahmen, aus denen die `stack.*`-Felder gelesen werden.
     *
     * @var list<array<string, mixed>>
     */
    private readonly array $frames;

    public function __construct(
        private readonly NormalizedEvent $event,
    ) {
        $this->frames = $event->frames();
    }

    /**
     * Alle Werte eines Feldes.
     *
     * Mehrere, weil ein Stacktrace viele Rahmen hat und eine Bedingung wie
     * `stack.abs_path:*vendor/*` auf **irgendeinen** davon zutreffen soll.
     * Wer einen einzelnen Wert braucht, nimmt {@see value()}.
     *
     * @return list<string>
     */
    public function all(string $name): array
    {
        $name = strtolower(trim($name));

        if (str_starts_with($name, 'tags.')) {
            $tag = $this->event->tags[substr($name, 5)] ?? null;

            return $tag === null ? [] : [$tag];
        }

        if (str_starts_with($name, 'stack.')) {
            return $this->fromFrames(substr($name, 6));
        }

        return match ($name) {
            'error.type', 'type' => $this->fromExceptions('type'),
            'error.value', 'value' => $this->fromExceptions('value'),
            'error.module' => $this->fromExceptions('module'),
            'error.mechanism' => $this->fromMechanisms(),
            'message' => $this->texts($this->message()),
            'level' => [$this->event->level->value],
            'platform' => [$this->event->platform],
            'title' => $this->texts($this->event->title),
            'culprit' => $this->texts($this->event->culprit),
            'transaction' => $this->texts($this->event->transaction),
            'logger' => $this->texts($this->event->logger),
            'environment' => $this->texts($this->event->environment),
            'release' => $this->texts($this->event->release),
            'dist' => $this->texts($this->event->dist),
            'server_name' => $this->texts($this->event->serverName),
            'sdk.name' => $this->texts(is_string($this->event->sdk['name'] ?? null) ? $this->event->sdk['name'] : null),
            default => [],
        };
    }

    /**
     * Der eine Wert eines Feldes — der, der die Meldung am besten beschreibt.
     *
     * Bei den Ausnahmen ist das die zuletzt geworfene und nicht die erste
     * Ursache: sie ist, was die Anwendung gesehen hat. Bei den Rahmen der
     * letzte aus eigenem Code, denn dort sitzt der Fehler, den jemand beheben
     * kann — der letzte Rahmen im Ganzen wäre der aus dem Rahmenwerk, an dem
     * nichts zu ändern ist.
     */
    public function value(string $name): ?string
    {
        $name = strtolower(trim($name));

        if (str_starts_with($name, 'stack.')) {
            $frame = $this->relevantFrame();

            if ($frame === null) {
                return null;
            }

            return $this->fromFrame($frame, substr($name, 6));
        }

        if (str_starts_with($name, 'error.') || $name === 'type' || $name === 'value') {
            $values = $this->all($name);

            return $values === [] ? null : $values[array_key_last($values)];
        }

        $values = $this->all($name);

        return $values === [] ? null : $values[0];
    }

    /**
     * Der Rahmen, der den Fehler am besten beschreibt.
     *
     * Der letzte mit `in_app` — die Fehlerstelle im eigenen Code. Gibt es
     * keinen, der letzte überhaupt: besser ein Rahmen aus dem Rahmenwerk als
     * gar keiner.
     *
     * `in_app` fehlt bei manchen SDKs ganz. Ein fehlender Wert gilt hier
     * **nicht** als `false` — die Normalisierung lässt ihn bewusst offen, und
     * ihn hier zu erraten hieße, den Rahmen zu verstecken, in dem der Fehler
     * tatsächlich sitzt.
     *
     * @return array<string, mixed>|null
     */
    public function relevantFrame(): ?array
    {
        $inApp = null;
        $fallback = null;

        foreach ($this->frames as $frame) {
            $fallback = $frame;

            if (($frame['in_app'] ?? null) === true) {
                $inApp = $frame;
            }
        }

        return $inApp ?? $fallback;
    }

    /**
     * Der Meldungstext in der Form, die für die Zuordnung zählt.
     *
     * Die Vorlage hat Vorrang vor dem ausgefüllten Text: „Nutzer %s nicht
     * gefunden" ist bei jedem Nutzer dieselbe, der ausgefüllte Text bei jedem
     * ein anderer. Ohne diesen Vorrang entstünde je Kennung eine Gruppe.
     */
    public function message(): ?string
    {
        $message = $this->event->message;

        if ($message === null) {
            return null;
        }

        foreach (['template', 'formatted'] as $field) {
            $value = $message[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function fromExceptions(string $field): array
    {
        $values = [];

        foreach ($this->event->exceptions as $exception) {
            $value = $exception[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Die Art, auf die die Ausnahme zu uns kam (`onerror`, `signalhandler` …).
     *
     * @return list<string>
     */
    private function fromMechanisms(): array
    {
        $values = [];

        foreach ($this->event->exceptions as $exception) {
            $value = $exception['mechanism']['type'] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function fromFrames(string $field): array
    {
        $values = [];

        foreach ($this->frames as $frame) {
            $value = $this->fromFrame($frame, $field);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function fromFrame(array $frame, string $field): ?string
    {
        // `path` als Kurzform für „irgendeine Ortsangabe": die SDKs füllen mal
        // `abs_path`, mal `filename`, und wer eine Regel schreibt, will beides
        // treffen, ohne zu wissen, welches gerade kam.
        $fields = match ($field) {
            'path' => ['abs_path', 'filename'],
            default => [$field],
        };

        foreach ($fields as $key) {
            $value = $frame[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function texts(?string $value): array
    {
        return $value === null || trim($value) === '' ? [] : [$value];
    }
}
