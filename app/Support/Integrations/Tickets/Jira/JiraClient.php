<?php

namespace App\Support\Integrations\Tickets\Jira;

use App\Models\Integration;
use App\Support\Integrations\Tickets\TicketException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Die eine Stelle, an der mit Jira gesprochen wird (X4).
 *
 * Wie bei GitHub: hier stehen nur die Aufrufe und das, was für **jeden** von
 * ihnen gilt — Adresse, Anmeldung, Zeitgrenze, und die Behandlung dessen, was
 * zurückkommt. Die Fachfragen („welcher Übergang schließt einen Vorgang?")
 * stehen daneben in {@see JiraTicketProvider}.
 *
 * **Die Adresse gehört der Anbindung, nicht der Installation.** Das ist der
 * Unterschied zu GitHub, und er ist kein Detail: jede Organisation hat ihr
 * eigenes `acme.atlassian.net`, und eine Einstellung in der `.env` könnte davon
 * genau eine bedienen. Sie steht deshalb bei den Zugangsdaten — verschlüsselt,
 * weil sie zusammen mit dem Token dort liegt, nicht weil sie geheim wäre.
 *
 * **Angemeldet wird mit E-Mail-Adresse und API-Token über Basic-Auth.** So will
 * Jira Cloud es für ein persönliches Token; ein `Bearer` gilt dort nur für
 * OAuth-Zugänge einer installierten App. Das ist unelegant und die einzige
 * Möglichkeit, mit einem Token zu arbeiten, das jemand in seinen Kontoein-
 * stellungen erzeugt hat — und genau das ist der Weg, der ohne
 * Marketplace-App auskommt.
 */
final readonly class JiraClient
{
    /**
     * Die Fassung der Schnittstelle. `3` und nicht `2`, weil erst dort
     * Beschreibungen als Dokument (ADF) entgegengenommen werden — und `2`
     * schreibt sie in einer Auszeichnungssprache, die Atlassian selbst nicht
     * mehr pflegt.
     */
    private const API = '/rest/api/3';

    public function __construct(private Integration $integration) {}

    /**
     * Wem der Zugang gehört.
     *
     * @return array{account: string, external_id: string}
     *
     * @throws TicketException
     */
    public function myself(): array
    {
        $user = $this->get('/myself');

        return [
            'account' => (string) ($user['displayName'] ?? $user['emailAddress'] ?? ''),
            'external_id' => (string) ($user['accountId'] ?? ''),
        ];
    }

    /**
     * Die Projekte, auf die dieser Zugang kommt.
     *
     * `/project/search` und nicht `/project`: das zweite ist abgekündigt und
     * liefert bei großen Instanzen alles auf einmal. Die Obergrenze ist keine
     * Sparsamkeit — eine Auswahlliste mit tausend Projekten ist keine Auswahl
     * mehr.
     *
     * @return list<array{key: string, name: string, id: string}>
     *
     * @throws TicketException
     */
    public function projects(int $limit = 200): array
    {
        $projects = [];
        $startAt = 0;

        while (count($projects) < $limit) {
            $page = $this->get('/project/search', [
                'startAt' => $startAt,
                'maxResults' => 50,
                'orderBy' => 'name',
            ]);

            $values = $page['values'] ?? [];

            if (! is_array($values) || $values === []) {
                break;
            }

            foreach ($values as $project) {
                if (! is_array($project)) {
                    continue;
                }

                $projects[] = [
                    'key' => (string) ($project['key'] ?? ''),
                    'name' => (string) ($project['name'] ?? ''),
                    'id' => (string) ($project['id'] ?? ''),
                ];
            }

            if (($page['isLast'] ?? true) === true) {
                break;
            }

            $startAt += count($values);
        }

        return array_slice($projects, 0, $limit);
    }

    /**
     * Einen Vorgang anlegen.
     *
     * @param  array<string, mixed>  $fields
     * @return array{id: string, key: string}
     *
     * @throws TicketException
     */
    public function createIssue(array $fields): array
    {
        $created = $this->post('/issue', ['fields' => $fields]);

        return [
            'id' => (string) ($created['id'] ?? ''),
            'key' => (string) ($created['key'] ?? ''),
        ];
    }

    /**
     * Einen Vorgang nachschlagen.
     *
     * `fields` ist eingeschränkt, und zwar deutlich: ein Jira-Vorgang bringt
     * ohne diese Angabe je nach Instanz zweihundert Felder mit, von denen hier
     * drei gelesen werden.
     *
     * @return array<mixed>
     *
     * @throws TicketException
     */
    public function issue(string $key): array
    {
        return $this->get('/issue/'.rawurlencode($key), [
            'fields' => 'summary,status,project',
        ]);
    }

    /**
     * Die Übergänge, die von hier aus möglich sind.
     *
     * Sie sind der Grund, dass „Vorgang schließen" bei Jira kein Feld ist,
     * das man setzt: welcher Übergang wohin führt, entscheidet der Arbeitsablauf
     * des Projekts, und der ist je Instanz von Hand gebaut. `expand=transitions.fields`
     * bleibt weg — welche Felder ein Übergang verlangt, wird hier nicht
     * ausgefüllt.
     *
     * @return list<array<mixed>>
     *
     * @throws TicketException
     */
    public function transitions(string $key): array
    {
        $response = $this->get('/issue/'.rawurlencode($key).'/transitions');

        $transitions = $response['transitions'] ?? [];

        return is_array($transitions) ? array_values(array_filter($transitions, 'is_array')) : [];
    }

    /**
     * Einen Übergang ausführen.
     *
     * @throws TicketException
     */
    public function transition(string $key, string $transitionId): void
    {
        $this->post('/issue/'.rawurlencode($key).'/transitions', [
            'transition' => ['id' => $transitionId],
        ]);
    }

    /**
     * Die Adresse, unter der ein Vorgang im Browser liegt.
     *
     * Aus der Adresse der Instanz zusammengesetzt und nicht aus dem `self`-Feld
     * der Antwort: das zeigt auf die Schnittstelle
     * (`…/rest/api/3/issue/10042`) und nicht auf die Seite, die ein Mensch
     * öffnen will.
     */
    public function browseUrl(string $key): string
    {
        return $this->baseUrl().'/browse/'.$key;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws TicketException
     */
    private function get(string $path, array $query = []): array
    {
        try {
            return $this->result($this->request()->get($this->url($path), $query));
        } catch (ConnectionException $e) {
            throw TicketException::failed($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     *
     * @throws TicketException
     */
    private function post(string $path, array $body): array
    {
        try {
            return $this->result($this->request()->post($this->url($path), $body));
        } catch (ConnectionException $e) {
            throw TicketException::failed($e->getMessage());
        }
    }

    /**
     * @throws TicketException
     */
    private function request(): PendingRequest
    {
        $token = $this->integration->token();
        $email = $this->integration->credential('email');

        if ($token === null || $email === null) {
            // Kein Token (oder keine E-Mail-Adresse dazu) heißt: die Anbindung
            // ist nicht benutzbar. Dieselbe Lage wie ein abgelehnter Zugang und
            // dieselbe Behandlung — nur ohne den Aufruf, der sicher scheitert.
            throw TicketException::accessRejected(__('integrations.errors.no_token'));
        }

        return Http::withBasicAuth($email, $token)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.jira.timeout', 10));
    }

    /**
     * @return array<mixed>
     *
     * @throws TicketException
     */
    private function result(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            // Der eine Fall, der nicht von selbst vorbeigeht. Er wird hier
            // festgehalten und nicht nur gemeldet: der nächste Aufruf käme
            // sonst genauso weit, und in der Oberfläche stünde weiterhin
            // „verbunden".
            //
            // Anders als bei GitHub ist `403` hier eindeutig: Jira zählt
            // Aufrufe nicht mit einem Kontingent, das denselben Status
            // benutzt — es antwortet bei Überlast mit `429` und einem
            // `Retry-After`.
            $reason = self::reason($response);

            $this->integration->markDisconnected($reason);

            throw TicketException::accessRejected($reason);
        }

        if ($response->failed()) {
            throw TicketException::failed(self::reason($response));
        }

        $data = $response->json();

        if (! is_array($data)) {
            // Eine Antwort, die kein JSON ist, kommt im Betrieb von
            // Zwischenstationen: der Anmeldeseite eines Proxys, der
            // Wartungsseite von Atlassian. Sie als leere Liste durchzureichen
            // hieße, „diese Instanz hat keine Projekte" zu behaupten.
            throw TicketException::failed(__('integrations.errors.unexpected_response', [
                'provider' => __('enums.integration_provider.jira'),
            ]));
        }

        return $data;
    }

    /**
     * Die Begründung, wie Jira sie schickt.
     *
     * Jira antwortet auf einen Prüffehler mit `errorMessages` (einer Liste) und
     * `errors` (einer Zuordnung Feld → Meldung) — und je nach Fehler ist genau
     * eines von beiden gefüllt. Beide zu lesen ist keine Vorsicht, sondern die
     * Voraussetzung dafür, dass „Field 'priority' cannot be set" überhaupt
     * ankommt (Abnahmekriterium „nicht verschluckt").
     */
    private static function reason(Response $response): string
    {
        $messages = $response->json('errorMessages');
        $parts = is_array($messages) ? array_values(array_filter($messages, 'is_string')) : [];

        $errors = $response->json('errors');

        if (is_array($errors)) {
            foreach ($errors as $field => $message) {
                if (is_string($message) && $message !== '') {
                    $parts[] = is_string($field) ? $field.': '.$message : $message;
                }
            }
        }

        if ($parts === []) {
            $message = $response->json('message');

            if (is_string($message) && $message !== '') {
                $parts[] = $message;
            }
        }

        return $parts === []
            ? __('integrations.errors.http_status', [
                'provider' => __('enums.integration_provider.jira'),
                'status' => $response->status(),
            ])
            : Str::limit(implode(' ', $parts), 200, '');
    }

    private function url(string $path): string
    {
        return $this->baseUrl().self::API.'/'.ltrim($path, '/');
    }

    /**
     * Die Adresse der Instanz, ohne Schrägstrich am Ende.
     */
    private function baseUrl(): string
    {
        return rtrim((string) $this->integration->credential('base_url'), '/');
    }
}
