<?php

namespace App\Support\Ingest\Security;

use App\Enums\SecurityReportType;
use App\Support\Ingest\Normalization\EventNormalizer;
use Illuminate\Support\Carbon;

/**
 * Ein Sicherheitsbericht des Browsers, in der Form, in der die Aufnahme ihn
 * weiterverarbeiten kann.
 *
 * Der Bericht kommt ohne SDK: die überwachte Anwendung setzt eine Kopfzeile
 * (`Content-Security-Policy: …; report-uri https://errstack.example/api/1/security/?sentry_key=…`),
 * der Browser stellt einen Verstoß fest und schickt ihn an diese Adresse. Was
 * dort ankommt, hat mit dem Sentry-Schema nichts zu tun — es ist das Format der
 * jeweiligen Browser-Spezifikation.
 *
 * **Hier wird daraus ein gewöhnliches Ereignis.** Das ist die eigentliche
 * Entscheidung dieser Klasse und nicht bloß eine Umformung: ein Bericht, der
 * als Ereignis abgelegt wird, durchläuft dieselbe Kette wie jede andere Meldung
 * — Eingangsfilter, Scrubbing, Normalisierung, Gruppierung, Zähler — und
 * erscheint danach in denselben Listen, Suchen und Alarmen. Die Alternative
 * wäre eine zweite Tabelle mit einer zweiten Anzeige und einer zweiten Suche,
 * und niemand hätte etwas davon: ein blockiertes Skript ist für die, die es
 * ansehen, ein Fehler wie jeder andere.
 *
 * Die drei Arten unterscheiden sich nur in dem, was ihre Felder bedeuten —
 * Überschrift, Fehlerstelle, Gruppierung und Marken. Alles andere ist für alle
 * gleich und steht deshalb hier.
 */
abstract class SecurityReport
{
    /**
     * Die Plattform, unter der Sicherheitsberichte laufen.
     *
     * Ein Bericht kommt immer aus einem Browser — die Meldung stammt aus dessen
     * Sicherheitsprüfung und nicht aus dem Code der Seite. `javascript` ist
     * damit nicht ganz wörtlich, aber die Angabe, nach der später jemand
     * filtert, wenn er „das Frontend" meint.
     */
    private const PLATFORM = 'javascript';

    /**
     * Der Absender, wie er am Ereignis steht.
     *
     * Kein SDK hat diese Meldung geschickt, und ein leeres Feld wäre die
     * schlechtere Auskunft: die Frage „woher kommt dieses Ereignis, das nach
     * keinem SDK aussieht?" beantwortet sich damit von selbst. Sentry hält es
     * genauso (`sentry.security`).
     */
    public const SDK_NAME = 'errstack.security';

    /**
     * @param  array<string, mixed>  $report  der innere Bericht, ohne seinen Umschlag
     */
    protected function __construct(
        protected readonly array $report,
    ) {}

