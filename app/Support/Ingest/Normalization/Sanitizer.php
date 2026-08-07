<?php

namespace App\Support\Ingest\Normalization;

/**
 * Prüft, wandelt und kürzt einzelne Werte — und schreibt mit, wenn dabei etwas
 * verloren geht.
 *
 * Alle Abschnitts-Normalisierer gehen über diese Klasse, statt jeder für sich
 * `is_string()` und `mb_substr()` aufzurufen. Das ist keine Bequemlichkeit,
 * sondern die Stelle, an der zwei Zusagen eingelöst werden: dass ein kaputtes
 * Feld verworfen wird, ohne die Meldung mitzunehmen, und dass jede Kürzung
 * vermerkt wird. Verteilt auf zwanzig Verwendungsstellen würde die zweite
 * Zusage an der ersten Stelle vergessen, an der jemand schnell etwas ergänzt.
 *
 * Der Umgang mit Zahlen und Wahrheitswerten in Textfeldern ist Absicht: SDKs
 * schicken `"line": "42"` ebenso wie `"line": 42`, und eine Fehlermeldung
 * wegzuwerfen, weil eine Zeilennummer als Zeichenkette kam, wäre Pedanterie auf
 * Kosten der Daten. Ein Feld-Baum in einem Textfeld ist dagegen kein
 * Schreibweisen-Unterschied, sondern ein anderer Datentyp — der wird verworfen
 * und vermerkt.
 */
final class Sanitizer
{
    public function __construct(
        private readonly Limits $limits,
        private readonly Notes $notes,
    ) {}

    public function limits(): Limits
    {
        return $this->limits;
    }

    public function notes(): Notes
    {
        return $this->notes;
    }

    /**
     * Ein Textfeld: gekürzt auf die erlaubte Länge, leer wie fehlend behandelt.
     *
     * Der leere Text ist bewusst `null`: `"culprit": ""` und ein fehlendes
     * `culprit` sind dieselbe Aussage, und zwei Schreibweisen für „nichts"
     * müsste danach jede Auswertung einzeln abfangen.
     */
    public function text(mixed $value, string $path, ?int $max = null): ?string
    {
        $text = $this->toText($value, $path);

        if ($text === null) {
            return null;
        }

        // Steuerzeichen fliegen raus, Zeilenumbrüche und Tabulatoren bleiben:
        // ein Nullbyte beendet in manchen Treibern die Zeichenkette, ein
        // Zeilenumbruch in einem Fehlertext ist dagegen der Normalfall.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        return $this->truncate($text, $path, $max ?? $this->limits->stringChars);
    }

    /**
     * Eine Zeile Quelltext. Eigene, deutlich engere Grenze als beim Textfeld:
     * eine gebündelte JavaScript-Datei besteht aus wenigen Zeilen von je
     * hunderten Kilobyte, und davon sind nur die ersten Zeichen für den
     * Menschen von Wert.
     *
     * Kein `trim()`: die Einrückung ist bei Quelltext die Information, die den
     * Rahmen im Umfeld überhaupt lesbar macht.
     */
    public function sourceLine(mixed $value, string $path): ?string
    {
        $text = $this->toText($value, $path);

        if ($text === null) {
            return null;
        }

        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return $this->truncate($text, $path, $this->limits->sourceLineChars);
    }

    /**
     * Eine ganze Zahl. `"42"` wird angenommen, `"vierzig"` verworfen.
     */
    public function integer(mixed $value, string $path): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && $value === floor($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if ($value !== null) {
            $this->notes->invalid($path);
        }

        return null;
    }

    /**
     * Ein Wahrheitswert. `"true"`, `1` und `"1"` gelten als wahr — SDKs
     * schicken für `in_app` alle drei Schreibweisen.
     */
    public function boolean(mixed $value, string $path): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        if ($value !== null) {
            $this->notes->invalid($path);
        }

