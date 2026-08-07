<?php

namespace App\Support\Ingest\Scrubbing;

/**
 * Geht die Meldung Feld für Feld durch und ersetzt, was nicht gespeichert werden
 * darf.
 *
 * Zwei Entscheidungen prägen die Klasse:
 *
 * 1. **Ersetzen, nicht löschen.** Was wegfällt, wird durch {@see FILTERED}
 *    ersetzt und nicht entfernt. Ein fehlendes Feld ist von einem Feld, das die
 *    Anwendung nie geschickt hat, nicht zu unterscheiden — und dann steht bei
 *    jeder Fehlersuche die Frage im Raum, ob die Angabe fehlt oder geschwärzt
 *    wurde. Der Vermerk beantwortet sie.
 * 2. **Der Feldname zählt, nicht nur der Wert.** Ein Feld `password` wird
 *    geschwärzt, ohne seinen Inhalt anzusehen. Nur nach Mustern zu suchen hieße,
 *    ein kurzes Kennwort für unverdächtig zu halten.
 * 3. **Die Form bleibt, die Werte gehen.** Trifft eine Regel einen Abschnitt
 *    (`cookies`), werden dessen Werte ersetzt und nicht der Abschnitt selbst
 *    gegen einen Text getauscht. Das ist kein Feinschliff: die Normalisierung
 *    danach erwartet an dieser Stelle ein Objekt, und ein Text dort wäre für sie
 *    eine kaputte Meldung — sie würde einen „ungültig"-Vermerk an ein Feld
 *    schreiben, an dem alles richtig gelaufen ist.
 *
 * Der Weg zu einem Feld wird ohne Listenzähler geführt (`exception.values.value`
 * statt `exception.values.0.value`). Eine Regel soll für den zweiten Rahmen
 * eines Stacktrace gelten wie für den ersten; müsste sie die Nummer treffen,
 * wäre sie bei der nächsten Meldung wirkungslos.
 */
final class Scrubber
{
    /**
     * Der Vermerk, der an der Stelle eines geschwärzten Wertes steht. Dieselbe
     * Zeichenkette, die Sentry setzt — SDK-Autoren, Dokumentation und
     * Betriebshandbücher kennen sie, und eine eigene Erfindung wäre nur eine
     * weitere.
     */
    public const FILTERED = '[Filtered]';

    /**
     * Wie tief in einen Feld-Baum hineingegangen wird.
     *
     * Was tiefer liegt, wird **entfernt** und nicht durchgelassen. Das ist die
     * unbequeme, aber einzige richtige Antwort: einen tief gebauten Feld-Baum
     * kann eine Anwendung versehentlich erzeugen (eine rekursive Struktur, die
     * ein SDK ausschreibt), und ein Kennwort in der 40. Ebene ist genauso wenig
     * zu speichern wie eines in der ersten. Die Grenze ist so gewählt, dass
     * keine echte Meldung sie erreicht — die Normalisierung kürzt frei geformte
     * Abschnitte ohnehin viel früher.
     */
    public const MAX_DEPTH = 25;

    /**
     * Wie viele Wege gemeldet werden.
     *
     * Die Liste ist eine Auskunft für Menschen, kein Protokoll: wer eine Regel
     * schreibt, die zehntausend Felder trifft, erkennt das an den ersten
     * fünfzig. Ohne die Grenze wäre der Bericht bei einer großen Meldung
     * umfangreicher als die Meldung selbst.
     */
    public const MAX_PATHS = 50;

    /**
     * Feldnamen, deren Wert eine Adresse mit Abfrage-Werten sein kann.
     *
     * Nur diese, und nicht jeder Text: ein Gleichheitszeichen kommt in
     * Fehlermeldungen, SQL-Ausdrücken und Quelltextzeilen laufend vor, und dort
     * ist `a=b` kein Abfrage-Wert. Die Liste bleibt damit erklärbar — was
     * geschwärzt wird, hängt am Feldnamen und nicht daran, wie ein Text aussieht.
     *
     * @var list<string>
     */
    private const QUERY_CARRYING = ['url', 'query_string', 'referer', 'referrer', 'location', 'href'];

    /**
     * Anweisungen, die überall greifen — vorab getrennt, weil das der Regelfall
     * ist und die Prüfung auf den Abschnitt dann für sie entfällt.
     *
     * @var list<Directive>
     */
    private array $global = [];

    /**
     * Anweisungen mit Abschnitts-Einschränkung.
     *
     * @var list<Directive>
     */
    private array $scoped = [];

    /** @var list<string> */
    private array $paths = [];

    public function __construct(Settings $settings)
    {
        foreach ($settings->directives as $directive) {
            if (! $directive->isUsable()) {
                continue;
            }

            if ($directive->isGlobal()) {
                $this->global[] = $directive;

                continue;
            }

            $this->scoped[] = $directive;
        }
    }

    /**
     * @param  array<mixed>  $data
     */
    public function scrub(array $data): ScrubResult
    {
        $this->paths = [];

        /** @var array<mixed> $scrubbed */
        $scrubbed = $this->walk($data, '', 0);

        return new ScrubResult($scrubbed, $this->paths);
    }

