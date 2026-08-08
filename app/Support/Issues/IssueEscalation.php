<?php

namespace App\Support\Issues;

/**
 * Die Feststellung, dass ein stummgeschalteter Fehler aus dem Ruder gelaufen
 * ist — und um wie viel.
 *
 * **Warum das nicht die Stummschaltung selbst erledigt.** Wer dauerhaft
 * stummschaltet, sagt „der bleibt, aber er soll mich nicht wecken"
 * ({@see IgnoreCondition}) — und meint dabei den Fehler, wie er **heute**
 * aussieht. Aus zehn Meldungen am Tag werden nach einer schlechten Auslieferung
 * zehntausend, und die Zusage von gestern deckt das nicht mehr. Eine Bedingung
 * dafür kann niemand vorher eintippen: sie hinge an einer Zahl, die man beim
 * Stummschalten noch gar nicht kennt.
 *
 * **Gemessen wird gegen den eigenen Verlauf, nicht gegen eine feste Zahl.** Was
 * „viel" ist, sagt kein absoluter Wert: für den einen Eintrag sind fünf
 * Meldungen in der Stunde eine Welle, für den anderen ist es Ruhe. Der
 * Erwartungswert ist deshalb der Durchschnitt der vergangenen Tage — inklusive
 * der stillen Stunden, denn Stille gehört zum Verlauf.
 *
 * **Ohne Datenbank**, wie {@see IgnoreCondition} und
 * {@see IssuePriorityScore}: zwei Zahlen hinein, ein Urteil heraus.
 */
final readonly class IssueEscalation
{
    /**
     * Um welchen Faktor die Stunde über dem Erwarteten liegen muss.
     *
     * Das Dreifache — nicht das Anderthalbfache: Fehlerzahlen schwanken über
     * den Tag ohnehin um ein Vielfaches (Nacht gegen Mittag), und eine Schwelle
     * innerhalb dieser Schwankung würde jeden Vormittag eine Eskalation melden.
     */
    public const FACTOR = 3.0;

    /**
     * Wie viele Meldungen in der Stunde mindestens zusammenkommen müssen.
     *
     * Ohne diese Untergrenze wäre der Sprung von einer auf vier Meldungen eine
     * Vervierfachung — rechnerisch richtig und als Meldung wertlos. Sie greift
     * zugleich dort, wo es gar keinen Erwartungswert gibt: ein Eintrag, der seit
     * Wochen still ist, hat den Durchschnitt null, und ohne Untergrenze wäre
     * jede einzelne Meldung eine Eskalation.
     */
    public const FLOOR = 10;

    public function __construct(
        /** Meldungen in der zuletzt vollständigen Stunde. */
        public int $observed,
        /** Der Durchschnitt je Stunde im Vergleichszeitraum. */
        public float $expected,
        /** Ab wo es eine Eskalation ist. */
        public float $threshold,
    ) {}

    /**
     * `null`, wenn alles im Rahmen ist — der Regelfall, und deshalb der
     * Rückgabewert, mit dem sich am kürzesten weiterarbeiten lässt.
     */
    public static function check(int $observed, float $expected): ?self
    {
        $threshold = max(self::FLOOR, $expected * self::FACTOR);

        return $observed >= $threshold ? new self($observed, $expected, $threshold) : null;
    }

    /**
     * Das Wievielfache des Erwarteten — für die Meldung und den Verlauf.
     *
     * Ohne Erwartungswert gibt es kein Vielfaches: dann steht dort `null` und
     * die Meldung nennt nur die Zahl selbst. „Unendlich mal mehr als nichts"
     * wäre keine Auskunft.
     */
    public function factor(): ?float
    {
        return $this->expected > 0 ? round($this->observed / $this->expected, 1) : null;
    }
}
