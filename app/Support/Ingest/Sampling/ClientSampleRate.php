<?php

namespace App\Support\Ingest\Sampling;

/**
 * Liest die Quote, mit der das SDK bereits ausgesiebt hat.
 *
 * Warum das überhaupt nötig ist: `traces_sample_rate` ist die erste Einstellung,
 * die jemand anfasst, der ein SDK in Betrieb nimmt — und sie wirkt vor uns. Steht
 * sie auf 0,1, kommt jeder zehnte Aufruf an, und eine Übersicht, die die
 * angekommenen zählt, weist ein Zehntel des Verkehrs aus. Der Fehler ist dabei
 * nicht zu sehen: an den gespeicherten Messungen fehlt nichts, sie sind nur zu
 * wenige.
 *
 * **Wo die Angabe steht, ist von der SDK-Fassung abhängig.** Die neueren
 * schreiben sie in die Zusatzangaben des Trace-Kontexts
 * (`contexts.trace.data['sentry.sample_rate']`), ältere unmittelbar in den
 * Trace-Kontext, manche in den Kopf der Meldung. Der Name wechselt zwischen
 * `sample_rate` und `traces_sample_rate`. Gesucht wird deshalb an allen
 * bekannten Stellen unter beiden Namen, und der erste brauchbare Wert gilt.
 * Das ist keine Nachlässigkeit: die Alternative wäre, für jede SDK-Fassung eine
 * eigene Fassung dieser Klasse zu haben.
 *
 * **Was hier fehlt und fehlen muss:** Der Envelope-Kopf trägt bei aktuellen SDKs
 * denselben Wert im „Dynamic Sampling Context". Der Kopf wird nicht abgelegt —
 * die Eingangsablage hält den Kopf des **Elements**, nicht den des Envelopes —
 * und steht der Verarbeitung damit nicht zur Verfügung. Ihn mitzuschreiben ist
 * eine eigene Änderung an der Annahme; solange sie fehlt, bleibt die Angabe aus
 * dem Rumpf die Quelle. Sie ist bei den SDKs, die überhaupt eine Quote melden,
 * die häufigere.
 */
final class ClientSampleRate
{
    /**
     * Die Namen, unter denen die Quote auftreten kann.
     *
     * @var list<string>
     */
    private const KEYS = ['sentry.sample_rate', 'sample_rate', 'traces_sample_rate'];

    /**
     * @param  array<mixed>  $data  Der Rumpf der Meldung.
     * @return float|null Ein Anteil größer 0 und höchstens 1 — sonst `null`.
     */
    public static function fromPayload(array $data): ?float
    {
        $contexts = is_array($data['contexts'] ?? null) ? $data['contexts'] : [];
        $trace = is_array($contexts['trace'] ?? null) ? $contexts['trace'] : [];
        $traceData = is_array($trace['data'] ?? null) ? $trace['data'] : [];
        $sdk = is_array($data['sdk'] ?? null) ? $data['sdk'] : [];

        // Von innen nach außen: die genaueste Angabe steht bei den Zusatzangaben
        // des Traces, die allgemeinste in den Einstellungen des SDK. Eine
        // Angabe am einzelnen Aufruf soll die allgemeine überstimmen — sie ist
        // die, mit der dieser Aufruf tatsächlich ausgesiebt wurde.
        foreach ([$traceData, $trace, $data, $sdk] as $source) {
            foreach (self::KEYS as $key) {
                $rate = self::rate($source[$key] ?? null);

                if ($rate !== null) {
                    return $rate;
                }
            }
        }

        return null;
    }

    /**
     * Ein Anteil, oder `null`.
     *
     * Eine Quote von 0 wird verworfen und nicht als „nichts behalten" gedeutet:
     * bei 0 wäre die Meldung nie gesendet worden, also ist die Angabe falsch —
     * und als Gewicht ergäbe sie eine Division durch Null. Größer als 1 ist
     * ebenso unmöglich; wer 1,5 meldet, meint nicht 150 %, sondern hat sich
     * verrechnet.
     *
     * Zeichenketten werden angenommen, weil der Dynamic Sampling Context die
     * Quote als Text führt und manche SDKs sie so auch in den Rumpf schreiben.
     */
    private static function rate(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);

            if ($value === '' || ! is_numeric($value)) {
                return null;
            }

            $value = (float) $value;
        }

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $rate = (float) $value;

        if (! is_finite($rate) || $rate <= 0.0 || $rate > 1.0) {
            return null;
        }

        return $rate;
    }
}
