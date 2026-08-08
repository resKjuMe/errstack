<?php

namespace App\Support\Ingest\Sampling;

use App\Models\IngestPayload;
use App\Models\SamplingRule;
use App\Models\Transaction;
use App\Support\Performance\PayloadReader;
use App\Support\Performance\TransactionEvent;

/**
 * Die Angaben einer gemeldeten Transaktion, an denen sich die Stichprobe
 * entscheidet.
 *
 * Bewusst nicht {@see TransactionEvent}: der liest die ganze Meldung samt
 * Einzelschritten und Messwerten, und er wird erst **nach** der Stichprobe
 * gebraucht. Genau das ist der Sinn der Reihenfolge — was ohnehin wegfällt, soll
 * nicht vorher noch ausgewertet werden. Hier stehen vier Zeichenketten und eine
 * Zahl; das ist alles, was eine Regel prüft.
 *
 * Gelesen wird mit demselben Werkzeug wie dort ({@see PayloadReader}) und auf
 * dieselben Längen gekürzt: eine Regel muss gegen den Wert vergleichen, der
 * später in der Spalte steht, nicht gegen den ungekürzten aus der Meldung.
 * Andernfalls würde eine Regel für einen sehr langen Namen nie greifen.
 */
final class SampleTarget
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $environment,
        public readonly ?string $release,
        public readonly ?string $op,
        /**
         * Der Anteil, den das SDK vor dem Senden behalten hat — `null`, wenn es
         * nichts dazu gesagt hat. Für die Regeln ohne Bedeutung, für die
         * Hochrechnung entscheidend.
         */
        public readonly ?float $clientSampleRate,
    ) {}

    /**
     * @param  array<mixed>  $data  Der Rumpf, wie {@see IngestPayload::decoded()} ihn liefert.
     */
    public static function fromPayload(array $data): self
    {
        $contexts = PayloadReader::map($data['contexts'] ?? null) ?? [];
        $trace = PayloadReader::map($contexts['trace'] ?? null) ?? [];

        return new self(
            // Derselbe Ersatzname wie in der Ablage. Eine Regel soll sich auf
            // unbenannte Aufrufe beziehen können — sie sind oft genau die, von
            // denen es zu viele gibt.
            name: PayloadReader::text($data['transaction'] ?? null, Transaction::NAME_LIMIT) ?? TransactionEvent::UNNAMED,
            environment: PayloadReader::text($data['environment'] ?? null, 64),
            release: PayloadReader::text($data['release'] ?? null, 255),
            op: PayloadReader::text($trace['op'] ?? null, Transaction::OP_LIMIT),
            clientSampleRate: ClientSampleRate::fromPayload($data),
        );
    }

    /**
     * Der Vorgang, dessen Häufigkeit für die Mindestquote gezählt wird.
     *
     * Name und Umgebung, nicht die Regel: selten ist ein Vorgang. Eine Regel
     * kann über einen Platzhalter tausend Namen erfassen, und würde je Regel
     * gezählt, wäre die Zusage „mindestens einer je Fenster" für die tausend
     * zusammen gemeint — der eine seltene Import wäre dann genauso verloren wie
     * ohne Mindestquote.
     *
     * Die Umgebung gehört dazu, weil derselbe Endpunkt in der Entwicklung selten
     * und im Betrieb häufig ist. Die Version gehört **nicht** dazu: sonst
     * bekäme jede Auslieferung ihr eigenes Kontingent, und bei häufigen
     * Auslieferungen wäre die Mindestquote keine Untergrenze mehr, sondern eine
     * zweite Quelle von Messungen.
     *
     * @see SamplingRule::$minimum_per_window
     */
    public function group(): string
    {
        return ($this->environment ?? '').'|'.$this->name;
    }
}
