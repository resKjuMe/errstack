<?php

namespace App\Enums;

use App\Models\Transaction;
use App\Support\Performance\Vitals\VitalHistogram;

/**
 * Die Messwerte, die das Ladeerlebnis im Browser beschreiben — die „Web
 * Vitals".
 *
 * Eine Aufzählung und keine freie Zeichenkette, obwohl die Messwerte als
 * beliebiger Feld-Baum ankommen ({@see Transaction::$measurements}).
 * Der Grund ist die **Bewertung**: erst eine Schwelle macht aus „2,8 Sekunden"
 * die Auskunft „zu langsam", und eine Schwelle gibt es nur für die Messwerte,
 * für die sie festgelegt ist. Alles andere, was ein SDK mitschickt
 * (`frames_slow`, `stall_count`, eigene Zahlen), bleibt am Einzelfall stehen
 * und läuft nicht in die Auswertung — eine erfundene Schwelle wäre schlimmer
 * als keine.
 *
 * **Die Schwellen stammen aus der Web-Vitals-Spezifikation und sind hier keine
 * Meinung.** Sie sind über Millionen echter Ladevorgänge festgelegt worden, und
 * genau das ist ihr Wert: eine Zahl, die sich mit anderen Anwendungen
 * vergleichen lässt. Eigene Schwellen wären für dieses eine Projekt vielleicht
 * passender und für den Vergleich wertlos.
 *
 * **Alle Werte werden in derselben Einheit geführt: Millionstel.** Für die
 * Dauern ist das die Mikrosekunde, für den einheitenlosen Verschiebungswert
 * (CLS) das Millionstel seiner Punktzahl. Eine gemeinsame Einheit ist die
 * Voraussetzung dafür, dass eine einzige Verteilung
 * ({@see VitalHistogram}) und eine einzige Tabellenspalte für alle Messwerte
 * reichen — sonst bräuchte jeder Messwert seine eigene Ablage.
 *
 * Die Reihenfolge der Fälle ist die Reihenfolge der Anzeige: erst die drei
 * Kernwerte (LCP, INP, CLS), dann die ergänzenden.
 */
enum WebVital: string
{
    /** Largest Contentful Paint — wann der größte sichtbare Inhalt stand. */
    case Lcp = 'lcp';

    /** Interaction to Next Paint — wie träge die Seite auf Eingaben reagiert. */
    case Inp = 'inp';

    /** Cumulative Layout Shift — wie stark der Inhalt beim Laden springt. */
    case Cls = 'cls';

    /** First Contentful Paint — wann überhaupt etwas zu sehen war. */
    case Fcp = 'fcp';

    /** Time to First Byte — wann die erste Antwort des Servers ankam. */
    case Ttfb = 'ttfb';

    /** First Input Delay — der Vorgänger von INP, den ältere SDKs noch melden. */
    case Fid = 'fid';

    /**
     * Das Perzentil, an dem ein Messwert bewertet wird.
     *
     * 75 % und nicht der Mittelwert oder das p95, weil die Spezifikation es so
     * festlegt: der Mittelwert verschwiegt die langsame Hälfte, das p95 hinge an
     * den wenigen Geräten, die immer langsam sind. Das p75 ist die Zusage „drei
     * von vier Besuchern haben es mindestens so gut erlebt".
     *
     * Die Zahl steht hier und nicht an der Auswertung: sie ist Teil der
     * Definition eines Web Vitals, nicht eine Einstellung der Anzeige.
     */
    public const PERCENTILE = 0.75;

    /**
     * Die drei Kernwerte.
     *
     * Sie stehen für die drei Dinge, die ein Besucher tatsächlich merkt: wie
     * lange er auf Inhalt wartet (LCP), wie träge die Seite auf ihn reagiert
     * (INP) und wie sehr sie ihm unter der Hand wegspringt (CLS). Die übrigen
     * erklären diese drei — TTFB sagt, ob es am Server liegt, FCP, ob überhaupt
     * schon etwas da war.
     *
     * @return list<self>
     */
    public static function core(): array
    {
        return [self::Lcp, self::Inp, self::Cls];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $vital): string => $vital->value, self::cases());
    }

    /**
     * Der Messwert zu einem Namen aus der Meldung, oder `null`.
     *
     * Nachsichtig gegenüber den Schreibweisen, die tatsächlich ankommen: die
     * SDKs führen die Werte klein geschrieben, manche Weiterleitung stellt
     * `measurements.` voran. Alles andere ist kein Web Vital — und genau das
     * ist die Aussage von `null`, nicht „unbekannt, nehmen wir mal an".
     */
    public static function fromMeasurement(string $name): ?self
    {
        $name = strtolower(trim($name));

        if (str_starts_with($name, 'measurements.')) {
            $name = substr($name, strlen('measurements.'));
        }

        return self::tryFrom($name);
    }

    public function label(): string
    {
        return __('enums.web_vital.'.$this->value);
    }

    /**
     * Der ausgeschriebene Name — die Beschriftung ist die Abkürzung, und wer
     * „INP" nicht kennt, ist mit ihr nicht bedient.
     */
    public function description(): string
    {
        return __('enums.web_vital_description.'.$this->value);
    }

    /**
     * Ist der Wert eine Punktzahl statt einer Dauer?
     *
     * Nur der Verschiebungswert (CLS) ist eine: er zählt, wie weit der Inhalt
     * beim Laden gesprungen ist, und hat keine Einheit. Die Unterscheidung
     * entscheidet über die Anzeige („0,12" statt „120 ms") und darüber, wie eine
     * gemeldete Zahl in die gemeinsame Einheit gerechnet wird.
     */
    public function isScore(): bool
    {
        return $this === self::Cls;
    }

    /**
     * Bis zu diesem Wert (einschließlich) gilt der Messwert als gut.
     *
     * In Millionsteln: 2.500 ms sind 2_500_000, ein CLS von 0,1 ebenso.
     */
    public function goodMax(): int
    {
        return match ($this) {
            self::Lcp => 2_500_000,
            self::Inp => 200_000,
            self::Cls => 100_000,
            self::Fcp => 1_800_000,
            self::Ttfb => 800_000,
            self::Fid => 100_000,
        };
    }

    /**
     * Ab über diesem Wert gilt der Messwert als schlecht; dazwischen liegt
     * „mäßig".
     */
    public function poorMin(): int
    {
        return match ($this) {
            self::Lcp => 4_000_000,
            self::Inp => 500_000,
            self::Cls => 250_000,
            self::Fcp => 3_000_000,
            self::Ttfb => 1_800_000,
            self::Fid => 300_000,
        };
    }

    /**
     * Die Bewertung eines einzelnen gemessenen Werts.
     *
     * Die Grenzen sind bewusst asymmetrisch gelesen — „gut" schließt seine
     * Obergrenze ein, „schlecht" beginnt erst **über** der zweiten. So gilt ein
     * LCP von genau 2.500 ms als gut und einer von genau 4.000 ms noch als
     * mäßig, wie es die Spezifikation vorsieht.
     */
    public function rate(int $value): VitalRating
    {
        if ($value <= $this->goodMax()) {
            return VitalRating::Good;
        }

        return $value <= $this->poorMin()
            ? VitalRating::NeedsImprovement
            : VitalRating::Poor;
    }
}
