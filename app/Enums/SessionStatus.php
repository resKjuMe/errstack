<?php

namespace App\Enums;

use App\Support\Releases\Health\SessionTally;

/**
 * Der Zustand einer Sitzung, wie ihn das SDK meldet.
 *
 * Die Werte sind die der Sentry-Sitzungen und nicht frei gewählt: sie stehen so
 * im Feld `status` eines `session`-Elements. Eine eigene Schreibweise hieße,
 * die Zuordnung von Hand zu pflegen — und beim nächsten SDK zu vergessen.
 *
 * **Vier Zustände, aber nur drei Sorten von schlechtem Ausgang.** Für die
 * Release-Gesundheit zählt nicht der Zustand selbst, sondern was er über die
 * Sitzung aussagt: lief sie durch, hatte sie unterwegs Fehler, ist sie
 * abgestürzt, oder ist sie einfach verschwunden. Die Übersetzung dorthin macht
 * {@see tally()} — an genau einer Stelle, weil sonst jede Auswertung ihre
 * eigene Vorstellung davon bekäme, was „errored" bedeutet.
 */
enum SessionStatus: string
{
    /** Die Sitzung läuft noch. Der Zustand, mit dem jede beginnt. */
    case Ok = 'ok';

    /** Die Sitzung ist regulär zu Ende gegangen. */
    case Exited = 'exited';

    /**
     * Die Sitzung ist zu Ende gegangen, unterwegs gab es Fehler.
     *
     * Manche SDKs melden das ausdrücklich, andere lassen den Zustand auf
     * `exited` und zählen stattdessen `errors` hoch. Beide Wege führen hier
     * zum selben Ergebnis ({@see tally()}) — täten sie das nicht, hinge die
     * Fehlerquote einer Version davon ab, welches SDK sie meldet.
     */
    case Errored = 'errored';

    /** Die Anwendung ist in dieser Sitzung abgestürzt. */
    case Crashed = 'crashed';

    /**
     * Die Sitzung ist weder regulär beendet noch abgestürzt — sie hört einfach
     * auf: hart beendet, vom Betriebssystem abgeräumt, Gerät ausgeschaltet.
     *
     * Getrennt von `crashed` geführt, weil die Antwort darauf eine andere ist:
     * ein Absturz ist ein Fehler mit Stacktrace, ein abgebrochener Lauf ist
     * eine Beobachtung ohne Schuldigen. Sie in die Crash-Free-Rate zu ziehen,
     * hieße jede App zu bestrafen, die man während der Fahrt schließt.
     */
    case Abnormal = 'abnormal';

    public function label(): string
    {
        return __('enums.session_status.'.$this->value);
    }

    /**
     * Der gemeldete Zustand, ersatzweise `ok`.
     *
     * Fehlt die Angabe oder steht dort etwas Unbekanntes, gilt die Sitzung als
     * laufend statt als verloren: ein SDK, das einen künftigen Zustand meldet,
     * soll seine Sitzung nicht in die Absturz-Zahlen schreiben.
     */
    public static function fromPayload(mixed $status): self
    {
        return is_string($status) ? self::tryFrom(strtolower(trim($status))) ?? self::Ok : self::Ok;
    }

    /**
     * Ist die Sitzung beendet?
     *
     * Nur ein beendeter Lauf sagt etwas über die Gesundheit einer Version aus.
     * Gezählt wird trotzdem auch die laufende — sie ist der Nenner, ohne den
     * es keine Quote gäbe.
     */
    public function isFinished(): bool
    {
        return $this !== self::Ok;
    }

    /**
     * Diese eine Sitzung als Strichliste.
     *
     * @param  int  $errors  Wie viele Fehler die Sitzung gemeldet hat. Sie
     *                       entscheiden dort, wo der Zustand allein nichts
     *                       sagt: eine regulär beendete Sitzung mit Fehlern ist
     *                       keine gesunde.
     */
    public function tally(int $errors = 0): SessionTally
    {
        return match ($this) {
            self::Crashed => new SessionTally(1, 0, 1, 0),
            self::Abnormal => new SessionTally(1, 0, 0, 1),
            self::Errored => new SessionTally(1, 1, 0, 0),
            default => new SessionTally(1, $errors > 0 ? 1 : 0, 0, 0),
        };
    }
}
