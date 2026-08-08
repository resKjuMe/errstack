<?php

namespace App\Enums;

use App\Models\InboundFilterRule;
use App\Models\Project;

/**
 * Die Arten von Rauschen, die ein Projekt beim Eingang aussortieren kann.
 *
 * Jede Art ist ein eigener Schalter am Projekt und kein gemeinsamer „Filter
 * an/aus". Das ist der Unterschied, auf den es ankommt: wer die Meldungen aus
 * Browser-Erweiterungen loswerden will, will deshalb nicht auch die von
 * localhost verlieren — und wenn er es doch merkt, soll er genau den einen
 * Schalter wieder umlegen können und nicht raten müssen, welcher der sieben es
 * war.
 *
 * Vier der Arten brauchen zusätzlich eine Liste ({@see usesRules()}): welche
 * Fehlertexte, welche Absender, welche Releases, ab welcher Browser-Fassung.
 * Die drei übrigen kommen mit einer eingebauten Liste aus — was eine
 * Browser-Erweiterung ist, weiß niemand besser als wir, und es je Projekt
 * eintragen zu lassen hieße, dieselbe Liste hundertmal zu pflegen.
 */
enum InboundFilterKind: string
{
    /**
     * Fehler aus einer Browser-Erweiterung: der Nutzer hat sie installiert, mit
     * der überwachten Anwendung hat sie nichts zu tun. Der häufigste
     * Rauschanteil im Frontend überhaupt.
     */
    case BrowserExtension = 'browser_extension';

    /**
     * Fehler aus einem Browser, der zu alt ist, um ihn noch zu unterstützen.
     * Anders als die übrigen Arten hängt diese an einer Grenze und nicht an
     * einer Liste — welche, steht in den Regeln.
     */
    case LegacyBrowser = 'legacy_browser';

    /** Meldungen aus der lokalen Entwicklung. */
    case Localhost = 'localhost';

    /** Meldungen, die ein Web-Crawler ausgelöst hat. */
    case Crawler = 'crawler';

    /** Fehlermeldungen, deren Text auf ein hinterlegtes Muster passt. */
    case MessagePattern = 'message_pattern';

    /** Meldungen von einer gesperrten Absender-Adresse. */
    case IpAddress = 'ip_address';

    /** Meldungen aus einem gesperrten Release. */
    case Release = 'release';

    /**
     * Der Schalter am Projekt, der diese Art ein- und ausschaltet.
     *
     * Eigene Spalten statt eines JSON-Feldes: eine Einstellung, nach der später
     * ausgewertet wird („welche Projekte filtern Crawler?"), gehört in eine
     * Spalte. Der Name steht hier und nicht an sieben Stellen im Code.
     */
    public function column(): string
    {
        return match ($this) {
            self::BrowserExtension => 'filter_browser_extensions',
            self::LegacyBrowser => 'filter_legacy_browsers',
            self::Localhost => 'filter_localhost',
            self::Crawler => 'filter_crawlers',
            self::MessagePattern => 'filter_message_patterns',
            self::IpAddress => 'filter_ip_addresses',
            self::Release => 'filter_releases',
        };
    }

    /**
     * Braucht diese Art eine Liste eigener Einträge ({@see InboundFilterRule})?
     *
     * Der Unterschied ist nicht kosmetisch: eine Art ohne Liste greift, sobald
     * ihr Schalter an ist. Eine Art mit Liste greift nur, soweit jemand etwas
     * eingetragen hat — mit einer Ausnahme, den veralteten Browsern: dort gibt
     * es eine sinnvolle Vorgabe, und ein eingeschalteter Filter, der ohne
     * Eintrag nichts tut, wäre eine Falle.
     */
    public function usesRules(): bool
    {
        return match ($this) {
            self::LegacyBrowser, self::MessagePattern, self::IpAddress, self::Release => true,
            default => false,
        };
    }

    public function label(): string
    {
        return __('enums.inbound_filter_kind.'.$this->value);
    }

    /**
     * Die Arten mit eigener Liste — die Auswahl beim Anlegen eines Eintrags.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function ruleOptions(): array
    {
        return array_values(array_map(
            fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
            array_filter(self::cases(), fn (self $kind): bool => $kind->usesRules()),
        ));
    }

    /**
     * Alle Schalterspalten — für die Stammdaten des {@see Project} und die
     * Prüfung des Formulars.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return array_map(fn (self $kind): string => $kind->column(), self::cases());
    }
}
