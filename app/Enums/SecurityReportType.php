<?php

namespace App\Enums;

use App\Support\Ingest\Security\SecurityReport;

/**
 * Art eines Sicherheitsberichts, den der Browser von sich aus schickt.
 *
 * Die Werte sind die Namen, unter denen der Bericht auf der Leitung steht — der
 * Schlüssel im Rumpf (`csp-report`) und der Content-Type
 * (`application/csp-report`) tragen dieselbe Bezeichnung. Sie umzubenennen
 * hieße, die Zuordnung zwischen Rumpf, Marke und Anzeige von Hand pflegen zu
 * müssen.
 *
 * Drei Arten, und alle drei kommen ohne SDK zustande: die Anwendung setzt eine
 * Kopfzeile, der Browser stellt einen Verstoß fest und meldet ihn an die darin
 * genannte Adresse. Was hier fehlt — die Berichte der neueren Reporting-API
 * (`application/reports+json`, u. a. NEL) — hat bei Sentry einen eigenen
 * Endpunkt (`/nel/`) und gehört deshalb nicht hierher.
 *
 * @see SecurityReport
 */
enum SecurityReportType: string
{
    /**
     * Ein Verstoß gegen die Content-Security-Policy: der Browser hat eine
     * Ressource nicht geladen, weil die Richtlinie der Seite sie verbietet.
     */
    case Csp = 'csp';

    /**
     * Ein Zertifikat ohne die verlangten Certificate-Transparency-Belege
     * (SCTs). Die Seite hat `Expect-CT` gesetzt, der Browser hat die Belege
     * geprüft und nicht gefunden.
     */
    case ExpectCt = 'expect-ct';

    /**
     * Eine fehlende oder unbrauchbare OCSP-Antwort im TLS-Handshake
     * („Stapling"). Dieselbe Bauart wie Expect-CT, nur eine andere Prüfung.
     */
    case ExpectStaple = 'expect-staple';

    public function label(): string
    {
        return __('enums.security_report_type.'.$this->value);
    }

    /**
     * Der Schlüssel, unter dem der Bericht im Rumpf steht.
     *
     * Die Browser packen den Bericht in ein Objekt mit genau einem Feld —
     * `{"csp-report": {…}}` —, und der Name dieses Feldes ist zugleich die
     * einzige verlässliche Auskunft darüber, welche Art angekommen ist: der
     * Content-Type fehlt bei manchen Browsern oder steht auf
     * `application/json`.
     */
    public function envelopeKey(): string
    {
        return $this->value.'-report';
    }
}
