<?php

namespace App\Support\Ingest\Grouping;

use App\Enums\GroupingSource;
use App\Support\Ingest\Normalization\NormalizedEvent;

/**
 * Der Fingerabdruck, den eine Meldung ohne jede Einstellung bekommt.
 *
 * Vier Verfahren, in dieser Rangfolge — jedes greift, wenn das vorherige nichts
 * hergibt:
 *
 * 1. **Stacktrace.** Ausnahme-Typ und die Rahmen des Stapels. Der Regelfall bei
 *    einem Absturz und das mit Abstand verlässlichste Verfahren: der Weg in den
 *    Fehler ist bei demselben Fehler derselbe, egal welche Kennung im Text
 *    stand.
 * 2. **Ausnahme.** Typ und Text, wenn kein Stacktrace dabei war — etwa bei
 *    einer von Hand gemeldeten Ausnahme.
 * 3. **Meldungstext.** Bevorzugt die Vorlage, nicht der ausgefüllte Text.
 * 4. **Titel, Fehlerstelle, Vorgang.** Was übrig bleibt.
 *
 * Der entscheidende Zug ist, was **nicht** in den Fingerabdruck eingeht:
 *
 * **Der Text der Ausnahme, sobald es einen Stacktrace gibt.** Er trägt die
 * wechselnden Anteile („Nutzer 4711 nicht gefunden"), der Stacktrace nicht. Wer
 * ihn mit aufnimmt, bekommt je Kennung eine Gruppe — und das ist genau die
 * Flut, gegen die diese Aufgabe angeht.
 *
 * **Die Zeilennummer eines Rahmens.** Sie verschiebt sich bei jeder Änderung an
 * der Datei; mit ihr im Fingerabdruck bekäme derselbe Fehler nach jedem
 * Deployment eine neue Gruppe, und die Zählung begänne von vorn.
 */
final class DefaultFingerprint
{
    public function __construct(
        private readonly int $maxFrames,
    ) {}

    /**
     * Bestimmt Bestandteile und Verfahren für diese Meldung.
     */
    public function for(NormalizedEvent $event): Components
    {
        $attributes = new Attributes($event);

        $frames = $this->frames($event);

        if ($frames !== []) {
            return new Components(GroupingSource::Stacktrace, [
                ...$this->named('error.type', $attributes->value('error.type')),
                ...$frames,
            ]);
        }

        if ($event->hasException()) {
            $exception = new Components(GroupingSource::Exception, [
                ...$this->named('error.type', $attributes->value('error.type')),
                ...$this->named('error.value', Variables::normalize($attributes->value('error.value'))),
            ]);

            // Eine Ausnahme, von der weder Typ noch Text übrig bleiben, ist
            // keine Zuordnung — dann sind die folgenden Verfahren besser als
            // eine Gruppe aus lauter leeren Bestandteilen.
            if (! $exception->isEmpty()) {
                return $exception;
            }
        }

        $message = Variables::normalize($attributes->message());

        if ($message !== null) {
            return new Components(GroupingSource::Message, $this->named('message', $message));
        }

        $fallback = new Components(GroupingSource::Fallback, [
            ...$this->named('title', Variables::normalize($event->title)),
            ...$this->named('culprit', Variables::normalize($event->culprit)),
            ...$this->named('transaction', Variables::normalize($event->transaction)),
        ]);

        if (! $fallback->isEmpty()) {
            return $fallback;
        }

        // Nichts, wonach sich unterscheiden ließe. Alle solchen Meldungen
        // landen in **einer** Gruppe: sie sind untereinander nicht zu
        // unterscheiden, und je Ereignis eine eigene Gruppe zu öffnen wäre die
        // Flut, die hier verhindert werden soll.
        return new Components(GroupingSource::Empty, [
            new Component('platform', $event->platform),
            new Component('level', $event->level->value),
        ]);
    }

    /**
     * Die Rahmen, die in den Fingerabdruck eingehen.
     *
     * **Bevorzugt die eigenen.** Bei einer Ausnahme aus zweihundert Rahmen sind
     * die aus dem Rahmenwerk in jedem Fehler dieselben; sie zu übernehmen
     * schadet zwar nicht der Zuordnung, macht sie aber empfindlich gegen jede
     * Fassungsänderung einer Bibliothek. Sind gar keine als eigen gekennzeichnet
     * — was bei manchen SDKs vorkommt —, gelten alle: eine Zuordnung nach
     * fremdem Code ist besser als keine.
     *
     * **Die jüngsten zuerst behalten.** Ist die Kette länger als erlaubt, wird
     * am älteren Ende gekappt: die Fehlerstelle steht am jungen Ende, und die
     * ältesten Rahmen sind der immer gleiche Weg durch das Rahmenwerk.
     *
     * @return list<Component>
     */
    private function frames(NormalizedEvent $event): array
    {
        $frames = $event->frames();

        if ($frames === []) {
            return [];
        }

        $inApp = array_values(array_filter(
            $frames,
            static fn (array $frame): bool => ($frame['in_app'] ?? null) === true,
        ));

        $relevant = $inApp === [] ? $frames : $inApp;

        if (count($relevant) > $this->maxFrames) {
            $relevant = array_slice($relevant, -$this->maxFrames);
        }

        $components = [];

        foreach ($relevant as $frame) {
            $signature = $this->frame($frame);

            if ($signature !== null) {
                $components[] = new Component('stack.frame', $signature);
            }
        }

        return $components;
    }

    /**
     * Die Kennzeichnung eines Rahmens: wo und in welcher Funktion.
     *
     * Modul und Paket stehen vor dem Dateipfad, wo es sie gibt — sie überleben
     * ein Verschieben im Dateibaum, das für den Fehler nichts bedeutet. Der Pfad
     * geht durch {@see Variables::path()}, damit derselbe Fehler von zwei
     * Rechnern mit verschiedenen Bauverzeichnissen nicht auseinanderfällt.
     *
     * @param  array<string, mixed>  $frame
     */
    private function frame(array $frame): ?string
    {
        $location = null;

        foreach (['module', 'package'] as $field) {
            $value = $frame[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $location = Variables::path($value);

                break;
            }
        }

        if ($location === null) {
            foreach (['abs_path', 'filename'] as $field) {
                $value = $frame[$field] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $location = Variables::path($value);

                    break;
                }
            }
        }

        $function = $frame['function'] ?? null;
        $function = is_string($function) && trim($function) !== ''
            ? Variables::normalize($function)
            : null;

        $signature = implode(' in ', array_filter([$function, $location]));

        return $signature === '' ? null : $signature;
    }

    /**
     * @return list<Component>
     */
    private function named(string $name, ?string $value): array
    {
        return $value === null || trim($value) === '' ? [] : [new Component($name, $value)];
    }
}
