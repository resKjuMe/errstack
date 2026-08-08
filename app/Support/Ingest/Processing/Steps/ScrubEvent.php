<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Models\IngestPayload;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Ingest\Scrubbing\Scrubber;
use App\Support\Ingest\Scrubbing\ScrubResult;
use App\Support\Ingest\Scrubbing\Settings;
use Closure;

/**
 * Entfernt personenbezogene und geheime Angaben, bevor irgendetwas gespeichert
 * wird.
 *
 * Der Schritt steht in der Kette **vor** allen, die schreiben. Das ist keine
 * Reihenfolgen-Vorliebe, sondern die Zusage der Aufgabe: was hier wegfällt, darf
 * die Datenbank nie gesehen haben. Ein Scrubbing hinter der Normalisierung wäre
 * ein Aufräumen — und ein Aufräumen kommt zu spät.
 *
 * **Die Rohdaten werden mitgeschrieben.** Die Eingangsablage hält den Rumpf, wie
 * das SDK ihn geschickt hat ({@see IngestPayload}), damit sich Meldungen später
 * erneut durchlaufen lassen. Genau dort läge das Kennwort danach weiter — die
 * Zusage wäre für den ausgewerteten Datensatz eingelöst und für die Ablage
 * daneben gebrochen. Deshalb ersetzt dieser Schritt auch die Rohdaten durch die
 * bereinigte Fassung. Ein erneuter Durchlauf arbeitet dann auf bereinigten
 * Daten; das ist die richtige Seite des Handels, denn die Alternative wäre eine
 * Ablage, die dauerhaft enthält, was nirgends stehen darf.
 *
 * Was dabei ungelöst bleibt und hier stehen soll, statt verschwiegen zu werden:
 * zwischen Annahme und Auswertung liegt die Wartezeit der Warteschlange, und in
 * dieser Zeit stehen die Rohdaten unbereinigt in der Tabelle. Kürzer geht es
 * nicht, ohne die Auswertung in die Anfrage der überwachten Anwendung zu
 * ziehen — und die soll auf uns nicht warten. Das nachträgliche Löschen bereits
 * gespeicherter Daten ist eigene Arbeit (O2).
 */
final class ScrubEvent implements ProcessingStep
{
    /**
     * Name, unter dem die Wege der geschwärzten Felder im Kontext liegen.
     *
     * Die folgenden Schritte brauchen sie nicht, aber sie sind die Auskunft
     * darüber, was passiert ist — und ein Schritt, der später einen Vermerk am
     * Datensatz anbringen will, soll sie vorfinden und nicht erneut rechnen.
     */
    public const RESULT = 'scrubbed_paths';

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;
        $project = $payload->project;

        if ($project === null) {
            // Ohne Projekt gibt es keine Einstellungen — und nichts, wofür die
            // Meldung noch aufgehoben würde. Das trifft nur Meldungen, deren
            // Projekt nach der Annahme gelöscht wurde.
            $next($context);

            return;
        }

        if ($payload->type->isUserFeedback()) {
            // Eine Rückmeldung besteht aus dem, was jemand **freiwillig**
            // angegeben hat, um zurückgerufen zu werden. Das Schwärzen ist gegen
            // etwas ganz anderes gerichtet: gegen personenbezogene Daten, die
            // ein SDK nebenbei einsammelt, ohne dass jemand gefragt wurde. Beide
            // über einen Kamm zu scheren hieße, die Antwortadresse zu löschen,
            // um die die Person selbst gebeten hat — und die Zuschrift damit
            // wertlos zu machen (M6).
            $next($context);

            return;
        }

        $settings = Settings::forProject($project);

        if ($payload->type->isBinary()) {
            // Ein Anhang ist kein Feld-Baum; an ihm gibt es nichts zu schwärzen.
            // Entweder er darf gespeichert werden oder nicht — ein Screenshot
            // eines Formulars ist entweder harmlos oder ganz und gar nicht.
            if ($settings->scrubAttachments) {
                $context->drop(DiscardReason::Scrubbed, $payload->type->value);

                return;
            }

            $next($context);

            return;
        }

        $data = $context->data;

        if ($data === null) {
            $next($context);

            return;
        }

        $result = (new Scrubber($settings))->scrub($data);

        $context->data = $result->data;
        $context->with(self::RESULT, $result->paths);

        if ($result->changed()) {
            $this->rewriteRawPayload($payload, $result);
        }

        $next($context);
    }

    /**
     * Ersetzt die abgelegten Rohdaten durch die bereinigte Fassung.
     *
     * `size_bytes` bleibt, wie es war: die Spalte trägt die angenommene Menge und
     * ist die Grundlage der Nutzungsabrechnung. Sie nachträglich zu verkleinern
     * hieße, dem Absender eine Meldung zu schenken, weil sie ein Kennwort
     * enthielt.
     *
     * Lässt sich die bereinigte Fassung nicht als JSON schreiben, bleibt die
     * alte stehen — dann ist die Ablage unbereinigt, aber lesbar. Andernfalls
     * wäre sie beides nicht.
     */
    private function rewriteRawPayload(IngestPayload $payload, ScrubResult $result): void
    {
        $json = json_encode($result->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        // `forceFill`, weil das Model keine füllbaren Felder hat: seine Angaben
        // kommen ausschließlich aus der Aufnahme, und das ist hier genau der
        // Grund, den Weg über die Ausnahme zu nehmen und nicht die Regel
        // aufzuweichen.
        $payload->forceFill([
            'payload' => $json,
            'payload_encoding' => null,
        ])->save();
    }
}
