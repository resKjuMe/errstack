<?php

namespace App\Http\Controllers\Ingest;

use App\Exceptions\IngestRejection;
use App\Http\Controllers\Controller;
use App\Support\Ingest\IngestAuth;
use App\Support\Ingest\IngestBody;
use App\Support\Ingest\IngestContext;
use App\Support\Ingest\IngestResponse;
use App\Support\Ingest\Security\SecurityIntake;
use App\Support\Ingest\Security\SecurityReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Die Berichte, die der Browser selbst schickt: `POST /api/{project}/security/`.
 *
 * Der einzige Aufnahme-Endpunkt ohne SDK auf der Gegenseite. Die überwachte
 * Anwendung setzt eine Kopfzeile —
 *
 *     Content-Security-Policy: default-src 'self'; report-uri https://errstack.example/api/1/security/?sentry_key=<public_key>
 *
 * — und von da an meldet der Browser jeden Verstoß von sich aus. Das ist der
 * Grund für zwei Eigenheiten, die sonst nirgends gelten:
 *
 *   • **Der Schlüssel steht im Abfrageteil.** In einer `report-uri` ist Platz
 *     für eine Adresse und für nichts sonst; Kopfzeilen kann dort niemand
 *     setzen. {@see IngestAuth} nimmt `?sentry_key=…` ohnehin an — dies ist der
 *     Weg, für den es die Möglichkeit gibt.
 *   • **Der Content-Type wird nicht geprüft.** Die Browser schicken
 *     `application/csp-report`, `application/expect-ct-report+json` oder
 *     schlicht `application/json`, je nach Hersteller und Fassung. Woran der
 *     Bericht erkannt wird, steht in seinem Rumpf
 *     ({@see SecurityReport::from()}).
 *
 * Was danach passiert, ist dasselbe wie bei jeder anderen Meldung: der Bericht
 * wird zu einem Ereignis und läuft durch die Verarbeitungskette. Er ist deshalb
 * in denselben Listen zu finden wie ein Absturz — mit derselben Suche, denselben
 * Filtern, denselben Alarmen.
 */
class SecurityController extends Controller
{
    public function store(Request $request, SecurityIntake $intake): JsonResponse
    {
        $payload = IngestBody::decode($request);

        $data = json_decode($payload, true);

        if (! is_array($data) || array_is_list($data)) {
            throw IngestRejection::unreadable('ingest.not_json');
        }

        $report = SecurityReport::from($data);

        if ($report === null) {
            // Abweisen und nicht ablegen: hier steht kein SDK dahinter, das
            // etwas wiederholen könnte, sondern ein Aufruf auf eine Adresse,
            // die genau eine Sorte Rumpf annimmt. Ein stilles „angenommen" wäre
            // die schlechtere Auskunft — die Berichte fehlten dann, ohne dass
            // jemand erführe, warum.
            throw IngestRejection::unreadable('ingest.security_unknown');
        }

        $intake->accept(
            IngestContext::key($request),
            $report,
            $this->headers($request),
        );

        // Immer dieselbe Antwort, auch wenn der Bericht als Rauschen verworfen
        // wurde. Die Gegenstelle ist ein Browser, der nichts damit anfangen
        // kann: er wertet die Antwort nicht aus, wiederholt nichts und zeigt
        // einen Fehlercode allenfalls in der Entwicklerkonsole der besuchten
        // Seite — also ausgerechnet dort, wo er den Betreiber der überwachten
        // Anwendung beunruhigen würde, ohne dass etwas zu tun wäre.
        return IngestResponse::securityAccepted();
    }

    /**
     * Die Kopfzeilen, die den Bericht einordnen helfen.
     *
     * Nur diese beiden, und beide bewusst: der User-Agent ist die einzige
     * Angabe darüber, welcher Browser den Verstoß gemeldet hat — daran hängen
     * die Eingangsfilter für Crawler und veraltete Browser. Die Herkunft sagt,
     * von welcher Seite aus gemeldet wurde. Alles Weitere mitzunehmen hieße,
     * Kopfzeilen zu speichern, nach denen niemand gefragt hat.
     *
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        // Über die Kopfzeilen-Sammlung und nicht über `header()`: die liefert je
        // nach Kopfzeile auch eine Liste, und hier ist genau ein Wert gemeint.
        $headers = [
            'User-Agent' => $request->headers->get('User-Agent'),
            'Referer' => $request->headers->get('Referer'),
        ];

        return array_filter($headers, static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
