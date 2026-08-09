<?php

namespace App\Support\Integrations\GitHub;

use App\Enums\ExternalIssueState;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationWebhookEvent;
use App\Models\Organization;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Die Annahme eingehender Meldungen von GitHub — Unterschrift prüfen, einmalig
 * festhalten, zuordnen.
 *
 * Getrennt von der Verarbeitung ({@see GitHubWebhookProcessor}), weil beides zu
 * verschiedenen Zeiten geschieht: die Annahme läuft in der Anfrage und muss
 * schnell antworten (GitHub gibt zehn Sekunden, danach gilt die Zustellung als
 * fehlgeschlagen), die Verarbeitung läuft in der Warteschlange.
 *
 * **Wiederholbar ist die Annahme, nicht die Verarbeitung.** GitHub stellt
 * dieselbe Meldung erneut zu, wenn die Antwort ausbleibt, und wiederholt sie
 * auf Knopfdruck. Der eindeutige Index über `(provider, delivery_id)`
 * entscheidet, wer die erste ist — alle weiteren finden ihre Zeile vor, und die
 * Verarbeitung läuft genau einmal. Das ist der einfachere Weg, als jeden
 * einzelnen Verarbeitungsschritt für sich wiederholbar zu bauen.
 */
final class GitHubWebhook
{
    /**
     * Ob die Anfrage von GitHub stammt.
     *
     * **Ohne eingerichtetes Geheimnis wird abgewiesen** — nicht durchgelassen.
     * Der Endpunkt nimmt sonst von jedem alles an: „schließe Ticket 42" ist
     * eine Meldung, die einen Fehler hier auf erledigt setzt, und niemand
     * braucht dafür mehr als die Adresse. Das ist der Unterschied zu einem
     * reinen Protokoll, bei dem man in der Anlaufphase großzügig sein kann.
     */
    public static function verify(Request $request): bool
    {
        $secret = trim((string) config('services.github.webhook_secret'));

        if ($secret === '') {
            return false;
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($signature === '') {
            return false;
        }

        // Über den **rohen** Rumpf, nicht über wieder eingelesenes und neu
        // serialisiertes JSON — dieselbe Regel, die die eigenen Webhooks ihren
        // Empfängern mitgeben (siehe docs/webhooks.md), und aus demselben
        // Grund: die Zeichenkette geht sonst nicht mehr auf.
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Die Meldung festhalten — oder feststellen, dass sie schon da war.
     *
     * @return array{event: IntegrationWebhookEvent, fresh: bool}
     */
    public static function record(Request $request): array
    {
        $payload = $request->json()->all();

        if (! is_array($payload)) {
            $payload = [];
        }

        $repository = data_get($payload, 'repository.full_name');
        $repository = is_string($repository) ? Repository::normalizeName($repository) : null;

        $event = IntegrationWebhookEvent::query()->firstOrCreate(
            [
                'provider' => IntegrationProvider::GitHub->value,
                // Fehlt die Kennung, wird eine erfunden: sonst käme jede
                // Meldung ohne Kopfzeile als „schon bekannt" durch und würde
                // nie verarbeitet. Eine erfundene Kennung heißt „einmal
                // verarbeiten und nicht wiedererkennen" — die richtige Wahl,
                // wenn man nichts hat, woran man sie erkennen könnte.
                'delivery_id' => self::deliveryId($request),
            ],
            [
                'organization_id' => self::organizationFor($repository)?->id,
                'event' => Str::limit((string) $request->header('X-GitHub-Event', 'unknown'), 50, ''),
                'action' => self::action($payload),
                'repository' => $repository,
                'payload' => $payload,
            ],
        );

        return ['event' => $event, 'fresh' => $event->wasRecentlyCreated];
    }

    /**
     * Die Organisation, die dieses Repository verbunden hat.
     *
     * Über das Repository und nicht über die Anbindung: die Meldung nennt kein
     * Konto, sondern ein Repository — und genau darüber ist sie eindeutig
     * zuzuordnen, solange nicht zwei Organisationen dasselbe verbunden haben.
     * Für den Fall gewinnt die ältere Verbindung; eine Meldung zweimal
     * auszuwerten wäre die schlechtere Antwort, weil dann ein Ticket in einem
     * gemeinsam genutzten Repository Fehler in einer fremden Organisation
     * erledigen könnte.
     */
    private static function organizationFor(?string $repository): ?Organization
    {
        if ($repository === null) {
            return null;
        }

        return Repository::query()
            ->where('name', $repository)
            ->whereNotNull('integration_id')
            ->orderBy('id')
            ->first()?->organization;
    }

    private static function deliveryId(Request $request): string
    {
        $delivery = trim((string) $request->header('X-GitHub-Delivery', ''));

        return $delivery === '' ? (string) Str::uuid() : Str::limit($delivery, 100, '');
    }

    /**
     * @param  array<mixed>  $payload
     */
    private static function action(array $payload): ?string
    {
        $action = $payload['action'] ?? null;

        return is_string($action) && $action !== '' ? Str::limit($action, 50, '') : null;
    }

    /**
     * Der Zustand, den ein `issues`-Ereignis meldet.
     *
     * Aus dem Ticket selbst und nicht aus der Unterart: `action` sagt
     * `closed`/`reopened`/`edited`, und für `edited` steht dort nichts über den
     * Zustand — der steht im Ticket, und zwar in jedem Ereignis.
     *
     * @param  array<mixed>  $payload
     */
    public static function issueState(array $payload): ExternalIssueState
    {
        $state = data_get($payload, 'issue.state');

        return ExternalIssueState::fromInput(is_string($state) ? $state : null);
    }
}
