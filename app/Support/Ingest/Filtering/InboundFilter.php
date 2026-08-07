<?php

namespace App\Support\Ingest\Filtering;

use App\Enums\InboundFilterKind;

/**
 * Entscheidet, ob eine Meldung gespeichert wird — an den Einstellungen des
 * Projekts und am rohen Rumpf.
 *
 * Ohne Datenbank und ohne Model: hereingegeben werden die zusammengestellten
 * Einstellungen und der Feld-Baum, heraus kommt ein Urteil. Das ist derselbe
 * Zuschnitt wie beim Scrubber und aus demselben Grund — was hier entschieden
 * wird, muss sich an einem Beispiel prüfen lassen, ohne ein Projekt aufzubauen,
 * und die Vorschau in der Oberfläche soll denselben Weg nehmen wie die
 * Aufnahme.
 *
 * **Die Reihenfolge der Prüfungen ist die nach ihrem Preis**, nicht die der
 * Aufzählung in der Oberfläche. Release und Absender sind ein Feldzugriff und
 * ein Vergleich; die Erweiterungen gehen durch jeden Stapelrahmen der Meldung.
 * Der erste Treffer beendet die Prüfung — was danach käme, änderte am Ergebnis
 * nichts und kostete nur.
 */
final class InboundFilter
{
    public function __construct(
        private readonly Settings $settings,
    ) {}

    /**
     * Warum die Meldung auszusortieren ist — oder `null`, wenn sie bleibt.
     *
     * @param  array<mixed>  $data
     */
    public function verdict(array $data): ?Verdict
    {
        if (! $this->settings->isActive()) {
            return null;
        }

        $facts = new EventFacts($data);

        return $this->byRelease($facts)
            ?? $this->byAddress($facts)
            ?? $this->byHost($facts)
            ?? $this->byCrawler($facts)
            ?? $this->byBrowser($facts)
            ?? $this->byMessage($facts)
            ?? $this->byExtension($facts);
    }

    private function byRelease(EventFacts $facts): ?Verdict
    {
        if (! $this->settings->isEnabled(InboundFilterKind::Release)) {
            return null;
        }

        $release = $facts->release();

        if ($release === null) {
            return null;
        }

        return $this->firstMatch(
            InboundFilterKind::Release,
            $this->settings->expressionsFor(InboundFilterKind::Release),
            [$release],
        );
    }

    private function byAddress(EventFacts $facts): ?Verdict
    {
        if (! $this->settings->isEnabled(InboundFilterKind::IpAddress)) {
            return null;
        }

        $addresses = $facts->addresses();

        foreach ($this->settings->expressionsFor(InboundFilterKind::IpAddress) as $expression) {
            if (Addresses::matchesAny($expression, $addresses)) {
                return new Verdict(InboundFilterKind::IpAddress, $expression);
            }
        }

        return null;
    }

    private function byHost(EventFacts $facts): ?Verdict
    {
        if (! $this->settings->isEnabled(InboundFilterKind::Localhost)) {
            return null;
        }

        return $this->firstMatch(InboundFilterKind::Localhost, Defaults::LOCAL_HOSTS, $facts->hosts());
    }

    private function byCrawler(EventFacts $facts): ?Verdict
    {
        if (! $this->settings->isEnabled(InboundFilterKind::Crawler)) {
            return null;
        }

        $agent = $facts->userAgent();

        if ($agent === null) {
            return null;
        }

        return $this->firstMatch(InboundFilterKind::Crawler, Defaults::CRAWLERS, [$agent]);
    }

    private function byBrowser(EventFacts $facts): ?Verdict
    {
        if (! $this->settings->isEnabled(InboundFilterKind::LegacyBrowser)) {
            return null;
        }

        $browser = $facts->browser();

        if ($browser === null) {
            return null;
        }

        // Die Vorgaben greifen nur, solange das Projekt nichts Eigenes einträgt.
        // Beides zusammenzulegen wäre die schlechtere Wahl: wer `safari:4`
        // einträgt, will die Vorgabe `safari:6` ersetzen und nicht neben ihr
        // stehen haben — sie würde sonst weiter gewinnen.
        $expressions = $this->settings->expressionsFor(InboundFilterKind::LegacyBrowser);

        if ($expressions === []) {
            $expressions = Browsers::defaults();
        }

        if (! Browsers::isLegacy($browser, $expressions)) {
            return null;
        }

        return new Verdict(
            InboundFilterKind::LegacyBrowser,
            trim($browser['name'].' '.$browser['version']),
        );
    }

    private function byMessage(EventFacts $facts): ?Verdict
    {
        if (! $this->settings->isEnabled(InboundFilterKind::MessagePattern)) {
            return null;
        }

        return $this->firstMatch(
            InboundFilterKind::MessagePattern,
            $this->settings->expressionsFor(InboundFilterKind::MessagePattern),
            $facts->messages(),
        );
    }

    /**
     * Erweiterungen erkennt man an drei Dingen, und alle drei sind nötig: am
     * Adressschema der Datei (der sichere Fall), an bekannten Herkünften, die
     * über `https` geladen werden (Virenscanner), und am Fehlertext selbst —
     * manche Erweiterungen hinterlassen überhaupt keinen Stapelrahmen.
     */
    private function byExtension(EventFacts $facts): ?Verdict
    {
        if (! $this->settings->isEnabled(InboundFilterKind::BrowserExtension)) {
            return null;
        }

        foreach ($facts->framePaths() as $path) {
            foreach (Defaults::EXTENSION_SCHEMES as $scheme) {
                if (stripos($path, $scheme) === 0) {
                    return new Verdict(InboundFilterKind::BrowserExtension, $scheme);
                }
            }
        }

        return $this->firstMatch(InboundFilterKind::BrowserExtension, Defaults::EXTENSION_HOSTS, $facts->framePaths())
            ?? $this->firstMatch(InboundFilterKind::BrowserExtension, Defaults::EXTENSION_MESSAGES, $facts->messages());
    }

    /**
     * @param  list<string>  $expressions
     * @param  list<string>  $subjects
     */
    private function firstMatch(InboundFilterKind $kind, array $expressions, array $subjects): ?Verdict
    {
        if ($subjects === []) {
            return null;
        }

        foreach ($expressions as $expression) {
            if (Pattern::matchesAny($expression, $subjects)) {
                return new Verdict($kind, $expression);
            }
        }

        return null;
    }
}
