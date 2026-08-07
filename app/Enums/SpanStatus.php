<?php

namespace App\Enums;

/**
 * Der Ausgang einer Transaktion oder eines Einzelschritts.
 *
 * Die Werte sind die Status-Namen der Sentry-Spezifikation (abgeleitet von den
 * gRPC-Status-Codes) — sie werden von den SDKs so geschickt und deshalb nicht
 * umbenannt.
 *
 * Abgelegt wird der Status trotzdem als **Text**, nicht als dieses Enum: Sentry
 * erweitert die Liste, und ein unbekannter Status darf nicht dazu führen, dass
 * eine Messung verloren geht. Das Enum ist der Weg, sie zu deuten, keine
 * Zulassungsliste.
 */
enum SpanStatus: string
{
    /** Erfolgreich abgeschlossen. */
    case Ok = 'ok';

    /** Vom Aufrufer abgebrochen — kein Fehler der überwachten Anwendung. */
    case Cancelled = 'cancelled';

    /** Ausgang unbekannt; das SDK konnte ihn nicht bestimmen. */
    case Unknown = 'unknown';

    case InvalidArgument = 'invalid_argument';

    case DeadlineExceeded = 'deadline_exceeded';

    case NotFound = 'not_found';

    case AlreadyExists = 'already_exists';

    case PermissionDenied = 'permission_denied';

    case ResourceExhausted = 'resource_exhausted';

    case FailedPrecondition = 'failed_precondition';

    case Aborted = 'aborted';

    case OutOfRange = 'out_of_range';

    case Unimplemented = 'unimplemented';

    case Internal = 'internal_error';

    case Unavailable = 'unavailable';

    case DataLoss = 'data_loss';

    case Unauthenticated = 'unauthenticated';

    /**
     * Zählt dieser Status als Fehlschlag für die Fehlerrate?
     *
     * Drei Ausgänge zählen nicht dazu, und das ist die eigentliche Aussage der
     * Methode: `ok` ist der Erfolg, `unknown` ist keine Aussage, und
     * `cancelled` hat der Aufrufer entschieden — wer den Browser-Tab schließt,
     * hat kein Problem der überwachten Anwendung verursacht. Würden diese
     * mitgezählt, sähe jede Seite mit vielen abgebrochenen Aufrufen (Suche mit
     * Vorschlägen, lange Downloads) dauerhaft kaputt aus.
     */
    public function isFailure(): bool
    {
        return match ($this) {
            self::Ok, self::Unknown, self::Cancelled => false,
            default => true,
        };
    }

    /**
     * Dasselbe für einen Status, wie er in der Datenbank steht.
     *
     * Ein Status, den wir nicht kennen, gilt **nicht** als Fehlschlag: die
     * Alternative wäre, dass ein neu eingeführter Sentry-Status die Fehlerrate
     * aller betroffenen Seiten auf 100 % springen lässt und einen Alarm auslöst,
     * dem nichts entspricht.
     */
    public static function isFailureValue(?string $status): bool
    {
        return self::tryFrom((string) $status)?->isFailure() ?? false;
    }
}
