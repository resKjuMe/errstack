<?php

namespace App\Support\Integrations\GitHub;

use App\Enums\CommitFileChange;
use App\Support\Releases\CommitImport;
use App\Support\Releases\PatchLines;

/**
 * Übersetzt einen Commit, wie GitHub ihn ausliefert, in die Form, die
 * {@see CommitImport} entgegennimmt.
 *
 * Das ist der Grund, warum die Anbindung keine eigene Ablage für Commits
 * braucht: sie holt sie und legt sie **denselben** Weg entlang ab, den eine
 * Bauumgebung über die Schnittstelle nimmt. „Was steckt in dieser Version" hat
 * damit eine Antwort und nicht zwei, die sich in Kleinigkeiten unterscheiden.
 *
 * Die Zielform sind die Feldnamen von sentry-cli (`id`, `patch_set`, `type`) —
 * sie ist die Schnittstelle nach innen, nicht bloß das Format eines fremden
 * Werkzeugs.
 */
final class GitHubCommitPayload
{
    /**
     * @param  mixed  $commits  die Liste, wie sie in der Antwort steht
     * @return list<array<string, mixed>>
     */
    public static function fromList(mixed $commits, string $repository): array
    {
        if (! is_array($commits)) {
            return [];
        }

        $payloads = [];

        foreach ($commits as $commit) {
            if (! is_array($commit)) {
                continue;
            }

            $payload = self::one($commit, $repository);

            if ($payload['id'] === '') {
                continue;
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }

    /**
     * Ein einzelner Commit.
     *
     * **Der Autor kommt aus `commit.author` und nicht aus `author`.** Die
     * beiden sehen ähnlich aus und sind es nicht: das eine ist der Autor, wie
     * er im Commit steht — Name und E-Mail, die Git kennt —, das andere das
     * GitHub-Konto, dem GitHub ihn zuordnet. Für die Zuordnung zu einem Konto
     * hier zählt die E-Mail-Adresse (siehe `CommitImport`), und die steht am
     * GitHub-Konto gar nicht drin, wenn jemand sie privat hält.
     *
     * @param  array<mixed>  $commit
     * @return array<string, mixed>
     */
    public static function one(array $commit, string $repository): array
    {
        $author = is_array($commit['commit']['author'] ?? null) ? $commit['commit']['author'] : [];

        $payload = [
            'id' => (string) ($commit['sha'] ?? ''),
            'repository' => $repository,
            'message' => (string) ($commit['commit']['message'] ?? ''),
            'author_name' => self::text($author['name'] ?? null),
            'author_email' => self::text($author['email'] ?? null),
            'timestamp' => self::text($author['date'] ?? null),
        ];

        // Die Dateiliste nur, wenn sie mitkam. Der Unterschied ist wichtig: ein
        // fehlendes `patch_set` lässt eine bereits bekannte Liste stehen, ein
        // leeres würde sie löschen (siehe `CommitImport::storeFiles()`). Der
        // Vergleich zweier Stände liefert die Dateien **nicht** je Commit — sie
        // dort als „keine" zu übergeben, wäre eine Falschaussage.
        if (is_array($commit['files'] ?? null)) {
            $payload['patch_set'] = self::files($commit['files']);
        }

        return $payload;
    }

    /**
     * Die berührten Dateien.
     *
     * `patch` wird durchgereicht, wie GitHub es schickt — der Unterschied im
     * üblichen Format. Daraus rechnet {@see PatchLines}
     * die geänderten Zeilenbereiche aus, und die sind es, an denen der
     * verdächtige Commit (R4) einen Stacktrace festmacht. Ohne sie bliebe der
     * Abgleich beim Dateinamen stehen.
     *
     * @param  array<mixed>  $files
     * @return list<array<string, mixed>>
     */
    private static function files(array $files): array
    {
        $entries = [];

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $path = self::text($file['filename'] ?? null);

            if ($path === null) {
                continue;
            }

            $entries[] = [
                'path' => $path,
                'type' => self::changeType(self::text($file['status'] ?? null)),
                'patch' => self::text($file['patch'] ?? null),
            ];
        }

        return $entries;
    }

    /**
     * GitHubs Wort für die Änderung in den Buchstaben, den alles andere
     * spricht.
     *
     * `renamed` wird zu `M`: eine Umbenennung ist für die Frage „welcher Commit
     * hat diese Datei angefasst" eine Änderung an ihr — und sie als
     * Hinzufügung zu führen hieße, dass der Commit die Datei erfunden hat.
     * Unbekanntes bleibt leer und fällt drüben auf den Vorgabewert zurück
     * (siehe {@see CommitFileChange}).
     */
    private static function changeType(?string $status): ?string
    {
        return match ($status) {
            'added', 'copied' => 'A',
            'removed' => 'D',
            'modified', 'changed', 'renamed' => 'M',
            default => null,
        };
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
