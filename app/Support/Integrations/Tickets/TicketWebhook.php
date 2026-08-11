<?php

namespace App\Support\Integrations\Tickets;

use App\Enums\ExternalIssueState;
use App\Enums\IntegrationProvider;
use App\Models\Integration;
use App\Models\IntegrationWebhookEvent;
use App\Support\Integrations\GitHub\GitHubWebhook;
use App\Support\Integrations\Tickets\Jira\JiraTicketProvider;
use App\Support\Integrations\Tickets\Linear\LinearTicketProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Die Annahme eingehender Meldungen von Jira und Linear (X4).
 *
 * Wie bei GitHub getrennt von der Verarbeitung ({@see TicketWebhookProcessor}),
 * und aus demselben Grund: die Annahme läuft in der Anfrage und muss schnell
 * antworten, die Verarbeitung läuft in der Warteschlange.
 *
 * **Der Nachweis steckt in der Adresse und nicht in einer Unterschrift.** Das
 * ist der eine wesentliche Unterschied zu {@see GitHubWebhook}, und er ist nicht
 * gewählt, sondern vorgefunden: Jira Cloud unterschreibt eine über die
 * Schnittstelle eingetragene Rückadresse nicht, und Linears Unterschrift hängt
 * an einem Geheimnis, das beim Einrichten des Webhooks **drüben** entsteht — hier
 * ist es unbekannt, solange niemand es abtippt. Also trägt die Adresse das
 * Geheimnis (`/api/hooks/jira/<token>`), es wird je Anbindung erzeugt und ist
 * jederzeit erneuerbar.
 *
 * Damit hängt die Zuordnung an der Adresse und nicht am Inhalt: welche
 * Organisation gemeint ist, sagt das Geheimnis. Das ist strenger als bei GitHub,
 * wo das Repository in der Nutzlast die Organisation bestimmt — und einfacher,
 * weil ein Jira-Projektschlüssel (`OPS`) nicht einmal instanzübergreifend
 * eindeutig ist.
 *
 * **Ohne passendes Geheimnis wird abgewiesen** und nichts festgehalten: eine
 * Antwort, die „Vorgang unbekannt" von „Adresse falsch" unterscheidet, ist eine
 * Auskunft an jemanden, der hier nichts zu suchen hat.
 */
final class TicketWebhook
{
    /**
     * Die Meldung festhalten — oder feststellen, dass sie schon da war.
     *
     * @return array{event: IntegrationWebhookEvent, fresh: bool}
     */
    public static function record(Request $request, Integration $integration): array
    {
        $payload = $request->json()->all();
        $provider = $integration->provider;

        $event = IntegrationWebhookEvent::query()->firstOrCreate(
            [
                'provider' => $provider->value,
                'delivery_id' => self::deliveryId($request, $provider, $payload),
            ],
            [
                // Die Organisation steht fest, sobald die Adresse stimmt — die
                // Anbindung gehört einer. Kein `best effort` über die Nutzlast
                // wie bei GitHub, wo das Repository sie bestimmt.
                'organization_id' => $integration->organization_id,
                'event' => Str::limit(self::event($provider, $payload), 50, ''),
                'action' => self::action($provider, $payload),
                // Das Projekt bzw. Team, in dem das Ticket liegt. Die Spalte
                // heißt `repository`, weil sie mit X1 entstanden ist und
                // dieselbe Rolle trägt: der Behälter, in dem die Nummer gilt.
                'repository' => self::target($provider, $payload),
                'payload' => $payload,
            ],
        );

        return ['event' => $event, 'fresh' => $event->wasRecentlyCreated];
    }

    /**
     * Die Kennung dieser Zustellung.
     *
     * Linear schickt eine (`Linear-Delivery`), Jira nicht. Für Jira wird sie
     * deshalb aus der Nutzlast **gebildet**: Vorgang und Zeitstempel der
     * Änderung. Das ist keine Notlösung, sondern genau die Eindeutigkeit, die
     * gebraucht wird — Jira wiederholt eine Zustellung mit demselben
     * `timestamp`, und zwei verschiedene Änderungen am selben Vorgang haben
     * nie denselben.
     *
     * Fehlt beides, wird eine erfunden: sonst käme jede Meldung als „schon
     * bekannt" durch und würde nie verarbeitet. Eine erfundene Kennung heißt
     * „einmal verarbeiten und nicht wiedererkennen" — die richtige Wahl, wenn
     * man nichts hat, woran man sie erkennen könnte.
     *
     * @param  array<mixed>  $payload
     */
    private static function deliveryId(Request $request, IntegrationProvider $provider, array $payload): string
    {
        $delivery = trim((string) $request->header('Linear-Delivery', ''));

        if ($delivery !== '') {
            return Str::limit($delivery, 100, '');
        }

        if ($provider === IntegrationProvider::Jira) {
            $issue = (string) (data_get($payload, 'issue.id') ?? data_get($payload, 'issue.key') ?? '');
            $timestamp = (string) (data_get($payload, 'timestamp') ?? '');

            if ($issue !== '' && $timestamp !== '') {
                return Str::limit($provider->value.':'.$issue.':'.$timestamp, 100, '');
            }
        }

        return (string) Str::uuid();
    }

