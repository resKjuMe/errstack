<?php

namespace App\Support\Performance\Trace;

use Carbon\CarbonImmutable;

/**
 * Ein Balken im Wasserfall: eine Transaktion, einer ihrer Einzelschritte — oder
 * eine Lücke, wo ein Schritt fehlt.
 *
 * Die drei Arten stehen absichtlich in **einem** Gegenstand und nicht in drei
 * Klassen. Der Wasserfall stellt sie gleich dar (Einrückung, Balken, Dauer), und
 * die Verschachtelung geht quer durch die Arten: unter einer Transaktion hängen
 * Schritte, unter einem Schritt hängt die Transaktion des nächsten Dienstes.
 * Drei Klassen hießen drei Fälle in jeder Schleife, die den Baum durchläuft.
 */
final class TraceNode
{
    /** Eine gemessene Transaktion — der Aufruf eines Dienstes. */
    public const KIND_TRANSACTION = 'transaction';

    /** Ein Einzelschritt innerhalb einer Transaktion. */
    public const KIND_SPAN = 'span';

    /**
     * Ein Schritt, auf den verwiesen wird, den wir aber nicht haben.
     *
     * Er entsteht, wenn ein Knoten einen Elternteil nennt, der in dieser Spur
     * nicht vorkommt: der übergeordnete Dienst ist nicht angebunden, seine
     * Meldung ist noch unterwegs, oder sie wurde von der Stichprobe verworfen.
     * Der Knoten wird dann **erfunden** und als Lücke gezeigt — die Alternative
     * wäre, die Kinder an die Wurzel zu hängen und damit zu behaupten, sie
     * hätten keinen Elternteil.
     */
    public const KIND_MISSING = 'missing';

    /** Längster angezeigter Beschreibungstext in der Liste. */
    public const LABEL_LIMIT = 200;

    /** @var list<TraceNode> */
    public array $children = [];

    /** @var list<TraceError> */
    public array $errors = [];

    /**
     * Anfang, Ende und Dauer sind veränderbar, weil eine Lücke sie nicht
     * mitbringt: sie hat keine eigene Messung und bekommt die Spanne ihrer
     * Kinder, sobald der Baum steht ({@see TraceView}).
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $spanId,
        public readonly ?string $parentSpanId,
        public readonly ?string $label,
        public readonly ?string $op,
        public readonly ?string $status,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $finishedAt = null,
        public int $durationUs = 0,
        public readonly ?string $projectName = null,
        public readonly ?string $projectSlug = null,
        public readonly ?int $transactionId = null,
    ) {}

    /**
     * Die Form, in der die Oberfläche eine Zeile bekommt.
     *
     * Ohne Beschreibungstext in voller Länge und ohne die Zusatzangaben des
     * SDK: bei einer Spur mit zehntausend Schritten wären das Megabyte, von
     * denen ein Betrachter eine Handvoll ansieht. Beides kommt beim Anklicken
     * nach ({@see TraceDetail}).
     *
     * @return array<string, mixed>
     */
    public function toArray(int $depth, int $traceStartUs): array
    {
        return [
            'key' => $this->key(),
            'kind' => $this->kind,
            'spanId' => $this->spanId,
            'depth' => $depth,
            'childCount' => count($this->children),
            'label' => $this->label === null ? null : mb_strimwidth($this->label, 0, self::LABEL_LIMIT, '…'),
            'op' => $this->op,
            'status' => $this->status,
            'project' => $this->projectName,
            'transactionId' => $this->transactionId,
            // Beides in Mikrosekunden: der Versatz ist der Abstand zum Anfang
            // der Spur, die Dauer die Breite des Balkens. Ausgerechnet wird
            // daraus erst in der Oberfläche — sie kennt die Breite des
            // Fensters, der Server nicht.
            'offsetUs' => $this->startedAt === null ? 0 : max(0, self::micros($this->startedAt) - $traceStartUs),
            'durationUs' => $this->durationUs,
            'startedAt' => $this->startedAt?->toIso8601String(),
            'errors' => array_map(fn (TraceError $error): array => $error->toArray(), $this->errors),
        ];
    }

    /**
     * Die Kennung dieser Zeile für die Oberfläche.
     *
     * Nicht die Span-Kennung allein: die einer Transaktion ist zugleich die
     * ihres eigenen Balkens, und ein SDK darf dieselbe Kennung in einem zweiten
     * Dienst wiederverwenden, ohne dass die Anzeige zusammenbricht.
     */
    public function key(): string
    {
        return $this->kind.':'.($this->transactionId ?? '-').':'.($this->spanId ?? '-');
    }

    /**
     * Der Zeitpunkt als ganze Mikrosekunden.
     *
     * `getPreciseTimestamp(6)` liefert bei Zeitpunkten jenseits von 2038 einen
     * Gleitkommawert; die Umwandlung in `int` ist deshalb keine Formsache,
     * sondern der Unterschied zwischen einer Zahl und einer Zahl mit Rest.
     */
    public static function micros(CarbonImmutable $at): int
    {
        return (int) $at->getPreciseTimestamp(6);
    }
}