        return null;
    }

    /**
     * Ein Abschnitt, der ein Objekt sein muss.
     *
     * Die leere Liste `[]` gilt als leeres Objekt und nicht als Fehler: in JSON
     * ist `{}` nach `json_decode(…, true)` von `[]` nicht mehr zu
     * unterscheiden, und mehrere SDKs schicken für einen leeren Abschnitt
     * tatsächlich `[]`.
     *
     * @return array<string, mixed>|null
     */
    public function map(mixed $value, string $path): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            $this->notes->invalid($path);

            return null;
        }

        if ($value === []) {
            return null;
        }

        if (array_is_list($value)) {
            $this->notes->invalid($path);

            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Ein Abschnitt, der eine Liste sein muss, gekappt auf die erlaubte Anzahl.
     *
     * Gekappt wird **am Ende**, nicht am Anfang: die Reihenfolge ist bei
     * Stapelrahmen und verschachtelten Ursachen die Information selbst, und wer
     * vorne abschneidet, verliert genau den Rahmen, in dem der Fehler entstand.
     *
     * @return list<mixed>
     */
    public function items(mixed $value, string $path, int $max): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            $this->notes->invalid($path);

            return [];
        }

        if (! array_is_list($value)) {
            // Ein Objekt statt einer Liste kommt vor, wenn ein SDK die Liste
            // mit Zahlen als Schlüsseln erzeugt hat. Die Werte sind dann
            // brauchbar, die Form nicht — also die Werte nehmen und die
            // Abweichung vermerken.
            $this->notes->invalid($path);

            $value = array_values($value);
        }

        if (count($value) > $max) {
            $this->notes->truncated($path);

            $value = array_slice($value, 0, $max);
        }

        return $value;
    }

    /**
     * Eine Liste, die auch in einen `values`-Umschlag gepackt sein darf.
     *
     * Sentry schreibt Ausnahmen, Ausführungsstränge und Spuren als
     * `{"values": [...]}` — eigentlich, denn mehrere SDKs schicken die nackte
     * Liste. Beides ist im Feld verbreitet genug, dass die Unterscheidung an
     * dieser einen Stelle abgeräumt gehört und nicht in jedem Abschnitt neu.
     *
     * @return list<mixed>
     */
    public function valueList(mixed $value, string $path, int $max): array
    {
        if (is_array($value) && ! array_is_list($value) && array_key_exists('values', $value)) {
            $value = $value['values'];
        }

        return $this->items($value, $path, $max);
    }

    /**
     * Ein Schlüssel-Wert-Abschnitt: Marken, Kopfzeilen, Umgebungsvariablen.
     *
     * Alles wird zu Text, weil dieser Abschnitt genau dafür da ist — nach
     * `tags.release` wird gesucht und gefiltert, nicht gerechnet. Ein Wert, der
     * selbst ein Feld-Baum ist, gehört nicht hierher und wird verworfen; für
     * frei geformte Daten gibt es {@see freeform()}.
     *
     * Sentry lässt Listen als Kopfzeilen-Wert zu (`"Set-Cookie": ["a", "b"]`),
     * weil HTTP dieselbe Kopfzeile mehrfach erlaubt. Die werden zusammengefügt
     * statt verworfen.
     *
     * @return array<string, string>
     */
    public function entries(mixed $value, string $path): array
    {
        $map = $this->map($value, $path);

        if ($map === null) {
            return [];
        }

        $entries = [];

        foreach ($map as $key => $entry) {
            if (count($entries) >= $this->limits->entries) {
                $this->notes->truncated($path);

                break;
            }

            $name = $this->text($key, $path, 200);

            if ($name === null) {
                continue;
            }

            if (is_array($entry) && array_is_list($entry)) {
                $entry = implode(', ', array_map(
                    static fn (mixed $part): string => is_scalar($part) ? (string) $part : '',
                    $entry,
                ));
            }

            $text = $this->text($entry, $path.'.'.$name);

            if ($text !== null) {
                $entries[$name] = $text;
            }
        }

        return $entries;
    }

    /**
     * Ein frei geformter Abschnitt: `extra`, die Kontexte, das Beiwerk einer
     * Spur.
     *
     * Hier ist jede Form erlaubt, weil die Anwendung hineinschreibt, was sie
     * für nützlich hält — Struktur zu erzwingen hieße, den Zweck des Feldes zu
     * verfehlen. Zwei Dinge werden trotzdem durchgesetzt: Texte werden gekürzt,
     * und ab einer bestimmten Tiefe ist Schluss. Ohne die Tiefengrenze genügte
     * ein rekursiv gebauter Feld-Baum, um die Auswertung zum Anhalten zu
     * bringen; ohne die Kürzung wäre die Grenze der Meldungsgröße die einzige.
     */
    public function freeform(mixed $value, string $path, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return $this->text($value, $path);
        }

        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_float($value)) {
            // Weder `NAN` noch `INF` überstehen `json_encode()`; sie würden die
            // ganze Meldung beim Ablegen scheitern lassen.
            return is_finite($value) ? $value : null;
        }

        if (! is_array($value)) {
            $this->notes->invalid($path);

            return null;
        }

        if ($depth >= $this->limits->depth) {
            $this->notes->truncated($path);

            return null;
        }

        $isList = array_is_list($value);
        $result = [];
        $count = 0;

        foreach ($value as $key => $entry) {
            if ($count >= $this->limits->entries) {
                $this->notes->truncated($path);

                break;
            }

            $count++;

            $child = $this->freeform($entry, $path.'.'.$key, $depth + 1);

            if ($isList) {
                $result[] = $child;

                continue;
            }

            $name = $this->text($key, $path, 200);

            if ($name !== null) {
                $result[$name] = $child;
            }
        }

        return $result;
    }

    /**
     * Nimmt einen Wert als Text an, soweit das ohne Bedeutungsverlust geht.
     */
    private function toText(mixed $value, string $path): ?string
    {
        if (is_string($value)) {
            // Ungültige Bytefolgen kommen vor, wenn eine Anwendung in einer
            // anderen Kodierung protokolliert. Sie würden `json_encode()`
            // beim Ablegen scheitern lassen und damit die ganze Meldung
            // kosten — also hier ersetzen, nicht dort auffliegen lassen.
            if (! mb_check_encoding($value, 'UTF-8')) {
                $this->notes->invalid($path);

                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }

            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? (string) $value : null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value !== null) {
            $this->notes->invalid($path);
        }

        return null;
    }

    /**
     * Kürzt auf die erlaubte Zeichenzahl und vermerkt die Kürzung.
     *
     * Gezählt werden Zeichen und nicht Bytes: eine Grenze in Bytes schneidet
     * ein Mehrbyte-Zeichen mitten durch, und die kaputte Bytefolge bringt
     * danach das Ablegen zu Fall — die Kürzung, die Schaden verhindern soll,
     * hätte selbst welchen angerichtet.
     */
    private function truncate(string $text, string $path, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $this->notes->truncated($path);

        return mb_substr($text, 0, $max);
    }
}
