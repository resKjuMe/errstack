<?php

namespace App\Console\Commands;

use App\Enums\BacklogAction;
use App\Support\Operations\BacklogWatch;
use App\Support\SelfMonitoring\ScheduleCheckIn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

use function Sentry\captureMessage;
use function Sentry\withScope;

/**
 * Der Selbstschutz: sieht nach, ob die Verarbeitung hinterherhängt, und meldet
 * es dem Betreiber.
 *
 * Der eine Zustand, den sonst niemand meldet. Ein Fehler meldet sich, ein
 * Ausfall fällt auf — eine Warteschlange, die volläuft, sieht von außen aus wie
 * Betrieb. Ohne diesen Wächter fällt sie erst auf, wenn Nutzer fragen, warum
 * ihre Fehler fehlen.
 *
 * Gemeldet wird auf zwei Wegen, und beide sind Absicht: ins **Log**, weil das
 * der Ort ist, an dem der Betreiber ohnehin nachsieht und der auch dann noch
 * schreibt, wenn sonst nichts mehr geht; und an die **Selbstüberwachung**, weil
 * dort schon die eigenen Fehler landen und ein Rückstand dort neben ihnen
 * gehört. Ist keine eingerichtet, tut der zweite Weg nichts.
 *
 * Ausdrücklich **nicht** über die Alarme der Anwendung (A2/A3): die gehören
 * Kunden und hängen an einem Projekt. Ein Rückstand hat kein Projekt — und ein
 * Alarmweg, der selbst über die stehende Warteschlange läuft, meldet im
 * Ernstfall gar nichts.
 */
class OperationsWatchCommand extends Command
{
    protected $signature = 'ops:watch';

    protected $description = 'Rückstand der Verarbeitung prüfen und bei anhaltender Überschreitung warnen';

    public function handle(BacklogWatch $watch): int
    {
        $result = $watch->evaluate();

        $context = [
            'pending' => $result['pending'],
            'oldest_seconds' => $result['oldest_seconds'],
            'max_pending' => $result['max_pending'],
            'max_age_seconds' => $result['max_age_seconds'],
            'since' => $result['since']?->toIso8601String(),
            'reasons' => $result['reasons'],
        ];

        match ($result['action']) {
            BacklogAction::Warn => $this->warnOperator($context),
            BacklogAction::Recover => $this->informRecovery($context),
            BacklogAction::None => null,
        };

        // Die Zahlen auch dann ausgeben, wenn nichts zu melden war: das
        // Kommando ist von Hand aufrufbar, und dann ist genau das die Frage.
        $this->components->twoColumnDetail('Rückstand', (string) $result['pending']);
        $this->components->twoColumnDetail(
            'Ältester Rückstand',
            $result['oldest_seconds'] === null ? '—' : $result['oldest_seconds'].' s',
        );
        $this->components->twoColumnDetail('Über der Schwelle', $result['breaching'] ? 'ja' : 'nein');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function warnOperator(array $context): void
    {
        $message = sprintf(
            'Die Verarbeitung hängt hinterher: %d wartende Meldungen, älteste seit %s.',
            (int) $context['pending'],
            $context['oldest_seconds'] === null ? '—' : $context['oldest_seconds'].' s',
        );

        $this->channel()->warning($message, $context);
        $this->report($message, $context, Severity::warning());

        $this->components->warn($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function informRecovery(array $context): void
    {
        $message = 'Der Rückstand der Verarbeitung liegt wieder im Rahmen.';

        $this->channel()->info($message, $context);
        $this->report($message, $context, Severity::info());

        $this->components->info($message);
    }

    /**
     * Meldet an die Selbstüberwachung — sofern eine eingerichtet ist.
     *
     * Fehler beim Melden bleiben beim Melden: ein Wächter, der abbricht, weil
     * die Gegenstelle nicht erreichbar war, ist schlechter dran als einer, der
     * unbeobachtet läuft. Dieselbe Regel wie beim Lebenszeichen des Zeitplans
     * ({@see ScheduleCheckIn}).
     *
     * @param  array<string, mixed>  $context
     */
    private function report(string $message, array $context, Severity $severity): void
    {
        // Ohne DSN gibt es keinen Client — dann ist hier nichts zu tun, und
        // das ist der Auslieferungszustand und kein Fehler.
        if (SentrySdk::getCurrentHub()->getClient() === null) {
            return;
        }

        try {
            // `withScope` und nicht `configureScope`: die Zusatzangaben gelten
            // nur für diese eine Meldung. Global gesetzt hingen sie an jedem
            // späteren Fehler desselben Prozesses — und der Arbeiter läuft
            // stundenlang.
            withScope(function (Scope $scope) use ($message, $context, $severity): void {
                $scope->setContext('backlog', $context);

                captureMessage($message, $severity);
            });
        } catch (Throwable $exception) {
            Log::warning('Der Rückstand konnte nicht an die Selbstüberwachung gemeldet werden.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Der Log-Kanal für Warnung und Entwarnung — ohne eigene Angabe der
     * Standard-Stapel der Anwendung.
     */
    private function channel(): LoggerInterface
    {
        $channel = config('operations.backlog.channel');

        return Log::channel(is_string($channel) && $channel !== '' ? $channel : null);
    }
}
