<?php

namespace App\Support\Issues;

use App\Models\Issue;
use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;

/**
 * Die Suche in der Fehlerliste — vorerst nur nach der ausgelieferten Version.
 *
 * **Das ist ausdrücklich der Anfang und nicht die Suchsprache.** Die vollständige
 * Sprache mit `is:unresolved`, `browser:`, Klammern und Verneinung ist eine
 * eigene Aufgabe (S4). Was hier steht, ist der Teil, ohne den diese Aufgabe
 * unvollständig wäre: eine Version zu erfassen und dann nicht danach suchen zu
 * können, hieße, die Frage „was ist mit 1.1.0 dazugekommen?" bis S4 offen zu
 * lassen — und das ist die Frage, wegen der Versionen überhaupt erfasst werden.
 *
 * Die Schreibweise ist deshalb schon die endgültige (`schlüssel:wert`, Werte mit
 * Leerzeichen in Anführungszeichen), damit gespeicherte Links diese Aufgabe
 * überleben.
 *
 * **Unbekannte Begriffe werden nicht stillschweigend übergangen.** Wer
 * `is:unresolved` eintippt, bevor S4 fertig ist, bekommt sonst eine Liste, die
 * so aussieht, als hätte sie den Begriff ausgewertet. Sie werden gesammelt und
 * zurückgemeldet; die Oberfläche sagt, dass sie nicht gewirkt haben.
 */
final class IssueSearch
{
    /** „In dieser Version gesehen." */
    public const KEY_RELEASE = 'release';

    /** „In dieser Version zum ersten Mal gesehen." */
    public const KEY_FIRST_RELEASE = 'firstRelease';

    /**
     * @param  list<string>  $releases  Werte zu `release:`
     * @param  list<string>  $firstReleases  Werte zu `firstRelease:`
     * @param  list<string>  $unsupported  Begriffe, die (noch) nicht ausgewertet werden
     */
    private function __construct(
        public readonly array $releases,
        public readonly array $firstReleases,
        public readonly array $unsupported,
    ) {}

    /**
     * Zerlegt die Eingabe.
     *
     * Der Schlüssel wird ohne Rücksicht auf Groß- und Kleinschreibung erkannt
     * (`firstrelease:` wie `firstRelease:`), der **Wert** dagegen genau
     * genommen: Versionsangaben sind Bezeichner, und `1.0.0-RC1` ist nicht
     * `1.0.0-rc1`.
     */
    public static function parse(?string $input): self
    {
        $releases = [];
        $firstReleases = [];
        $unsupported = [];

        // Ein Begriff ist entweder `schlüssel:"wert mit Leerzeichen"` oder eine
        // Folge ohne Leerzeichen. Alles andere fällt als freier Text an — der
        // hat bis S4 keine Bedeutung und landet unter „nicht ausgewertet".
        preg_match_all('/(\S+?):"([^"]*)"|(\S+)/u', (string) $input, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (($match[1] ?? '') !== '') {
                $key = $match[1];
                $value = $match[2];
            } else {
                $term = $match[3] ?? '';
                $colon = strpos($term, ':');

                if ($colon === false || $colon === 0) {
                    $unsupported[] = $term;

                    continue;
                }

                $key = substr($term, 0, $colon);
                $value = substr($term, $colon + 1);
            }

            $value = Release::normalizeVersion($value);

            if ($value === null) {
                // `release:` ohne Wert. Kein Filter — und auch kein Hinweis auf
                // einen unbekannten Begriff: der Schlüssel stimmt ja.
                continue;
            }

            match (mb_strtolower($key)) {
                mb_strtolower(self::KEY_RELEASE) => $releases[] = $value,
                mb_strtolower(self::KEY_FIRST_RELEASE) => $firstReleases[] = $value,
                default => $unsupported[] = $key.':'.$value,
            };
        }

        return new self(
            array_values(array_unique($releases)),
            array_values(array_unique($firstReleases)),
            array_values(array_unique($unsupported)),
        );
    }

    public function isEmpty(): bool
    {
        return $this->releases === [] && $this->firstReleases === [];
    }

    /**
     * Schränkt die Abfrage auf die gesuchten Versionen ein.
     *
     * Mehrere Werte desselben Schlüssels sind ein **Oder** („1.0.0 oder 1.1.0"),
     * verschiedene Schlüssel ein **Und** — die Schreibweise, die man von einer
     * Suchleiste erwartet und die S4 beibehalten wird.
     *
     * `release:` fragt die **erste oder letzte** bekannte Version ab, und das
     * ist eine Auskunft mit Grenze: erfasst sind genau diese beiden. Ein Fehler,
     * der in 1.0.0 begann, in 1.1.0 weiterlief und in 1.2.0 zuletzt auftrat,
     * wird von `release:1.1.0` nicht gefunden. Die vollständige Antwort bräuchte
     * je Eintrag und Version eine Zeile — eine Tabelle in der Größenordnung der
     * Ereignisse, und die ist diese Auskunft nicht wert. Die Oberfläche sagt
     * deshalb „zuerst/zuletzt", nicht „alle".
     *
     * @param  Builder<Issue>  $query
     */
    public function apply(Builder $query): void
    {
        if ($this->firstReleases !== []) {
            $query->whereHas('firstRelease', function (Builder $release): void {
                $release->whereIn('version', $this->firstReleases);
            });
        }

        if ($this->releases !== []) {
            $query->where(function (Builder $any): void {
                $any
                    ->whereHas('firstRelease', function (Builder $release): void {
                        $release->whereIn('version', $this->releases);
                    })
                    ->orWhereHas('lastRelease', function (Builder $release): void {
                        $release->whereIn('version', $this->releases);
                    });
            });
        }
    }
}
