<?php

namespace App\Enums;

use App\Models\IngestPayload;
use App\Support\Ingest\EnvelopeIntake;

/**
 * Art einer angenommenen Meldung. Die Werte sind die Element-Typen der
 * Sentry-Envelope-Spezifikation — auch für den klassischen Store-Endpunkt, der
 * ausschließlich Fehler (`event`) liefert. Damit ist die Eingangsablage
 * dieselbe für alle Wege; der Envelope-Endpunkt füllt hier nur weitere Fälle,
 * ohne die Tabelle oder die Verarbeitung umzubauen.
 *
 * Die Zeichenketten sind nicht frei gewählt: sie stehen so im Kopf jedes
 * Envelope-Elements. Sie umzubenennen hieße, die Zuordnung von Hand pflegen zu
 * müssen — und beim nächsten neuen Typ zu vergessen.
 *
 * Was hier fehlt, ist bewusst offen gelassen: Sentry erweitert die Liste
 * laufend (`span`, `nel`, `statsd` …). Ein unbekannter Typ ist kein Fehler,
 * sondern wird gezählt und verworfen — siehe
 * {@see EnvelopeIntake}.
 */
enum IngestType: string
{
    /** Eine Fehlermeldung — was über `/store/` hereinkommt. */
    case Event = 'event';

    /** Eine Transaktion samt ihrer Einzelschritte (Spans). */
    case Transaction = 'transaction';

    /** Eine einzelne Sitzung für die Release-Gesundheit. */
    case Session = 'session';

    /** Mehrere Sitzungen gebündelt, wie sie Server-SDKs zusammenfassen. */
    case Sessions = 'sessions';

    /** Eine Datei zu einer Meldung: Screenshot, Logdatei, Speicherabbild. */
    case Attachment = 'attachment';

    /** Lebenszeichen eines überwachten Cronjobs. */
    case CheckIn = 'check_in';

    /** Kopfdaten einer Sitzungs-Aufzeichnung. */
    case ReplayEvent = 'replay_event';

    /** Die Aufzeichnung selbst — gepackte Bilddaten, kein JSON. */
    case ReplayRecording = 'replay_recording';

    /** Eine Laufzeitmessung: welche Code-Stellen Rechenzeit verbrauchen. */
    case Profile = 'profile';

    /** Was das SDK selbst verworfen hat, mit Grund und Anzahl. */
    case ClientReport = 'client_report';

    /** Die Beschreibung einer betroffenen Person zu einem Fehler. */
    case UserReport = 'user_report';

    /**
     * Dasselbe auf dem neueren Weg: heutige SDKs schicken eine Rückmeldung als
     * eigenes Element `feedback`, dessen Nutzdaten wie eine Meldung aussehen und
     * den Text unter `contexts.feedback` tragen. Zwei Typen für eine Sache, weil
     * beide Formen im Umlauf sind — auseinandergehalten werden sie nur beim
     * Lesen der Nutzdaten, danach ist es dieselbe Rückmeldung.
     */
    case Feedback = 'feedback';

    public function label(): string
    {
        return __('enums.ingest_type.'.$this->value);
    }

    /**
     * Ist das die Rückmeldung einer betroffenen Person (M6)?
     *
     * Die Frage stellen zwei Stellen: der Schritt, der sie ablegt, und das
     * Schwärzen, das sie in Ruhe lassen muss. Beide dieselbe Antwort geben zu
     * lassen ist billiger, als die Aufzählung an zwei Stellen zu pflegen — beim
     * nächsten Typ wird sonst eine der beiden vergessen.
     */
    public function isUserFeedback(): bool
    {
        return $this === self::UserReport || $this === self::Feedback;
    }

    /**
     * Zählt eine Meldung dieser Art gegen ein Kontingent des Projekts?
     *
     * Die Zusage stammt aus M6 und ist seit O1 keine Zusage mehr, sondern eine
     * Ableitung: **welches** Kontingent gilt, steht in
     * {@see QuotaCategory::forIngestType()}, und diese Frage ist nur die
     * kürzere Form derselben Auskunft. Zwei Aufzählungen nebeneinander wären
     * zwei, die beim nächsten Element-Typ auseinanderlaufen — und eine davon
     * entscheidet über Geld.
     *
     * Unverändert gilt, was der Grund der Frage war: eine Rückmeldung ist die
     * Beschreibung eines Menschen zu einem Ereignis, das bereits gezählt wurde.
     * Sie ein zweites Mal zu zählen hieße, das Nachfragen bei den Betroffenen zu
     * bepreisen. Ebenso wenig zählt eine Verworfen-Meldung des SDK: sie ist
     * keine Meldung, sondern eine Angabe über welche. Sitzungen zählen aus einem
     * dritten Grund nicht — sie sind die Rechengrundlage der
     * Release-Gesundheit, und ein Kontingent darauf machte eine Kennzahl
     * falsch, statt Daten zu sparen.
     */
    public function countsTowardEventQuota(): bool
    {
        return QuotaCategory::forIngestType($this) !== null;
    }

    /**
     * Trägt dieser Typ eine Meldung mit eigener `event_id` im Rumpf?
     *
     * Nur bei diesen dreien steht die Nummer im Element selbst und hat Vorrang
     * vor der aus dem Envelope-Kopf. Alle anderen erben sie — ein Anhang gehört
     * zu der Meldung, mit der er zusammen gesendet wurde, und weiß das selbst
     * nicht.
     */
    public function carriesOwnEventId(): bool
    {
        return match ($this) {
            self::Event, self::Transaction, self::ReplayEvent => true,
            default => false,
        };
    }

    /**
     * Sind die Nutzdaten Binärdaten statt JSON?
     *
     * Anhänge sind beliebige Dateien, eine Aufzeichnung ist ein gepackter
     * Datenstrom. Für beide gelten eigene Größengrenzen, und ihre Nutzdaten
     * sind kein JSON — {@see IngestPayload::bytes()} holt sie wieder heraus.
     */
    public function isBinary(): bool
    {
        return match ($this) {
            self::Attachment, self::ReplayRecording => true,
            default => false,
        };
    }
}
