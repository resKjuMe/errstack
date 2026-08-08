<?php

namespace App\Support\Performance\Detection;

use App\Enums\GroupingSource;
use App\Enums\PerformanceProblem;
use App\Support\Ingest\Grouping\Component;
use App\Support\Ingest\Grouping\Fingerprint;

/**
 * Was ein Erkenner gefunden hat, bevor daraus ein Eintrag wird.
 *
 * Die Trennung ist Absicht: ein Erkenner sieht sich einen Ablauf an und sagt,
 * was ihm auffällt — er legt nichts an, zählt nichts hoch und weiß nichts von
 * Einträgen, Gruppen oder Fingerabdrücken. Das macht ihn prüfbar, ohne dass ein
 * einziger Datensatz entsteht: ein Test gibt Schritte hinein und vergleicht die
 * Funde.
 *
 * Zusammengesetzt wird daraus in {@see PerformanceIssues}.
 */
final class Finding
{
    /**
     * @param  PerformanceProblem  $problem  Das erkannte Muster.
     * @param  string  $subject  Der Gegenstand — die Abfrageform, die Adresse, der Dateiname.
     *                           Er geht in den Fingerabdruck ein und entscheidet damit, was als
     *                           dasselbe Problem gilt.
     * @param  string  $description  Der Beleg im Klartext: die tatsächliche Abfrage, die
     *                               tatsächliche Adresse. Anders als der Gegenstand nur zur
     *                               Anzeige, nie zum Vergleich.
     * @param  list<string>  $spanIds  Die betroffenen Schritte.
     * @param  int  $timeLostUs  Die vermeidbare Zeit in Mikrosekunden.
     * @param  array<string, mixed>  $evidence  Was die Detailansicht sonst noch erklärt.
     */
    public function __construct(
        public readonly PerformanceProblem $problem,
        public readonly string $subject,
        public readonly string $description,
        public readonly array $spanIds,
        public readonly int $timeLostUs,
        public readonly array $evidence = [],
    ) {}

    /**
     * Der Fingerabdruck dieses Fundes.
     *
     * Drei Bestandteile, und jeder ist begründet:
     *
     *   - **das Muster**, weil eine doppelte Abfrage und ein N+1 auf derselben
     *     Abfrage zwei verschiedene Sachen sind, die verschieden behoben werden;
     *   - **der Ablauf**, weil dieselbe langsame Abfrage in der Listenansicht
     *     und im Export zwei Baustellen sind — die eine kann dringend sein, die
     *     andere nie;
     *   - **der Gegenstand**, weil er sagt, *was* langsam ist.
     *
     * Was **nicht** hineingehört, ist ebenso wichtig: keine Kennung des
     * einzelnen Ablaufs, keine Zeit, keine Umgebung. Sonst wäre jeder Fund sein
     * eigener Eintrag, und aus der Erkennung würde ein Protokoll — genau das,
     * was die Gruppierung verhindern soll.
     */
    public function fingerprint(string $transactionName): Fingerprint
    {
        $components = [
            new Component('problem', $this->problem->value),
            new Component('transaction', $transactionName),
            new Component('subject', $this->subject),
        ];

        return Fingerprint::of(
            source: GroupingSource::Performance,
            values: array_map(static fn (Component $c): string => $c->signature(), $components),
            components: $components,
        );
    }
}
