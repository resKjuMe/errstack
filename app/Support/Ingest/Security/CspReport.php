<?php

namespace App\Support\Ingest\Security;

use App\Enums\SecurityReportType;

/**
 * Ein Verstoß gegen die Content-Security-Policy.
 *
 * Der Browser schickt ihn, wenn er eine Ressource nicht geladen hat, weil die
 * Richtlinie der Seite sie verbietet — ein Skript von einem fremden Wirt, ein
 * `eval()`, ein eingebettetes `<style>`. Für die überwachte Anwendung ist das
 * zweierlei zugleich: der Hinweis auf einen versuchten Angriff und der Hinweis
 * auf eine zu enge Richtlinie. Welches von beidem, entscheidet die blockierte
 * Quelle — und genau deshalb wird nach ihr gruppiert.
 *
 * **Gruppiert wird nach Direktive und blockierter Quelle**, nicht nach der
 * betroffenen Seite. Der Unterschied ist der zwischen einem Eintrag und
 * zehntausend: dieselbe eingeklinkte Werbung wird auf jeder Unterseite
 * blockiert, und der Befund ist jedes Mal derselbe. Von der Quelle bleibt
 * dafür nur ihr Ursprung stehen — `https://ads.example/a.js?v=17` und
 * `https://ads.example/b.js` sind derselbe Wirt und dasselbe Problem.
 */
final class CspReport extends SecurityReport
{
    /**
     * Werte, die `blocked-uri` statt einer Adresse tragen kann.
     *
     * Sie stehen für die Art des Verstoßes und nicht für eine Herkunft:
     * `inline` ist ein `<script>` im Seitenquelltext, `eval` ein Aufruf von
     * `eval()`, `self` eine Ressource der eigenen Herkunft. Sie durch die
     * Adress-Zerlegung zu schicken hieße, sie zu verlieren — `parse_url('eval')`
     * liefert keinen Wirt, und übrig bliebe ein leeres Feld an der Stelle, an
     * der die eigentliche Auskunft steht.
     *
     * @var list<string>
     */
    private const KEYWORDS = ['inline', 'eval', 'self', 'data', 'blob', 'filesystem', 'wasm-eval', 'unsafe-eval'];

    /**
     * Was in der Überschrift steht, wenn `blocked-uri` fehlt.
     *
     * Der Fall kommt vor: bei `inline`-Verstößen lassen einzelne Browser das
     * Feld leer, statt das Schlüsselwort zu setzen.
     */
    private const UNKNOWN_SOURCE = 'unbekannte Quelle';

    public function type(): SecurityReportType
    {
        return SecurityReportType::Csp;
    }

    public function sources(): array
    {
        // Beide, und beide sind nötig: `blocked-uri` verrät die Erweiterung,
        // die eine Ressource nachlädt, `source-file` die, die ein Skript in die
        // Seite schreibt. Bei der zweiten Sorte steht in `blocked-uri` nur
        // `inline`, und das sieht aus wie ein Befund über die Anwendung.
        return array_values(array_filter([
            $this->text('blocked-uri', 1000),
            $this->text('source-file', 1000),
            $this->text('document-uri', 1000),
        ]));
    }

    private function title(): string
    {
        return sprintf('%s blockierte %s', $this->directive(), $this->blockedSource() ?? self::UNKNOWN_SOURCE);
    }

    protected function culprit(): ?string
    {
        return $this->blockedSource();
    }

    protected function fingerprint(): array
    {
        return [
            $this->type()->value,
            $this->directive(),
            $this->blockedSource() ?? self::UNKNOWN_SOURCE,
        ];
    }

    protected function tags(): array
    {
        return array_filter([
            'security_report' => $this->type()->value,
            'directive' => $this->directive(),
            'blocked_source' => $this->blockedSource(),
            // `enforce` oder `report`: ob der Browser tatsächlich blockiert hat
            // oder die Richtlinie nur im Probelauf lief
            // (`Content-Security-Policy-Report-Only`). Der Unterschied
            // entscheidet, ob gerade etwas kaputt ist oder ob jemand eine
            // Richtlinie erprobt.
            'disposition' => $this->text('disposition', 20),
        ], static fn (?string $value): bool => $value !== null);
    }

    protected function url(): ?string
    {
        return $this->text('document-uri', 1000);
    }

    protected function message(): array
    {
        $directive = $this->directive();
        $source = $this->blockedSource() ?? self::UNKNOWN_SOURCE;

        return [
            'message' => 'Sicherheitsrichtlinie verletzt: %s blockierte %s',
            'params' => [$directive, $source],
            'formatted' => 'Sicherheitsrichtlinie verletzt: '.$this->title(),
        ];
    }

    /**
     * Die verletzte Direktive — `script-src`, `img-src`, `frame-ancestors`.
     *
     * `effective-directive` ist die Antwort des Browsers auf genau diese Frage
     * und hat deshalb Vorrang. Ältere Browser kennen das Feld nicht und füllen
     * nur `violated-directive`; dort steht die Direktive samt ihrer Quellenliste
     * (`script-src https://cdn.example 'unsafe-inline'`), und die ganze Liste in
     * den Fingerabdruck zu nehmen hieße, bei jeder Änderung der Richtlinie eine
     * neue Gruppe zu bekommen.
     */
    private function directive(): string
    {
        $effective = $this->text('effective-directive', 100);

        if ($effective !== null) {
            return $effective;
        }

        $violated = $this->text('violated-directive', 400) ?? '';

        $first = strtok($violated, " \t\n");

        return $first === false || $first === '' ? 'unbekannte Direktive' : $first;
    }

    /**
     * Die blockierte Quelle, auf ihren Ursprung gekürzt.
     *
     * Pfad, Abfrageteil und Sprungmarke fallen weg. Sie machen aus einem
     * Befund pro Wirt einen pro Datei — und bei einer Erweiterung, die für
     * jede Seite eine eigene Adresse baut, sogar einen pro Aufruf. Der
     * Anschluss bleibt: `https://example:8443` ist ein anderer Ursprung als
     * `https://example`, und die Richtlinie sieht das genauso.
     */
    private function blockedSource(): ?string
    {
        $blocked = $this->text('blocked-uri', 1000);

        if ($blocked === null) {
            return null;
        }

        if (in_array(strtolower($blocked), self::KEYWORDS, true)) {
            return strtolower($blocked);
        }

        return self::origin($blocked);
    }

    /**
     * Der Ursprung einer Adresse: Schema, Wirt und Anschluss.
     *
     * Ohne erkennbaren Wirt bleibt die Angabe, wie sie kam — gekürzt auf die
     * Länge einer Marke. Das trifft die Adressen der Browser-Erweiterungen
     * (`chrome-extension://…`) ebenso wie Werte, die gar keine Adresse sind;
     * beide sollen erkennbar bleiben, statt zu verschwinden.
     */
    private static function origin(string $uri): string
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return mb_substr($uri, 0, 200);
        }

        $origin = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $origin .= $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