    /**
     * Ein Knoten des Feld-Baums.
     *
     * Der Feldname ist bereits im Weg enthalten — geprüft wird er beim Absteigen
     * durch den Vater, nicht hier. So gilt eine Feld-Regel für den ganzen Ast
     * unter dem Feld: `password: {"value": "…"}` ist genauso ein Kennwort wie
     * `password: "…"`, und nur den Namen mit dem Wert daneben zu vergleichen
     * ließe die verschachtelte Form stehen.
     */
    private function walk(mixed $node, string $path, int $depth): mixed
    {
        if (is_string($node)) {
            return $this->filterText($node, $path);
        }

        if (is_int($node) || is_float($node)) {
            // Eine Kartennummer kommt auch als Zahl an. Nur Texte anzusehen wäre
            // eine Lücke, die sich mit einem weggelassenen Anführungszeichen
            // ausnutzen ließe — der Typ bleibt erhalten, solange nichts greift.
            $text = (string) $node;
            $filtered = $this->filterText($text, $path);

            return $filtered === $text ? $node : $filtered;
        }

        if (! is_array($node)) {
            return $node;
        }

        if ($depth >= self::MAX_DEPTH) {
            $this->note($path);

            return self::FILTERED;
        }

        $isList = array_is_list($node);
        $result = [];

        foreach ($node as $key => $value) {
            if ($isList) {
                // Listenzähler bleiben aus dem Weg: siehe Klassenkommentar.
                $result[] = $this->walk($value, $path, $depth + 1);

                continue;
            }

            $name = (string) $key;
            $childPath = $path === '' ? $name : $path.'.'.$name;

            if ($this->fieldMatches($name, $childPath)) {
                $this->note($childPath);

                $result[$name] = self::redact($value, $depth + 1);

                continue;
            }

            $result[$name] = $this->walk($value, $childPath, $depth + 1);
        }

        return $result;
    }

    /**
     * Ersetzt alles unterhalb eines getroffenen Feldes.
     *
     * Kein weiterer Blick auf die Regeln: über dieses Feld ist entschieden, und
     * was darin steht, geht mit. Ein `password`, das ein Objekt ist, ist
     * genauso ein Kennwort wie eines, das ein Text ist.
     */
    private static function redact(mixed $node, int $depth): mixed
    {
        if (! is_array($node) || $depth >= self::MAX_DEPTH) {
            return self::FILTERED;
        }

        $isList = array_is_list($node);
        $result = [];

        foreach ($node as $key => $value) {
            if ($isList) {
                $result[] = self::redact($value, $depth + 1);

                continue;
            }

            $result[(string) $key] = self::redact($value, $depth + 1);
        }

        // Ein leerer Abschnitt hat keine Werte, die zu ersetzen wären — der
        // Vermerk muss trotzdem hin, sonst sähe das Ergebnis so aus, als hätte
        // die Regel nicht gegriffen.
        return $result === [] ? self::FILTERED : $result;
    }

    /**
     * Trifft eine Feld-Regel diesen Namen an dieser Stelle?
     */
    private function fieldMatches(string $name, string $path): bool
    {
        foreach ($this->global as $directive) {
            if ($directive->matchesField($name)) {
                return true;
            }
        }

        foreach ($this->scoped as $directive) {
            if ($directive->appliesTo($path) && $directive->matchesField($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lässt die Muster-Regeln über einen Text laufen.
     *
     * Alle, nicht nur die erste, die trifft: ein Fehlertext kann eine
     * Kartennummer **und** einen Nachweis enthalten, und die zweite Regel
     * arbeitet auf dem Ergebnis der ersten weiter.
     */
    private function filterText(string $value, string $path): string
    {
        if ($value === '') {
            return $value;
        }

        $filtered = $value;

        foreach ($this->global as $directive) {
            $filtered = $directive->filterValue($filtered, self::FILTERED);
        }

        foreach ($this->scoped as $directive) {
            if ($directive->appliesTo($path)) {
                $filtered = $directive->filterValue($filtered, self::FILTERED);
            }
        }

        $filtered = $this->filterQuery($filtered, $path);

        if ($filtered !== $value) {
            $this->note($path);
        }

        return $filtered;
    }

    /**
     * Schwärzt Abfrage-Werte in einem Text, dessen Feldname eine Adresse
     * erwarten lässt.
     *
     * Der Grund für diesen Sonderfall ist eine Lücke, die sonst offen bliebe und
     * genau den häufigsten Fall trifft: `?token=abc` steht in der Adresse als
     * **ein** Text, und eine Feld-Regel für `token` kommt an ihn nicht heran —
     * einen Feldnamen `token` gibt es dort nicht. Die Adresse ist aber bei einem
     * Web-Fehler das erste, was gespeichert wird.
     *
     * Geprüft werden dieselben Feld-Regeln wie sonst, angewandt auf den Namen des
     * Abfrage-Werts. Damit greifen auch eigene Regeln, ohne dass jemand sie ein
     * zweites Mal für Adressen anlegen müsste.
     */
    private function filterQuery(string $value, string $path): string
    {
        if (! str_contains($value, '=')) {
            return $value;
        }

        $dot = strrpos($path, '.');
        $leaf = strtolower($dot === false ? $path : substr($path, $dot + 1));

        if (! in_array($leaf, self::QUERY_CARRYING, true)) {
            return $value;
        }

        return (string) preg_replace_callback(
            // Ein Name-Wert-Paar am Anfang des Texts (`a=1&b=2`) oder hinter
            // einem Trenner (`…?a=1`). Ohne diese Verankerung gälte jedes
            // Gleichheitszeichen in einem Pfad als Abfrage-Wert.
            '/(^|[?&])([^?&=\s]+)=([^&\s]*)/',
            fn (array $match): string => $this->fieldMatches(urldecode($match[2]), $path)
                ? $match[1].$match[2].'='.self::FILTERED
                : $match[0],
            $value,
        );
    }

    /**
     * Hält einen geänderten Weg fest — jeden nur einmal, weil derselbe Weg bei
     * einer Liste für jedes Element wiederkommt.
     */
    private function note(string $path): void
    {
        if (count($this->paths) >= self::MAX_PATHS || in_array($path, $this->paths, true)) {
            return;
        }

        $this->paths[] = $path;
    }
}
