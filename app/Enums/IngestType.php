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
     * Zählt eine Meldung dieser Art als Ereignis — als eine der Meldungen also,
     * deren Menge das Aufkommen eines Projekts ausmacht?
     *
     * Die Frage stellen zwei Stellen mit zwei verschiedenen Absichten, und
     * beide bekommen dieselbe Antwort: der Ausschlag-Schutz (A7) entscheidet
     * daran, was er bei einer Flut wegdrosselt, und die Rückmeldungen (M6)
     * begründen daran, warum sie nichts kosten. Eine Rückmeldung ist die
     * Beschreibung eines Menschen zu einem Ereignis, das bereits gezählt wurde;
     * sie ein zweites Mal zu zählen hieße, das Nachfragen bei den Betroffenen zu
     * bepreisen. Ebenso wenig zählen die Buchhaltungs-Elemente: ein
     * Lebenszeichen, eine Verworfen-Meldung des SDK und ein Anhang sind keine
     * Ereignisse, sondern Angaben über welche.
     *
     * **Nicht zu verwechseln mit den Kontingenten (O1).** Die begrenzen je
     * Datenart und fragen deshalb etwas anderes: gegen *welches* Kontingent
     * zählt dieser Typ ({@see QuotaCategory::forIngestType()})? Dort zählen ein
     * Anhang und ein Lebenszeichen sehr wohl — sie haben ein eigenes
     * Kontingent —, und eine Sitzung zählt nicht, weil ein Kontingent auf sie
     * die Release-Gesundheit verfälschen würde statt Daten zu sparen. Die
     * beiden Aufzählungen sind deshalb absichtlich verschieden und keine
     * Doppelung.
     */
    public function countsTowardEventQuota(): bool
    {
        return match ($this) {
            self::Event, self::Transaction, self::Session, self::Sessions,
            self::ReplayEvent, self::ReplayRecording, self::Profile => true,
            default => false,
        };
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
