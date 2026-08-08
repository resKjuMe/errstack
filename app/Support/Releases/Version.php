<?php

namespace App\Support\Releases;

/**
 * Eine Versionsangabe, so weit zerlegt, wie sie sich zerlegen lässt.
 *
 * Die Angabe selbst ist und bleibt eine Zeichenkette — das ist die Zusage
 * dieser Aufgabe. Was ein SDK als Version schickt, ist nicht verhandelbar: es
 * kann `1.4.2` sein, `v2.0.0-rc.1`, `mein-dienst@3.1.0`, ein Commit-Hash oder
 * der Zählerstand der Bauumgebung. Nichts davon wird umgeschrieben.
 *
 * Zerlegt wird trotzdem, denn ohne Ordnung ist eine Versionsliste unbrauchbar:
 * als Text sortiert steht `1.10.0` vor `1.9.0`, und damit ist „neueste zuerst"
 * die Unwahrheit. Was sich nicht zerlegen lässt, bekommt keine erfundene
 * Ordnung, sondern **keine** — dort entscheidet später die Zeit.
 *
 * Bewusst kein vollständiges SemVer: der Bauteil (`+abc123`) wird verworfen,
 * weil er nach der Spezifikation nicht zur Rangfolge gehört, und der Vorabteil
 * wird als Zeichenkette verglichen statt Feld für Feld. Der Unterschied
 * betrifft Fälle wie `1.0.0-rc.2` gegen `1.0.0-rc.10` — ein Preis, der eine
 * eigene Sortierspalte je Vorab-Feld nicht wert ist.
 */
final class Version
{
    /**
     * Die größte Zahl, die in eine Sortierspalte passt.
     *
     * Alles darüber ist keine Versionsnummer mehr, sondern ein Zeitstempel oder
     * ein Zählerstand, der zufällig aus Ziffern besteht. Solche Angaben bleiben
     * unzerlegt, statt die Spalte zum Überlaufen zu bringen.
     */
    private const MAX_PART = 999_999_999_999_999;

    private function __construct(
        public readonly ?int $major,
        public readonly ?int $minor,
        public readonly ?int $patch,
        public readonly ?string $prerelease,
    ) {}

    /**
     * Zerlegt eine Versionsangabe.
     *
     * Erkannt werden: ein vorangestelltes Paket (`mein-dienst@1.2.3`) — wie es
     * die neueren Sentry-SDKs vorschlagen —, ein führendes `v`, ein bis drei
     * Zahlen, ein Vorabteil hinter `-` und ein Bauteil hinter `+`.
     *
     * Fehlende Zahlen werden mit `0` aufgefüllt: `1.2` ist `1.2.0`, und zwei
     * Angaben, die dasselbe meinen, sollen auch gleich sortieren.
     */
    public static function parse(string $version): self
    {
        $candidate = trim($version);

        // Das Paket vor dem `@` gehört nicht zur Nummer. Von rechts getrennt,
        // damit ein Paketname, der selbst ein `@` trägt (`@meine-firma/web`),
        // nicht in der Mitte zerfällt.
        $at = strrpos($candidate, '@');

        if ($at !== false) {
            $candidate = substr($candidate, $at + 1);
        }

        // Der Bauteil zählt nach der Spezifikation nicht zur Rangfolge — zwei
        // Fassungen, die sich nur darin unterscheiden, sind gleich alt.
        $plus = strpos($candidate, '+');

        if ($plus !== false) {
            $candidate = substr($candidate, 0, $plus);
        }

        if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:-(.+))?$/', $candidate, $matches) !== 1) {
            return new self(null, null, null, null);
        }

        $major = self::part($matches[1]);
        $minor = self::part($matches[2] ?? '0');
        $patch = self::part($matches[3] ?? '0');

        if ($major === null || $minor === null || $patch === null) {
            return new self(null, null, null, null);
        }

        $prerelease = $matches[4] ?? '';

        return new self(
            $major,
            $minor,
            $patch,
            // Leer heißt hier „endgültige Fassung" und nicht „unbekannt": eine
            // Version ohne Vorabteil steht nach allen ihren Vorabversionen.
            $prerelease === '' ? null : mb_substr($prerelease, 0, 100),
        );
    }

    /**
     * Ob die Angabe eine Rangfolge hat.
     */
    public function isOrdered(): bool
    {
        return $this->major !== null;
    }

    /**
     * Die Sortierfelder, wie sie an der Version gespeichert werden.
     *
     * @return array{sort_major: int|null, sort_minor: int|null, sort_patch: int|null, sort_prerelease: string|null}
     */
    public function columns(): array
    {
        return [
            'sort_major' => $this->major,
            'sort_minor' => $this->minor,
            'sort_patch' => $this->patch,
            'sort_prerelease' => $this->prerelease,
        ];
    }

    /**
     * Eine Zahl aus der Angabe — oder `null`, wenn sie keine mehr ist.
     */
    private static function part(string $value): ?int
    {
        // Führende Nullen sind nach der Spezifikation nicht erlaubt; als
        // Sortierwert stören sie nicht, und eine Angabe deswegen abzulehnen
        // hieße, `01.02.03` ohne Ordnung zu lassen.
        if (! ctype_digit($value)) {
            return null;
        }

        // Erst der Längentest, dann die Umwandlung: eine 30-stellige Ziffer
        // würde als `int` still überlaufen und käme als Unsinn zurück.
        if (strlen(ltrim($value, '0')) > 15) {
            return null;
        }

        $number = (int) $value;

        return $number > self::MAX_PART ? null : $number;
    }
}
