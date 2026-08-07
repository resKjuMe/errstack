<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\IngestType;
use App\Models\Event;
use App\Support\Ingest\Normalization\EventNormalizer;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Macht aus dem Feld-Baum einer Meldung den einheitlichen Datensatz und legt
 * ihn ab.
 *
 * Der Schritt, ab dem die Herkunft keine Rolle mehr spielt: davor ist eine
 * Meldung das, was ein bestimmtes SDK in einer bestimmten Fassung für richtig
 * hielt, danach ist sie ein Datensatz mit festen Feldern. Alles Weitere —
 * Gruppierung (I5), Fortschreibung (I6), Suche, Anzeige — setzt darauf auf und
 * muss deshalb nie wieder wissen, wie ein SDK etwas schreibt.
 *
 * **Was hier nicht aussortiert wird:** nichts. Eine Meldung, von der nur der
 * Meldungstext lesbar ist, wird als Meldung mit Meldungstext abgelegt — mit
 * einem Vermerk, was verworfen wurde. Der Grund ist der Zweck des Werkzeugs:
 * die kaputtesten Meldungen kommen aus den kaputtesten Anwendungen, und das
 * sind genau die, für die jemand hier nachsieht. Ausgesondert wird nur, was gar
 * keine Nutzdaten hat.
 *
 * **Was hier nicht passiert:** Entfernen personenbezogener Daten. Das ist das
 * Scrubbing (I7) und steht in der Kette **davor** — was hier ankommt, darf
 * gespeichert werden.
 */
final class NormalizeEvent implements ProcessingStep
{
    /**
     * Der Name, unter dem der Datensatz für die folgenden Schritte bereitliegt.
     *
     * Eine Zeichenkette an einer Stelle statt in fünf Schritten verstreut: I5
     * und I6 holen ihn hier ab, und ein Tippfehler im Namen wäre sonst ein
     * Schritt, der still nichts tut.
     */
    public const RESULT = 'event';

    /**
     * Meldungsarten, die dieser Schritt auswertet.
     *
     * Nur Fehler und Nachrichten, denn nur für die gilt das Schema, das hier
     * gelesen wird. Transaktionen kommen in P5 dazu — sie haben eigene
     * Abschnitte (Spans, Messwerte) und gehören deshalb in einen eigenen
     * Schritt, nicht in ein Sonderfach hier. Sitzungen, Lebenszeichen,
     * Anhänge und Aufzeichnungen haben mit dem Ereignis-Schema von vornherein
     * nichts zu tun.
     *
     * @var list<IngestType>
     */
    private const HANDLED = [IngestType::Event];

    public function handle(ProcessingContext $context, Closure $next): void
    {
        if (! in_array($context->payload->type, self::HANDLED, true)) {
            // Kein Fall für diesen Schritt. Durchreichen und **nicht**
            // aussortieren: ein Anhang ist kein Fehler, er gehört nur einem
            // anderen Schritt.
            $next($context);

            return;
        }

        $data = $context->data;

        if ($data === null) {
            // Ohne Feld-Baum gibt es nichts zu normalisieren. Das Entpacken
            // sondert unlesbare Nutzdaten bereits aus; hier steht der Fall
            // trotzdem, weil ein späterer Schritt vor diesem stehen könnte,
            // der `data` leert — und der Absturz käme dann in diesem Schritt
            // an, ohne dass er etwas damit zu tun hätte.
            $next($context);

            return;
        }

        $normalized = EventNormalizer::make()->normalize(
            $data,
            $context->payload->event_id,
            $context->payload->created_at,
        );

        // Erst ablegen, dann weiterreichen: die folgenden Schritte schreiben an
        // diesem Datensatz weiter (I5 die Gruppe, I6 die Zähler), und sie
        // sollen ihn vorfinden und nicht selbst anlegen müssen.
        $record = Event::store($context->payload, $normalized);

        $context->with(self::RESULT, $normalized);
        $context->with(self::RESULT.'_record', $record);

        $next($context);
    }
}
