<?php

namespace App\Jobs;

use App\Enums\IntegrationProvider;
use App\Enums\IssueStatus;
use App\Enums\QueueName;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Support\Integrations\Tickets\TicketException;
use App\Support\Integrations\Tickets\TicketProviders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Die andere Richtung des Abgleichs (X4): **hier erledigt, drüben geschlossen.**
 *
 * Sie läuft in der Warteschlange und nicht in der Anfrage, und das ist keine
 * Feinheit: „erledigen" trifft in dieser Anwendung eine Auswahl und nicht einen
 * Eintrag — bei 12.480 Fehlern mit je einem Ticket wären das 12.480 Aufrufe bei
 * Jira, während jemand auf eine Antwortseite wartet. Aus demselben Grund kommen
 * die Fehler als **Liste von Nummern** herein und nicht als Modelle: der Auftrag
 * liegt unter Umständen Minuten in der Schlange, und was dann zählt, ist die
 * Zeile in der Datenbank und nicht die Momentaufnahme von vorhin.
 *
 * **Ein gescheiterter Aufruf hält den Rest nicht auf.** Ein Ticket, das sich
 * nicht schließen lässt — weil der Arbeitsablauf keinen Übergang hergibt, weil
 * ein Pflichtfeld fehlt, weil es drüben gelöscht wurde —, wird vermerkt und
 * übersprungen. Die Alternative wäre, den ganzen Auftrag scheitern zu lassen und
 * ihn dreimal zu wiederholen: dann bleiben die anderen 300 Tickets offen, weil
 * eines im Weg steht.
 *
 * **Der Zustand hier wird nicht angerührt.** Dieser Auftrag schreibt
 * ausschließlich drüben. Ob ein Fehler erledigt ist, hat die Oberfläche
 * entschieden, bevor es diesen Auftrag gab — und den Zustand der Verknüpfung
 * setzt die Meldung, die von drüben zurückkommt (der Anbieter schickt sie, weil
 * wir gerade etwas geändert haben). Ihn hier vorwegzunehmen hieße, zu behaupten,
 * was man nicht geprüft hat.
 */
class SyncTicketState implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param  list<int>  $issueIds
     */
    public function __construct(
        public array $issueIds,
        public bool $resolved,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Den Abgleich anstoßen — wenn es etwas abzugleichen gibt.
     *
     * Die Existenzprüfung steht hier und nicht im Auftrag: der Regelfall ist,
     * dass eine Organisation **kein** Ticket-System angebunden hat, und dann soll
     * das Erledigen von 12.480 Fehlern keinen Auftrag in die Schlange legen, der
     * nichts findet. Eine Abfrage gegen zwei Spalten ist billiger als ein
     * Auftrag, der geschrieben, geholt und ausgeführt wird.
     *
     * @param  list<int>  $issueIds
     */
    public static function dispatchFor(array $issueIds, bool $resolved): void
    {
        if ($issueIds === []) {
            return;
        }

        $exists = IssueLink::query()
            ->whereIn('issue_id', $issueIds)
            ->whereNotNull('integration_id')
            ->whereHas('integration', fn ($integration) => $integration->whereIn('provider', array_map(
                fn (IntegrationProvider $provider): string => $provider->value,
                IntegrationProvider::ticketProviders(),
            )))
            ->exists();

        if ($exists) {
            self::dispatch($issueIds, $resolved);
        }
    }

    public function handle(): void
    {
        $links = IssueLink::query()
            ->whereIn('issue_id', $this->issueIds)
            ->whereNotNull('integration_id')
            ->with(['integration', 'issue'])
            ->get();

        foreach ($links as $link) {
            $this->sync($link);
        }
    }

    private function sync(IssueLink $link): void
    {
        $integration = $link->integration;

        if ($integration === null || ! $integration->syncsOutbound()) {
            return;
        }

        $provider = TicketProviders::forLink($link);

        if ($provider === null) {
            // Kein Ticket-Anbieter (GitHub) oder eine Anbindung, deren Zugang
            // abgelehnt wurde. Beides ist hier kein Fehler: die Verknüpfung
            // bleibt lesbar, sie wird nur nicht abgeglichen.
            return;
        }

        // Nachgesehen, ob der Fehler **jetzt noch** so steht. Zwischen dem Klick
        // und diesem Auftrag liegen unter Umständen Minuten, und in denen kann
        // jemand ihn wieder geöffnet haben. Ohne diese Prüfung schließt der
        // Auftrag ein Ticket zu einem Fehler, der offen ist.
        $issue = $link->issue;

        if (! $issue instanceof Issue) {
            return;
        }

        if (($issue->status === IssueStatus::Resolved) !== $this->resolved) {
            return;
        }

        try {
            $this->resolved ? $provider->close($link) : $provider->reopen($link);
        } catch (TicketException $e) {
            // Vermerkt und übersprungen. Ins Protokoll und nicht an den Fehler:
            // niemand hat auf diesen Aufruf gewartet, und ein Eintrag im Verlauf
            // des Fehlers („Ticket konnte nicht geschlossen werden") wäre eine
            // Meldung an jemanden, der die Seite längst verlassen hat.
            Log::warning('Ticket-Abgleich gescheitert', [
                'link_id' => $link->id,
                'reference' => $link->reference(),
                'provider' => $integration->provider->value,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
