<?php

namespace App\Support\Ingest\Normalization;

use App\Enums\EventLevel;
use App\Enums\Platform;
use Illuminate\Support\Carbon;

/**
 * Eine Meldung, nachdem sie durch die Normalisierung gegangen ist.
 *
 * Das ist die Form, mit der alles Weitere arbeitet — Gruppierung (I5),
 * Fortschreibung der Fehlergruppen (I6), Suche, Anzeige, Benachrichtigungen.
 * Der Unterschied zum Eingang ist nicht der Inhalt, sondern die Verlässlichkeit:
 * hier steht in jedem Feld entweder ein geprüfter Wert oder `null`, und die
 * Frage „was schickt dieses SDK eigentlich" ist ein für alle Mal beantwortet.
 *
 * Ein Gegenstand ohne Verhalten mit Absicht: er wird erzeugt, weitergereicht
 * und abgelegt. Was aus ihm folgt, entscheiden die Schritte danach — und die
 * sollen ihn nicht verändern können, sonst wäre nicht mehr zu sagen, welcher
 * Schritt einen Wert gesetzt hat.
 */
final class NormalizedEvent
{
    /**
     * @param  array<string, mixed>|null  $message  Meldungstext samt Vorlage.
     * @param  list<array<string, mixed>>  $exceptions  Ausnahme und ihre Ursachen, älteste zuerst.
     * @param  list<array<string, mixed>>  $threads  Ausführungsstränge.
     * @param  array<string, mixed>|null  $request  Die HTTP-Anfrage.
     * @param  array<string, mixed>|null  $user  Wen es getroffen hat.
     * @param  array<string, mixed>|null  $contexts  Betriebssystem, Browser, Laufzeit, Gerät, Spur.
     * @param  list<array<string, mixed>>  $breadcrumbs  Was vorher geschah, ältestes zuerst.
     * @param  array<string, string>  $tags  Marken zum Filtern.
     * @param  array<string, mixed>|null  $extra  Beiwerk der Anwendung.
     * @param  array<string, mixed>|null  $sdk  Absendendes SDK.
     * @param  array<string, string>  $modules  Bibliotheken samt Fassung.
     * @param  array<string, mixed>|null  $unknown  Felder, die wir nicht kennen — unverändert bewahrt.
     * @param  array{truncated?: list<string>, invalid?: list<string>}|null  $notes  Was gekürzt und was verworfen wurde.
     */
    public function __construct(
        public readonly string $eventId,
        public readonly EventLevel $level,
        public readonly string $platform,
        public readonly Carbon $timestamp,
        public readonly ?string $title,
        public readonly ?string $culprit,
        public readonly ?string $transaction,
        public readonly ?string $logger,
        public readonly ?string $environment,
        public readonly ?string $release,
        public readonly ?string $dist,
        public readonly ?string $serverName,
        public readonly ?array $message,
        public readonly array $exceptions,
        public readonly array $threads,
        public readonly ?array $request,
        public readonly ?array $user,
        public readonly ?array $contexts,
        public readonly array $breadcrumbs,
        public readonly array $tags,
        public readonly ?array $extra,
        public readonly ?array $sdk,
        public readonly array $modules,
        public readonly ?array $unknown,
        public readonly ?array $notes,
    ) {}

    /**
     * Die Plattform, die eine Meldung ohne brauchbare Angabe bekommt.
     *
     * Bewusst dieselbe Zeichenkette wie {@see Platform::Other} —
     * dort ist es die Auswahl des Projekts, hier die Angabe der Meldung. Die
     * Werte der Meldung sind aber **keine** Aufzählung: Sentry kennt an die
     * vierzig Plattformen und nimmt laufend neue auf (`cocoa`, `elixir`,
     * `native` …). Eine geschlossene Liste müsste bei jeder davon nachgezogen
     * werden und würde die Meldung bis dahin verfälschen.
     */
    public const PLATFORM_FALLBACK = 'other';

    /**
     * Trägt die Meldung eine Ausnahme — oder ist sie eine bloße Nachricht?
     *
     * Der Unterschied entscheidet ab I5 darüber, wonach gruppiert wird: nach
     * dem Stacktrace oder nach dem Meldungstext.
     */
    public function hasException(): bool
    {
        return $this->exceptions !== [];
    }

    /**
     * Der Stacktrace der zuletzt geworfenen Ausnahme.
     *
     * Die Ursachenkette ist von der ältesten Ursache zur zuletzt geworfenen
     * Ausnahme geordnet; die letzte ist deshalb die, die die Anwendung
     * tatsächlich gesehen hat.
     *
     * @return list<array<string, mixed>>
     */
    public function frames(): array
    {
        $last = $this->exceptions === [] ? null : $this->exceptions[array_key_last($this->exceptions)];

        $frames = $last['frames'] ?? null;

        /** @var list<array<string, mixed>> */
        return is_array($frames) ? $frames : [];
    }

    /**
     * Die Form, in der die Meldung abgelegt wird.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'level' => $this->level->value,
            'platform' => $this->platform,
            'timestamp' => $this->timestamp->toIso8601ZuluString(),
            'title' => $this->title,
            'culprit' => $this->culprit,
            'transaction' => $this->transaction,
            'logger' => $this->logger,
            'environment' => $this->environment,
            'release' => $this->release,
            'dist' => $this->dist,
            'server_name' => $this->serverName,
            'message' => $this->message,
            'exceptions' => $this->exceptions,
            'threads' => $this->threads,
            'request' => $this->request,
            'user' => $this->user,
            'contexts' => $this->contexts,
            'breadcrumbs' => $this->breadcrumbs,
            'tags' => $this->tags,
            'extra' => $this->extra,
            'sdk' => $this->sdk,
            'modules' => $this->modules,
            'unknown' => $this->unknown,
            'notes' => $this->notes,
        ];
    }
}