    /**
     * Erkennt einen Bericht an seinem Umschlag.
     *
     * Die Browser packen den Bericht in ein Objekt mit genau einem Feld, und
     * dessen Name sagt, worum es geht. Am Content-Type wird bewusst **nicht**
     * entschieden: er ist je nach Browser und Fassung `application/csp-report`,
     * `application/json` oder gar nichts, und ein Bericht, der wegen einer
     * fehlenden Kopfzeile abgewiesen wird, fehlt später ohne jede Spur.
     *
     * `null` heißt: kein Bericht, den wir kennen. Der Aufrufer weist ab — das
     * ist der eine Fall, in dem eine Abweisung richtig ist, denn der Absender
     * ist hier kein SDK, das etwas wiederholen könnte, sondern ein Browser, der
     * gerade eine Adresse aufgerufen hat, die ihm nicht gehört.
     *
     * @param  array<mixed>  $data  der Rumpf der Anfrage als Feld-Baum
     */
    final public static function from(array $data): ?self
    {
        foreach (SecurityReportType::cases() as $type) {
            $report = $data[$type->envelopeKey()] ?? null;

            if (is_array($report) && ! array_is_list($report)) {
                /** @var array<string, mixed> $report */
                return self::make($type, $report);
            }
        }

        // Ohne Umschlag: einzelne Browser schicken den CSP-Bericht nackt, und
        // Werkzeuge, die ihn von Hand nachstellen, tun es fast immer. Erkannt
        // wird er an den beiden Feldern, die kein anderer Bericht trägt.
        if (isset($data['violated-directive']) || isset($data['effective-directive'])) {
            /** @var array<string, mixed> $data */
            return self::make(SecurityReportType::Csp, $data);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private static function make(SecurityReportType $type, array $report): self
    {
        return match ($type) {
            SecurityReportType::Csp => new CspReport($report),
            SecurityReportType::ExpectCt => new ExpectCtReport($report),
            SecurityReportType::ExpectStaple => new ExpectStapleReport($report),
        };
    }

    abstract public function type(): SecurityReportType;

    /**
     * Die Adressen, an denen sich eine Browser-Erweiterung verrät.
     *
     * Getrennt von allem anderen, weil daran **vor** dem Ablegen entschieden
     * wird ({@see ExtensionNoise}): was eine Erweiterung ausgelöst hat, ist
     * kein Befund über die überwachte Anwendung.
     *
     * @return list<string>
     */
    abstract public function sources(): array;

    /**
     * Wo es passiert ist, in einer Zeile — dieselbe Rolle wie die Fehlerstelle
     * einer Ausnahme.
     */
    abstract protected function culprit(): ?string;

    /**
     * Wonach gruppiert wird.
     *
     * Ausdrücklich gesetzt und nicht dem Standardverfahren überlassen: das
     * arbeitet über Stapelrahmen und Fehlertext, und ein Bericht hat weder das
     * eine noch das andere. Ohne diese Angabe fiele jeder Bericht eines
     * Projekts in **eine** Gruppe, und die Liste bestünde aus einem einzigen
     * Eintrag mit einer großen Zahl daneben.
     *
     * @return list<string>
     */
    abstract protected function fingerprint(): array;

    /**
     * Die Marken, nach denen sich die Berichte filtern lassen.
     *
     * Nur Werte mit wenigen Ausprägungen: eine Marke je blockierter Adresse
     * samt Pfad wäre eine Spalte mit Millionen Werten und in keiner Auswahlbox
     * mehr zu gebrauchen.
     *
     * @return array<string, string>
     */
    abstract protected function tags(): array;

    /**
     * Die Adresse der Seite, auf der der Verstoß auftrat — sofern der Bericht
     * sie nennt.
     */
    abstract protected function url(): ?string;

    /**
     * Der Bericht als Sentry-Ereignis, wie es die Aufnahme ablegt.
     *
     * @param  array<string, string>  $headers  Kopfzeilen der meldenden Anfrage
     * @return array<string, mixed>
     */
    final public function toEvent(string $eventId, Carbon $receivedAt, array $headers = []): array
    {
        return array_filter([
            'event_id' => $eventId,
            'timestamp' => $receivedAt->toIso8601ZuluString(),
            'platform' => self::PLATFORM,
            // Kein Fehler, sondern ein Befund: der Browser hat getan, was die
            // Richtlinie verlangt. Wer das als Fehler geführt haben will, hebt
            // die Stufe am Eintrag an — umgekehrt ließe sich eine falsche
            // Aufregung nicht mehr zurücknehmen.
            'level' => 'warning',
            'logger' => $this->type()->value,
            'culprit' => $this->culprit(),
            // Als Vorlage samt Werten und nicht als fertiger Satz: die
            // Normalisierung hält beides auseinander, und die Vorlage ist die
            // Form, in der sich derselbe Befund über verschiedene Adressen
            // hinweg wiedererkennen lässt ({@see EventNormalizer}).
            'logentry' => $this->message(),
            'fingerprint' => $this->fingerprint(),
            'tags' => $this->tags(),
            'request' => $this->request($headers),
            'sdk' => ['name' => self::SDK_NAME, 'version' => $this->type()->value],
            // Der Bericht im Original. Ausgewertet wird er von uns nicht mehr —
            // aber die Frage „was stand da wirklich drin?" stellt sich bei einem
            // Verstoß regelmäßig, und `original-policy` ist die einzige Stelle,
            // an der die ganze Richtlinie steht.
            'extra' => [$this->type()->envelopeKey() => $this->report],
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Meldungstext samt Vorlage.
     *
     * @return array<string, mixed>
     */
    abstract protected function message(): array;

    /**
     * Die meldende Anfrage: die betroffene Seite und die Kopfzeilen des
     * Browsers.
     *
     * Beides ist echt und nicht nachgebaut — der Browser, der den Bericht
     * schickt, ist derselbe, dem der Verstoß begegnet ist. Damit greifen die
     * Eingangsfilter für Crawler und veraltete Browser auch hier.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    private function request(array $headers): ?array
    {
        $request = array_filter([
            'url' => $this->url(),
            'headers' => $headers,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        return $request === [] ? null : $request;
    }

    /**
     * Ein Feld des Berichts als Text, gekürzt und ohne Leerraum an den Rändern.
     *
     * Die Berichte kommen aus dem Browser und damit von außen: ein Feld darf
     * fehlen, eine Zahl statt eines Textes sein oder ein Objekt, wo ein Text
     * stehen sollte. Die Normalisierung fängt das später noch einmal ab — hier
     * geht es darum, dass Überschrift und Fingerabdruck **vorher** stimmen.
     */
    final protected function text(string $field, int $limit = 200): ?string
    {
        $value = $this->report[$field] ?? null;

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
