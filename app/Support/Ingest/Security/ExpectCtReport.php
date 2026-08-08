<?php

namespace App\Support\Ingest\Security;

use App\Enums\SecurityReportType;

/**
 * Ein Zertifikat ohne die verlangten Certificate-Transparency-Belege.
 *
 * Die Seite hat `Expect-CT` gesetzt, der Browser hat nachgesehen, ob das
 * Zertifikat in den öffentlichen Logbüchern steht (die „SCTs"), und es nicht
 * gefunden. Der Befund betrifft nicht den Code, sondern die Auslieferung: ein
 * falsch ausgestelltes Zertifikat, eine aufgebrochene TLS-Verbindung im
 * Firmennetz, ein Zwischenzertifikat, das die Kette nicht mitliefert.
 *
 * **Gruppiert wird nach dem Rechnernamen.** Anders als beim CSP-Bericht gibt es
 * hier nichts Feineres: der Befund gilt dem Zertifikat dieses Wirts, und
 * dasselbe Zertifikat liefert derselbe Wirt jedem Besucher aus. Die
 * Zertifikatsketten in den Fingerabdruck zu nehmen wäre die naheliegende
 * Verfeinerung und die falsche — jede Erneuerung ergäbe eine neue Gruppe, und
 * erneuert wird alle drei Monate.
 */
final class ExpectCtReport extends SecurityReport
{
    public function type(): SecurityReportType
    {
        return SecurityReportType::ExpectCt;
    }

    public function sources(): array
    {
        // Eine Browser-Erweiterung kann diesen Bericht nicht auslösen — er
        // entsteht im TLS-Handshake, lange bevor eine Erweiterung überhaupt
        // etwas zu sehen bekommt. Die Angabe steht trotzdem, damit der Filter
        // vor jeder Art dieselbe Frage stellen kann und nicht nach der Art
        // unterscheiden muss.
        $hostname = $this->hostname();

        return $hostname === null ? [] : [$hostname];
    }

    protected function culprit(): ?string
    {
        return $this->authority();
    }

    protected function fingerprint(): array
    {
        return [$this->type()->value, $this->hostname() ?? ''];
    }

    protected function tags(): array
    {
        return array_filter([
            'security_report' => $this->type()->value,
            'hostname' => $this->hostname(),
            'port' => $this->text('port', 10),
            // `enforce` oder `report-only`: ob der Browser die Verbindung
            // abgebrochen hätte oder nur berichtet.
            'failure_mode' => $this->text('failure-mode', 20),
        ], static fn (?string $value): bool => $value !== null);
    }

    protected function url(): ?string
    {
        $hostname = $this->hostname();

        if ($hostname === null) {
            return null;
        }

        return ($this->text('scheme', 10) ?? 'https').'://'.$hostname;
    }

    protected function message(): array
    {
        $authority = $this->authority() ?? 'unbekannter Wirt';

        return [
            'message' => 'Certificate Transparency verletzt: %s',
            'params' => [$authority],
            'formatted' => 'Certificate Transparency verletzt: '.$authority,
        ];
    }

    private function hostname(): ?string
    {
        return $this->text('hostname', 255);
    }

    /**
     * Rechnername samt Anschluss, sofern einer genannt ist.
     *
     * Der Anschluss steht in der Anzeige und **nicht** im Fingerabdruck: es ist
     * dasselbe Zertifikat, gleich unter welchem Anschluss es ausgeliefert wird.
     */
    private function authority(): ?string
    {
        $hostname = $this->hostname();

        if ($hostname === null) {
            return null;
        }

        $port = $this->text('port', 10);

        return $port === null ? $hostname : $hostname.':'.$port;
    }
}
