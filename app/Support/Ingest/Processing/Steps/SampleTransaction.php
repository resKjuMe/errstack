<?php

namespace App\Support\Ingest\Processing\Steps;

use App\Enums\DiscardReason;
use App\Enums\IngestType;
use App\Jobs\ProcessIngestPayload;
use App\Support\Ingest\Processing\ProcessingContext;
use App\Support\Ingest\Processing\ProcessingStep;
use App\Support\Ingest\Sampling\Sampler;
use App\Support\Ingest\Sampling\SampleTarget;
use App\Support\Ingest\Sampling\SamplingDecision;
use Closure;

/**
 * Zieht die Stichprobe: behält einen einstellbaren Anteil der gemeldeten
 * Antwortzeiten und sortiert den Rest aus.
 *
 * **Fehler fasst der Schritt nicht an.** Er sieht ausschließlich Meldungen des
 * Typs `transaction` und reicht alles andere unverändert weiter. Das ist die
 * Zusage der Aufgabe und keine Auslegungsfrage: ein Absturz ist ein Einzelfall,
 * und ein Einzelfall lässt sich nicht hochrechnen. Von hundert gleichen
 * Abstürzen einen zu behalten hieße, in 99 Fällen die falsche Antwort auf „ist
 * das schon vorgekommen?" zu geben.
 *
 * **Der Schritt steht früh in der Kette** — vor allem, was schreibt. Das ist der
 * ganze Zweck: eine Messung, die die Stichprobe nicht behält, soll auch nicht
 * normalisiert, gruppiert oder abgelegt werden. Stünde er hinter der Ablage,
 * wäre er ein Aufräumen, und ein Aufräumen spart nichts.
 *
 * **Aber hinter dem Scrubbing** ({@see ScrubEvent}), und das ist keine Frage der
 * Ersparnis. Das Scrubbing schreibt die bereinigte Fassung über die Rohdaten in
 * der Eingangsablage zurück. Stünde die Stichprobe davor, würde sie mit einem
 * `drop()` die Kette beenden — der Rumpf einer ausgesiebten Messung bliebe
 * dauerhaft unbereinigt liegen, und das Scrubbing käme nie an ihn heran. Zwei
 * gesparte Regelwerk-Durchläufe je verworfener Messung wären dafür ein
 * schlechter Handel.
 *
 * **Er sortiert aus, statt durchzureichen.** Anders als {@see RecordTransaction},
 * das eine unlesbare Transaktion zählt und weiterlaufen lässt, ist ein
 * `drop()` hier richtig: die Meldung ist vollständig und in Ordnung, sie wird
 * nur nicht gebraucht. Was danach käme, hätte mit ihr nichts mehr zu tun.
 *
 * Gezählt wird die Verwerfung im Rahmen ({@see ProcessIngestPayload}) — als
 * `sampled` und mit der Kategorie `transaction`, damit die Nutzungsstatistik
 * (O3) sie neben den übrigen Gründen ausweisen kann und **nicht** als Fehler.
 * Protokolliert wird sie nicht: bei 1 % Quote wären das 99 Protokollzeilen je
 * behaltener Messung, und die Stichprobe soll Kosten sparen und nicht
 * verschieben.
 */
final class SampleTransaction implements ProcessingStep
{
    public function __construct(
        private readonly Sampler $sampler,
    ) {}

    public function handle(ProcessingContext $context, Closure $next): void
    {
        $payload = $context->payload;

        if ($payload->type !== IngestType::Transaction) {
            $next($context);

            return;
        }

        $project = $payload->project;
        $data = $context->data;

        if ($project === null || $data === null) {
            // Ohne Projekt gibt es keine Regeln, ohne Rumpf nichts zu bewerten.
            // Durchreichen und nicht aussortieren: die Meldung fällt später von
            // selbst weg, und eine Verwerfung mit dem Grund „Stichprobe" wäre
            // hier eine falsche Auskunft.
            $next($context);

            return;
        }

        $decision = $this->sampler->decide($project, SampleTarget::fromPayload($data));

        // Auch bei einer verworfenen Messung abgelegt: sie ist die Begründung,
        // und ein späterer Schritt soll nicht raten müssen, warum die Kette hier
        // endete.
        $context->with(SamplingDecision::CONTEXT_KEY, $decision);

        if (! $decision->keep) {
            $context->drop(DiscardReason::Sampled, IngestType::Transaction->value);

            return;
        }

        $next($context);
    }
}
