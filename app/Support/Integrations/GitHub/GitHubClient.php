<?php

namespace App\Support\Integrations\GitHub;

use App\Models\Integration;
use App\Support\Releases\CommitImport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Die eine Stelle, an der mit GitHub gesprochen wird.
 *
 * Alles darüber — Repositories auswählen, Commits einer Auslieferung holen, ein
 * Ticket anlegen — sind Fachfragen und stehen in eigenen Klassen. Hier stehen
 * nur die Aufrufe und das, was für **jeden** von ihnen gilt: Kopfzeilen, Token,
 * Zeitgrenze und die Behandlung dessen, was zurückkommt.
 *
 * **Der abgelehnte Zugang wird hier erkannt und festgehalten.** Das ist der
 * Grund, warum es diese Klasse überhaupt gibt und nicht nur ein paar
 * `Http::withToken()`-Aufrufe verstreut in den Fachklassen: `401` und `403`
 * kommen an jedem einzelnen Aufruf zurück, und die Anbindung als „verloren" zu
 * kennzeichnen, gehört genau dorthin, wo es auffällt — nicht in einen Prüflauf,
 * der es Stunden später bemerkt (Abnahmekriterium „Verbindungsverlust wird
 * erkannt und angezeigt, statt still zu scheitern").
 *
 * Was **nicht** hier steht: Wiederholungen. Ein gescheiterter Aufruf fliegt als
 * {@see GitHubException} heraus, und wer ihn ausgelöst hat, entscheidet, was
 * das bedeutet — die Warteschlange wiederholt einen Auftrag, eine Anfrage aus
 * der Oberfläche zeigt eine Meldung. Beides hier zu entscheiden hieße, den
 * wartenden Menschen vor dem Bildschirm dreimal zehn Sekunden warten zu lassen.
 */
final readonly class GitHubClient
{
    public function __construct(private Integration $integration) {}

    /**
     * Wem der Zugang gehört — der Aufruf direkt nach dem Anmelden.
     *
     * @return array{login: string, id: string}
     */
    public function viewer(): array
    {
        $user = $this->get('/user');

        return [
            'login' => (string) ($user['login'] ?? ''),
            'id' => (string) ($user['id'] ?? ''),
        ];
    }

    /**
     * Die Repositories, auf die dieser Zugang schreibend kommt.
     *
     * `affiliation` nennt die drei Wege, auf denen jemand an ein Repository
     * kommt; ohne die Angabe liefert GitHub nur die eigenen. Gefiltert wird auf
     * Schreibrecht, und das ist keine Bevormundung: aus einem Fehler ein Ticket
     * anzulegen ist der halbe Zweck der Anbindung, und ein Repository in der
     * Auswahl, in dem das später an einem `403` scheitert, ist eine Falle.
     *
     * @return list<array{name: string, external_id: string, url: string, private: bool}>
     */
    public function repositories(int $limit = 200): array
    {
        $repositories = [];
        $page = 1;

        // Seitenweise, bis genug beisammen ist oder GitHub nichts mehr hat.
        // Die Obergrenze ist keine Sparsamkeit: es gibt Konten mit einigen
        // tausend Repositories, und eine Auswahlliste dieser Länge ist keine
        // Auswahl mehr — wer so viele hat, sucht sein Repository, statt es zu
        // suchen.
        while (count($repositories) < $limit) {
            $batch = $this->get('/user/repos', [
                'affiliation' => 'owner,collaborator,organization_member',
                'sort' => 'pushed',
                'per_page' => 100,
                'page' => $page,
            ]);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $repository) {
                if (! is_array($repository)) {
                    continue;
                }

                if (($repository['permissions']['push'] ?? false) !== true) {
                    continue;
                }

                $repositories[] = [
                    'name' => (string) ($repository['full_name'] ?? ''),
                    'external_id' => (string) ($repository['id'] ?? ''),
                    'url' => (string) ($repository['html_url'] ?? ''),
                    'private' => (bool) ($repository['private'] ?? false),
                ];
            }

            if (count($batch) < 100) {
                break;
            }

            $page++;
        }

        return array_slice($repositories, 0, $limit);
    }

    /**
     * Die Commits zwischen zwei Ständen — der Aufruf hinter „was steckt in
     * dieser Auslieferung?".
     *
     * GitHub beantwortet das in **einem** Aufruf, samt Dateiliste; sie einzeln
     * abzuholen wären dreihundert Aufrufe für eine Auslieferung. Die Liste ist
     * bei 250 Commits gedeckelt (so viel gibt GitHub in einem Vergleich
     * heraus) — mehr wäre für die Frage „was ist neu" ohnehin keine Antwort
     * mehr, sondern die halbe Geschichte des Repositories.
     *
     * @return list<array<string, mixed>> Commits in der Form, die {@see CommitImport} erwartet
     */
    public function compare(string $repository, string $base, string $head): array
    {
        $comparison = $this->get('/repos/'.$repository.'/compare/'.rawurlencode($base).'...'.rawurlencode($head));

        return GitHubCommitPayload::fromList($comparison['commits'] ?? [], $repository);
    }

    /**
     * Die jüngsten Commits bis zu einem Stand — der Weg, wenn es keinen
     * Vorgänger zum Vergleichen gibt.
     *
     * Also bei der ersten Auslieferung, die über die Anbindung hereinkommt. Die
     * Liste ist kurz gehalten: „alles seit Beginn der Zeit" wäre die technisch
     * naheliegende und die unbrauchbare Antwort — niemand liest die 4.000
     * Commits eines Repositories als Inhalt einer Version.
     *
     * @return list<array<string, mixed>>
     */
    public function commits(string $repository, string $head, int $limit = 50): array
    {
        $commits = $this->get('/repos/'.$repository.'/commits', [
            'sha' => $head,
            'per_page' => max(1, min($limit, 100)),
        ]);

        return GitHubCommitPayload::fromList($commits, $repository);
    }

    /**
     * Die Dateien eines einzelnen Commits.
     *
     * Der Vergleich oben liefert sie nicht mit — er nennt die Dateien der
     * gesamten Spanne, nicht die je Commit. Für den verdächtigen Commit (R4)
     * ist aber genau das die Frage, und deshalb gibt es diesen Aufruf: er wird
     * je Commit gemacht und ist damit der teuerste in dieser Klasse.
     *
     * @return array<string, mixed>
     */
    public function commit(string $repository, string $sha): array
    {
        return GitHubCommitPayload::one($this->get('/repos/'.$repository.'/commits/'.$sha), $repository);
    }

    /**
     * Ein neues Ticket anlegen.
     *
     * @return array{number: int, url: string, title: string, state: string}
     */
    public function createIssue(string $repository, string $title, string $body): array
    {
        return self::issuePayload($this->post('/repos/'.$repository.'/issues', [
            'title' => $title,
            'body' => $body,
        ]));
    }

    /**
     * Ein vorhandenes Ticket nachschlagen — beim Verknüpfen von Hand.
     *
     * Der Aufruf ist die Prüfung: eine Nummer, die es drüben nicht gibt, soll
     * nicht als Verknüpfung enden, die ins Leere zeigt.
     *
     * @return array{number: int, url: string, title: string, state: string}
     */
    public function issue(string $repository, int $number): array
    {
        return self::issuePayload($this->get('/repos/'.$repository.'/issues/'.$number));
    }

    /**
     * Einen Webhook einrichten — oder den vorhandenen stehen lassen.
     *
     * Wiederholbar, weil das Verbinden eines Repositories wiederholt wird: wer
     * es löst und neu auswählt, soll nicht zwei Hooks hinterlassen, die
     * dieselbe Meldung doppelt schicken. Erkannt wird der eigene an seiner
     * Adresse.
     *
     * Ein Fehlschlag ist hier **kein** Grund, das Verbinden scheitern zu
     * lassen: ohne Hook fehlt der Abgleich, aber Commits holen und Tickets
     * anlegen gehen weiter. Das entscheidet allerdings der Aufrufer — hier
     * fliegt die Ausnahme wie überall.
     */
    public function ensureWebhook(string $repository, string $url, string $secret): void
    {
        foreach ($this->get('/repos/'.$repository.'/hooks') as $hook) {
            if (is_array($hook) && ($hook['config']['url'] ?? null) === $url) {
                return;
            }
        }

        $this->post('/repos/'.$repository.'/hooks', [
            'name' => 'web',
            'active' => true,
            // Push für die Commits, `issues` für den Zustandsabgleich. Mehr
            // nicht: ein Hook, der alles abonniert, schickt bei einem regen
            // Repository tausende Meldungen am Tag, die hier keine Zeile Code
            // auswertet.
            'events' => ['push', 'issues'],
            'config' => [
                'url' => $url,
                'content_type' => 'json',
                'secret' => $secret,
                'insecure_ssl' => '0',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send($path, $query, post: false);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->send($path, $body, post: true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    private function send(string $path, array $data, bool $post): array
    {
        $token = $this->integration->token();

        if ($token === null) {
            // Kein Token heißt: die Anbindung ist nicht (mehr) benutzbar. Das
            // ist dieselbe Lage wie ein abgelehnter Zugang und wird auch so
            // behandelt — nur ohne den Aufruf, der sicher scheitert.
            throw GitHubException::accessRejected(__('integrations.errors.no_token'));
        }

        $request = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout((int) config('services.github.timeout', 10));

        try {
            $response = $post
                ? $request->post($this->url($path), $data)
                : $request->get($this->url($path), $data);
        } catch (ConnectionException $e) {
            // Netz weg, Zeitüberschreitung: eine Störung und keine Ablehnung.
            // Die Anbindung bleibt verbunden — sie ist es ja.
            throw GitHubException::failed($e->getMessage());
        }

        return $this->result($response);
    }

    /**
     * @return array<mixed>
     */
    private function result(Response $response): array
    {
        if (self::isAccessRejected($response)) {
            // Der eine Fall, der nicht von selbst vorbeigeht. Er wird hier
            // festgehalten und nicht nur gemeldet: der nächste Aufruf käme
            // sonst genauso weit, und in der Oberfläche stünde weiterhin
            // „verbunden".
            $reason = self::reason($response);

            $this->integration->markDisconnected($reason);

            throw GitHubException::accessRejected($reason);
        }

        if ($response->failed()) {
            throw GitHubException::failed(self::reason($response));
        }

        $data = $response->json();

        if (! is_array($data)) {
            // Eine Antwort, die kein JSON-Objekt ist, kommt im Betrieb von
            // Zwischenstationen (Anmeldeseite eines Proxys, Wartungsseite).
            // Sie als leere Liste durchzureichen hieße, „das Repository hat
            // keine Commits" zu behaupten.
            throw GitHubException::failed(__('integrations.errors.unexpected_response'));
        }

        return $data;
    }

    /**
     * Hat GitHub den **Zugang** abgelehnt — oder nur diesen Aufruf?
     *
     * `401` ist eindeutig: das Token gilt nicht. `403` ist es nicht, und das
     * ist die Falle, um derentwillen diese Methode existiert: GitHub antwortet
     * mit demselben Status auf „du darfst hier nicht schreiben" **und** auf
     * „du hast zu viele Aufrufe gemacht". Das zweite geht in einer Stunde von
     * selbst vorbei — es als verlorenen Zugang zu führen hieße, jemanden zum
     * Neu-Verbinden zu schicken, weil eine Auslieferung zu viele Commits
     * hatte.
     *
     * Erkannt wird die Begrenzung an dem, was GitHub dafür mitschickt: der
     * Zähler der übrigen Aufrufe steht auf null, oder es steht in der Meldung.
     */
    private static function isAccessRejected(Response $response): bool
    {
        if ($response->status() === 401) {
            return true;
        }

        if ($response->status() !== 403) {
            return false;
        }

        if ($response->header('X-RateLimit-Remaining') === '0') {
            return false;
        }

        $message = (string) $response->json('message');

        return ! Str::contains($message, ['rate limit', 'abuse detection', 'secondary rate'], ignoreCase: true);
    }

    private function url(string $path): string
    {
        return Str::finish((string) config('services.github.api_url', 'https://api.github.com'), '/')
            .ltrim($path, '/');
    }

    /**
     * Die Begründung, wie GitHub sie schickt — gekürzt auf das, was in eine
     * Anzeige passt.
     *
     * Nur `message`, nie der ganze Rumpf: der enthält bei Prüffehlern die
     * gesendeten Daten, und bei einem Ticket-Aufruf steckt darin der Inhalt
     * des Tickets.
     */
    private static function reason(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== ''
            ? Str::limit($message, 200, '')
            : __('integrations.errors.http_status', ['status' => $response->status()]);
    }

    /**
     * @param  array<mixed>  $issue
     * @return array{number: int, url: string, title: string, state: string}
     */
    private static function issuePayload(array $issue): array
    {
        return [
            'number' => (int) ($issue['number'] ?? 0),
            'url' => (string) ($issue['html_url'] ?? ''),
            'title' => (string) ($issue['title'] ?? ''),
            'state' => (string) ($issue['state'] ?? 'open'),
        ];
    }
}
