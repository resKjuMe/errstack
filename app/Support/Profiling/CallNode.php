<?php

namespace App\Support\Profiling;

/**
 * Ein Knoten des Aufrufbaums: eine Funktion, aufgerufen aus einem bestimmten
 * Weg heraus.
 *
 * Derselbe Rahmen kommt im Baum mehrfach vor — einmal je Weg, über den er
 * erreicht wurde. Genau das ist der Sinn eines Flamegraphs: `json_encode`
 * interessiert nicht als Summe, sondern in der Frage „wer ruft es so oft auf?".
 *
 * Die Klasse ist **veränderlich**, anders als fast alles andere in dieser
 * Anwendung. Der Grund ist der Aufbau: ein Baum entsteht, indem zehntausend
 * Stichproben nacheinander ihren Weg hineinzählen. Mit unveränderlichen Knoten
 * wäre jede Stichprobe eine Neuanlage des ganzen Weges von der Wurzel abwärts —
 * bei einer Tiefe von 40 Rahmen also 400.000 Objekte für ein einziges Profil.
 */
final class CallNode
{
    /**
     * Zeit, die in **dieser** Funktion selbst verbracht wurde — ohne das, was
     * sie ihrerseits aufgerufen hat. Die Zahl, die sagt: hier rechnet es.
     */
    public int $selfNs = 0;

    /**
     * Zeit von hier abwärts, einschließlich aller Aufgerufenen. Die Breite des
     * Balkens im Flamegraph.
     */
    public int $totalNs = 0;

    /**
     * Wie viele Stichproben diese Funktion als oberste im Stapel gesehen haben.
     * Steht neben der Zeit, weil beide verschiedene Fragen beantworten: die Zeit
     * sagt, wie viel es kostet, die Zahl sagt, wie sicher die Messung ist —
     * drei Stichproben sind ein Hinweis, dreihundert ein Befund.
     */
    public int $selfSamples = 0;

    /**
     * Die Aufgerufenen, nach dem Platz ihres Rahmens in der Rahmentabelle.
     *
     * Ein Feld-Baum statt einer Liste, weil beim Aufbau je Stichprobe und Ebene
     * nachgeschlagen wird, ob es den Zweig schon gibt. Über eine Liste wäre das
     * eine Suche je Ebene — bei zehntausend Stichproben der Unterschied zwischen
     * Millisekunden und Minuten.
     *
     * @var array<int, CallNode>
     */
    public array $children = [];

    public function __construct(
        public readonly int $frame,
    ) {}

    /**
     * Der Zweig unter diesem Knoten, der zu diesem Rahmen gehört — angelegt,
     * falls es ihn noch nicht gibt.
     */
    public function child(int $frame): self
    {
        return $this->children[$frame] ??= new self($frame);
    }
}