    /**
     * Die Art der Meldung, in der Schreibweise des Anbieters.
     *
     * Sie wird **nicht** auf einen gemeinsamen Wortschatz umgerechnet. Am
     * Ereignis soll stehen, was angekommen ist — `jira:issue_updated` ist der
     * Begriff, unter dem man es im Zustellungsprotokoll drüben wiederfindet, und
     * ein hier erfundenes `ticket.changed` wäre in keiner Dokumentation zu
     * finden.
     *
     * @param  array<mixed>  $payload
     */
    private static function event(IntegrationProvider $provider, array $payload): string
    {
        if ($provider === IntegrationProvider::Jira) {
            $event = data_get($payload, 'webhookEvent');

            return is_string($event) && $event !== '' ? $event : 'unknown';
        }

        // Linear nennt die Art des betroffenen Dings (`Issue`, `Comment`,
        // `Project`) und die Handlung getrennt. Die Art steht als Ereignis, die
        // Handlung als Unterart — dieselbe Aufteilung wie bei GitHub
        // (`issues` / `opened`).
        $type = data_get($payload, 'type');

        return is_string($type) && $type !== '' ? $type : 'unknown';
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function action(IntegrationProvider $provider, array $payload): ?string
    {
        $action = $provider === IntegrationProvider::Jira
            ? data_get($payload, 'issue_event_type_name')
            : data_get($payload, 'action');

        return is_string($action) && $action !== '' ? Str::limit($action, 50, '') : null;
    }

    /**
     * Das Projekt bzw. Team aus der Nutzlast.
     *
     * @param  array<mixed>  $payload
     */
    private static function target(IntegrationProvider $provider, array $payload): ?string
    {
        if ($provider === IntegrationProvider::Jira) {
            $key = data_get($payload, 'issue.fields.project.key');

            if (is_string($key) && $key !== '') {
                return Str::limit($key, 200, '');
            }

            // Kein Projekt im Vorgang: manche Jira-Instanzen liefern die Felder
            // eines Vorgangs abgespeckt. Der Schlüssel steht dann noch vor der
            // Nummer.
            $issueKey = data_get($payload, 'issue.key');

            return is_string($issueKey) && $issueKey !== ''
                ? Str::limit(JiraTicketProvider::projectOf($issueKey), 200, '')
                : null;
        }

        $team = data_get($payload, 'data.team.key');

        if (is_string($team) && $team !== '') {
            return Str::limit($team, 200, '');
        }

        $identifier = data_get($payload, 'data.identifier');

        return is_string($identifier) && $identifier !== ''
            ? Str::limit(LinearTicketProvider::projectOf($identifier), 200, '')
            : null;
    }

    /**
     * Die Nummer des Tickets, um das es geht — oder `null`.
     *
     * @param  array<mixed>  $payload
     */
    public static function number(IntegrationProvider $provider, array $payload): ?int
    {
        if ($provider === IntegrationProvider::Jira) {
            $key = data_get($payload, 'issue.key');

            if (! is_string($key) || $key === '') {
                return null;
            }

            $number = JiraTicketProvider::numberOf($key);

            return $number > 0 ? $number : null;
        }

        $number = data_get($payload, 'data.number');

        return is_numeric($number) ? (int) $number : null;
    }

    /**
     * Der Zustand, den die Meldung nennt.
     *
     * Aus dem Ticket selbst und nicht aus der Handlung: `action` sagt
     * `update`/`create`, und daraus geht über den Zustand nichts hervor — der
     * steht im Ticket, und zwar in jeder Meldung.
     *
     * @param  array<mixed>  $payload
     */
    public static function state(IntegrationProvider $provider, array $payload): ExternalIssueState
    {
        if ($provider === IntegrationProvider::Jira) {
            $issue = $payload['issue'] ?? null;

            // Der Vorgang aus der Meldung hat dieselbe Form wie der aus einem
            // Aufruf — deshalb liest ihn dieselbe Methode. Fehlt er, bleibt eine
            // leere Liste, und die ergibt „offen": die vorsichtige Annahme, wie
            // überall beim Lesen fremder Nutzlasten.
            return JiraTicketProvider::state(is_array($issue) ? $issue : []);
        }

        return LinearTicketProvider::state((string) data_get($payload, 'data.state.type', ''));
    }

    /**
     * Die Überschrift, die die Meldung nennt — oder `null`, wenn keine dabei ist.
     *
     * @param  array<mixed>  $payload
     */
    public static function title(IntegrationProvider $provider, array $payload): ?string
    {
        $title = $provider === IntegrationProvider::Jira
            ? data_get($payload, 'issue.fields.summary')
            : data_get($payload, 'data.title');

        return is_string($title) && $title !== '' ? $title : null;
    }
}
