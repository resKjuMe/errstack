<?php

namespace App\Support\Ingest\Scrubbing;

use App\Enums\ScrubRuleType;
use App\Models\Project;
use App\Models\ScrubRule;

/**
 * Was für ein Projekt gilt: die Standardregeln, die Schalter und die eigenen
 * Regeln — in dieser Reihenfolge zu einer Liste zusammengelegt.
 *
 * Ein Gegenstand statt drei Parameter, weil die drei nie getrennt gebraucht
 * werden und weil die Vorschau in der Oberfläche genau dasselbe braucht wie die
 * Aufnahme. Zwei Wege, dieselbe Liste zusammenzustellen, wären zwei
 * Gelegenheiten, sie unterschiedlich zusammenzustellen — und die Vorschau würde
 * dann etwas anderes zeigen als das, was passiert.
 */
final class Settings
{
    /**
     * @param  list<Directive>  $directives
     */
    private function __construct(
        public readonly array $directives,
        public readonly bool $scrubAttachments,
    ) {}

    /**
     * Die Einstellungen eines Projekts, samt seiner und der
     * organisationsweiten Regeln.
     */
    public static function forProject(Project $project): self
    {
        $custom = ScrubRule::query()
            ->effectiveFor($project)
            ->active()
            // Feste Reihenfolge, damit die Vorschau immer dasselbe zeigt: erst
            // die organisationsweiten, dann die des Projekts.
            ->orderByRaw('CASE WHEN project_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->get()
            ->map(fn (ScrubRule $rule): Directive => $rule->toDirective())
            ->all();

        return self::of(
            custom: $custom,
            scrubIpAddresses: (bool) $project->scrub_ip_addresses,
            scrubUserData: (bool) $project->scrub_user_data,
            scrubAttachments: (bool) $project->scrub_attachments,
        );
    }

    /**
     * Baut die Liste ohne Datenbank — der Weg der Tests und der Vorschau.
     *
     * @param  list<Directive>  $custom
     */
    public static function of(
        array $custom = [],
        bool $scrubIpAddresses = false,
        bool $scrubUserData = false,
        bool $scrubAttachments = false,
    ): self {
        return new self(
            array_merge(
                Defaults::directives(),
                self::fromSwitches($scrubIpAddresses, $scrubUserData),
                array_values(array_filter($custom, static fn (Directive $d): bool => $d->isUsable())),
            ),
            $scrubAttachments,
        );
    }

    /**
     * Die Schalter als Anweisungen.
     *
     * Sie könnten auch als Sonderfälle im Scrubber stehen. Als Anweisungen sind
     * sie es aber nicht nur einmal weniger zu schreiben, sondern zwei Dinge
     * zugleich: sie greifen ohne weitere Zeile überall, wo eine Regel greift,
     * und sie erscheinen in der Vorschau — wer die Adresse abschaltet, sieht am
     * Beispiel, dass sie wirklich weg ist.
     *
     * @return list<Directive>
     */
    private static function fromSwitches(bool $scrubIpAddresses, bool $scrubUserData): array
    {
        $directives = [];

        if ($scrubIpAddresses) {
            // Die Adresse steht an mehreren Stellen, und keine davon ist
            // verzichtbar: das SDK schickt sie am Betroffenen, der Webserver
            // legt sie in die Umgebung, und ein Proxy schreibt sie in eine
            // Kopfzeile. Bliebe eine übrig, wäre die Einstellung wirkungslos.
            $directives[] = new Directive(ScrubRuleType::Field, 'ip_address', 'user');
            $directives[] = new Directive(ScrubRuleType::Field, 'remote_addr', 'request.env');

            foreach (['x-forwarded-for', 'x-real-ip', 'x-client-ip', 'forwarded'] as $header) {
                $directives[] = new Directive(ScrubRuleType::Field, $header, 'request.headers');
            }
        }

        if ($scrubUserData) {
            // Der ganze Abschnitt, nicht die bekannten Felder daraus: was ein
            // SDK zusätzlich am Betroffenen mitgibt, ist genauso wenig zu
            // speichern — und was das ist, wissen wir nicht.
            $directives[] = new Directive(ScrubRuleType::Field, '*', 'user');
        }

        return $directives;
    }
}
