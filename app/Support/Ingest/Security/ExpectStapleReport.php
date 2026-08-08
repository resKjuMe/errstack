<?php

namespace App\Support\Ingest\Security;

use App\Enums\SecurityReportType;

/**
 * Eine fehlende oder unbrauchbare OCSP-Antwort im TLS-Handshake.
 *
 * „Stapling" heißt: der Server legt die Sperrauskunft seiner Zertifizierungs-
 * stelle der Verbindung gleich bei, statt den Browser einzeln nachfragen zu
 * lassen. Die Seite hat mit `Expect-Staple` verlangt, dass das geschieht; der
 * Browser meldet, wenn es ausblieb oder die Auskunft nicht zum Zertifikat
 * passte.
 *
 * Dieselbe Bauart wie {@see ExpectCtReport} und aus demselben Grund neben ihm:
 * beide betreffen die Auslieferung und nicht den Code, und beide kommen von
 * einem Browser, der eine Kopfzeile ernst genommen hat.
 *
 * **Gruppiert wird nach Rechnername und Befund.** Der Befund gehört dazu, weil
 * er die Ursache benennt: eine fehlende Antwort (`response-status`) ist ein
 * Problem der Auslieferung, eine abgelaufene oder widerrufene (`cert-status`)
 * eines des Zertifikats — zwei Sachen, die niemand in einem Eintrag zusammen
 * sehen will.
 */
final class ExpectStapleReport extends SecurityReport
{
    public function type(): SecurityReportType
    {
        return SecurityReportType::ExpectStaple;
    }

    public function sources(): array
    {
        // Wie bei Expect-CT: für eine Erweiterung ist hier nichts zu holen, die
        // Angabe hält nur die Reihe geschlossen.
        return array_values(array_filter([$this->hostname()]));
    }

    protected function culprit(): ?string
    {
        return $this->authority();
    }

    protected function fingerprint(): array
    {
        return [$this->type()->value, $this->hostname() ?? '', $this->status()];
    }

    protected function tags(): array
    {
        return array_filter([
            'security_report' => $this->type()->value,
            'hostname' => $this->hostname(),
            'port' => $this->text('port', 10),
            'response_status' => $this->text('response-status', 40),
            'cert_status' => $this->text('cert-status', 40),
        ], static fn (?string $value): bool => $value !== null);
    }

    protected function url(): ?string
    {
        $hostname = $this->hostname();

        return $hostname === null ? null : 'https://'.$hostname;
    }

    protected function message(): array
    {
        $authority = $this->authority() ?? 'unbekannter Wirt';
        $status = $this->status();

        return [
            'message' => 'OCSP-Stapling fehlgeschlagen: %s (%s)',
            'params' => [$authority, $status],
            'formatted' => sprintf('OCSP-Stapling fehlgeschlagen: %s (%s)', $authority, $status),
        ];
    }

    /**
     * Der Befund: warum die Auskunft nicht taugte.
     *
     * `cert-status` zuerst, weil es die genauere Auskunft ist — es steht nur
     * da, wenn überhaupt eine Antwort kam. Fehlte die Antwort ganz, sagt das
     * `response-status`.
     */
    private function status(): string
    {
        return $this->text('cert-status', 40)
            ?? $this->text('response-status', 40)
            ?? 'unbekannter Befund';
    }

    private function hostname(): ?string
    {
        return $this->text('hostname', 255);
    }

    /**
     * Rechnername samt Anschluss, sofern einer genannt ist.
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
