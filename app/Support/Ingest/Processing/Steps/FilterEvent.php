<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Support\Ingest\Filtering\InboundFilter;
use App\Support\Ingest\Filtering\Settings;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use Closure;

/**
 * Sortiert aus, was das Projekt gar nicht erst sehen will: Meldungen aus
 * Browser-Erweiterungen, von Crawlern, aus der lokalen Entwicklung, aus einem
 * gesperrten Release.
 *
 * Der Schritt steht ganz vorn, gleich hinter dem Entpacken, und das ist seine
 * eigentliche Begründung. Aussortieren ließe sich eine Meldung an jeder Stelle
 * der Kette — hier kostet sie danach nichts mehr: keine Normalisierung, die
 * durch jeden Stapelrahmen geht, keinen Fingerabdruck über den halben
 * Stacktrace, keine Einfügung und kein Fortschreiben von Zählern. Der Filter
 * selbst ist ein paar Vergleiche auf Feldern, die ohnehin im Speicher liegen.
 *
 * **Nur Fehler und Transaktionen.** Ein Anhang hat keinen Feld-Baum, an dem
 * sich etwas erkennen ließe; eine Sitzung, ein Lebenszeichen oder ein
 * Client-Report sind keine „Meldungen", und den Client-Report zu filtern hieße
 * ausgerechnet die Zählung wegzuwerfen, die erklärt, was ein SDK selbst
 * verworfen hat.
 *
 * **Die Rohdaten bleiben liegen — ungeschwärzt.** Der Schritt steht vor dem
 * Scrubbing, und das Scrubbing ist es, das die Ablage in `ingest_payloads`
 * überschreibt ({@see ScrubEvent}). Eine gefilterte Meldung erreicht es nie,
 * ihr Rumpf steht also weiter da, wie das SDK ihn schickte. Das ist eine
 * bewusste Abwägung und keine Lücke: die Rohdaten sind der einzige Weg zurück,
 * wenn ein Filter zu weit gefasst war — genau der Fall, in dem jemand sie
 * braucht. Erst umzuschreiben und dann wegzuwerfen wäre Arbeit an einer
 * Meldung, die gleich darauf verworfen wird, und der Filter für Absender
 * bräuchte die Adresse, die das Scrubbing entfernt hätte. Aufgeräumt wird
 * beides zusammen (O2).
 *
 * **Der Anhang zu einer gefilterten Meldung bleibt.** Er kommt als eigenes
 * Element, oft in einer eigenen Anfrage, und trägt nichts, woran der Filter ihn
 * seiner Meldung zuordnen könnte. Das ist eine bekannte Lücke und keine
 * vergessene: nachträglich Gespeichertes wieder wegzuräumen ist eigene Arbeit
 * (O2).
 */
final class FilterEvent implements ProcessingStep
{
    /**
     * Was den Ausschlag gab — für einen späteren Schritt, der es vermerken
     * will, und für die Tests, die sonst nur „irgendetwas hat gefiltert"
     * feststellen könnten.
     */
    public const RESULT = 'filter_verdict';

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;
        $project = $payload->project;
        $data = $context->data;

        if ($project === null || $data === null || ! self::isFilterable($payload->type)) {
            $next($context);

            return;
        }

        $verdict = (new InboundFilter(Settings::forProject($project)))->verdict($data);

        if ($verdict === null) {
            $next($context);

            return;
        }

        $context->with(self::RESULT, $verdict);

        // Die Filterart als Merkmal der Verwerfung: daraus entsteht die Zählung
        // je Filterart, ohne dass es dafür sieben Gründe braucht. Der Anlass —
        // welches Muster, welcher Pfad — wird bewusst nicht gezählt; er wäre ein
        // Merkmal mit unbegrenzt vielen Werten.
        $context->drop(DiscardReason::Filtered, $verdict->kind->value);
    }

    private static function isFilterable(IngestType $type): bool
    {
        return match ($type) {
            IngestType::Event, IngestType::Transaction => true,
            default => false,
        };
    }
}
