<?php

namespace App\Enums;

use App\Jobs\ProcessIngestPayload;
use App\Models\IngestPayload;

/**
 * Wie weit eine angenommene Meldung in der Verarbeitung ist.
 *
 * Der Zustand steht an der Meldung selbst und nicht in der Warteschlange: die
 * Warteschlange vergisst einen Job, sobald er durch ist, und in der
 * Fehlerablage steht anschließend nur ein serialisierter Job, keine Meldung.
 * Die Fragen, die im Betrieb wirklich gestellt werden — „wie viel liegt noch
 * an?", „was ist liegengeblieben?", „lässt sich das nochmal laufen lassen?" —
 * sind nur über die Meldung zu beantworten.
 *
 * @see ProcessIngestPayload für die Übergänge, {@see IngestPayload} für die Spalten.
 */
enum ProcessingState: string
{
    /** Angenommen, aber noch nicht ausgewertet — der Rückstand. */
    case Pending = 'pending';

    /** Ausgewertet; die Verarbeitungskette lief vollständig durch. */
    case Processed = 'processed';

    /**
     * Dieselbe Meldung war schon da. Kein Fehler, sondern der Normalfall bei
     * einer Zustellung, die ein SDK wiederholt hat, weil unsere Antwort nicht
     * ankam.
     */
    case Duplicate = 'duplicate';

    /**
     * Ein Schritt hat die Meldung bewusst aussortiert — unlesbar, weggefiltert
     * (I8) oder nicht in die Stichprobe gefallen (I9). Die Rohdaten bleiben
     * liegen; verworfen ist nur das Ergebnis.
     */
    case Dropped = 'dropped';

    /**
     * Alle Versuche sind gescheitert. Die Rohdaten liegen unverändert da und
     * lassen sich mit `ingest:retry` erneut durchlaufen.
     */
    case Failed = 'failed';

    public function label(): string
    {
        return __('enums.processing_state.'.$this->value);
    }

    /**
     * Wartet diese Meldung noch auf ihre Auswertung?
     *
     * Das ist die Frage hinter dem Rückstand — und zugleich die Bedingung, unter
     * der ein Job überhaupt arbeiten darf: alles andere hat sein Ergebnis
     * bereits, und ein zweiter Durchlauf würde es nur überschreiben.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
