<?php

namespace App\Support\Releases;

use App\Enums\CommitFileChange;
use App\Models\Commit;
use App\Models\CommitFile;
use App\Models\Organization;
use App\Models\Release;
use App\Models\Repository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Commits übernehmen und einer Auslieferung zuordnen.
 *
 * Der eine Weg, auf dem Commits in diese Anwendung kommen — gleich, ob sie eine
 * Bauumgebung über die Schnittstelle übergibt oder später eine Anbindung
 * (X1/X2) sie abholt. Beide landen hier, damit „was steckt in dieser Version"
 * nicht zwei Antworten hat, die sich in Kleinigkeiten unterscheiden.
 *
 * **Die Übergabe setzt die Liste, sie ergänzt sie nicht.** Das ist die
 * Entscheidung, an der alles andere hängt: der Aufruf steht in einer
 * Auslieferungs-Pipeline, und die läuft bei einem Fehlschlag noch einmal. Beim
 * Ergänzen stünde nach dem zweiten Lauf jeder Commit doppelt — beim Setzen
 * steht danach genau das da, was der letzte Lauf geschickt hat. Wiederholbar
 * heißt hier also nicht „tut beim zweiten Mal nichts", sondern „führt beim
 * zweiten Mal zum selben Ergebnis".
 *
 * Die Commits selbst werden dabei **nicht** gelöscht, nur ihre Zuordnung: ein
 * Commit gehört seinem Repository und steckt womöglich in einer anderen
 * Auslieferung.
 */
final class CommitImport
{
    /**
     * Die Prüfregeln einer Übergabe — dort, wo auch das Lesen steht.
     *
     * Die Feldnamen sind die von sentry-cli (`id`, `patch_set`, `type`), damit
     * vorhandene Auslieferungs-Skripte unverändert damit sprechen (X6).
     *
     * Sie stehen hier und nicht im Controller, weil es **zwei** Wege gibt, auf
     * denen dieselbe Liste ankommt: beim Ankündigen einer Version und beim
     * späteren Nachreichen. Zwei Fassungen derselben Regeln würden über kurz
     * oder lang auseinanderlaufen — und der Unterschied fiele erst auf, wenn
     * eine Pipeline über den einen Weg durchkommt und über den anderen nicht.
     *
     * Die Regel für `commits` selbst fehlt mit Absicht: ob die Liste da sein
     * muss, entscheidet der Aufrufer — beim Nachreichen ja, beim Ankündigen
     * einer Version ist sie freiwillig.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            // Der Vorgabewert für alle Einträge, die kein eigenes Repository
            // nennen. Der übliche Fall ist genau der: ein Baulauf kommt aus
            // **einem** Repository.
            'repository' => ['nullable', 'string', 'max:'.Repository::NAME_LIMIT],

            'commits.*.id' => ['required', 'string', 'max:'.Commit::SHA_LIMIT],

            // Entweder oben für alle oder hier für jeden einzelnen — aber
            // irgendwo. Ohne diese Regel landete eine Übergabe ohne
            // Repository-Angabe wortlos im Nichts: die Antwort wäre eine leere
            // Liste, und in der Pipeline sähe das aus wie ein Erfolg.
            'commits.*.repository' => ['required_without:repository', 'nullable', 'string', 'max:'.Repository::NAME_LIMIT],

            'commits.*.message' => ['nullable', 'string'],
            'commits.*.author_name' => ['nullable', 'string', 'max:200'],
            'commits.*.author_email' => ['nullable', 'string', 'max:254'],
            'commits.*.timestamp' => ['nullable', 'date'],

            'commits.*.patch_set' => ['nullable', 'array'],
            'commits.*.patch_set.*.path' => ['required', 'string', 'max:500'],

            // Nachsichtig gegenüber der Schreibweise, streng gegenüber dem
            // Wert: ein Buchstabe, den niemand kennt, wäre eine Angabe über die
            // Änderung, die sich später nicht mehr von einer echten
            // unterscheiden ließe.
            'commits.*.patch_set.*.type' => ['nullable', Rule::in(['A', 'M', 'D', 'a', 'm', 'd'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'repository' => 'Repository',
            'commits' => 'Commits',
            'commits.*.id' => 'Commit-Hash',
            'commits.*.repository' => 'Repository',
            'commits.*.patch_set.*.path' => 'Dateipfad',
        ];
    }

    /**
     * Übernimmt die Commits einer Auslieferung.
     *
     * @param  list<array<string, mixed>>  $commits  Rohdaten in der Form, in der
     *                                               sie über die Schnittstelle
     *                                               ankommen (siehe {@see one()}).
     * @return list<Commit> die übernommenen Commits in der übergebenen Reihenfolge
     */
    public static function into(Release $release, array $commits, ?string $defaultRepository = null): array
    {
        $release->loadMissing('project.organization');

        $organization = $release->project?->organization;

        if (! $organization instanceof Organization) {
            // Ohne Organisation gibt es kein Repository, an dem ein Commit
            // hängen könnte. Der Fall entsteht nicht im Betrieb — er steht hier,
            // damit eine Auslieferung ohne Projekt keine halbe Übernahme
            // hinterlässt.
            return [];
        }

        $authors = self::authorsOf($organization);

        return DB::transaction(function () use ($release, $commits, $defaultRepository, $organization, $authors): array {
            $stored = [];
            $links = [];
            $position = 0;

            // Ein eigener Zähler und nicht der Schlüssel der Übergabe: als JSON
            // darf `commits` auch ein Objekt sein, und dann wären die Schlüssel
            // Zeichenketten — eine davon in der Spalte `position` ließe den
            // ganzen Aufruf an der Datenbank scheitern. Er zählt außerdem nur
            // die tatsächlich übernommenen, damit übersprungene Einträge keine
            // Lücken hinterlassen.
            foreach ($commits as $payload) {
                $commit = self::one($organization, $payload, $defaultRepository, $authors);

                if ($commit === null) {
                    continue;
                }

                // Nach Kennung abgelegt und nicht angehängt: schickt eine
                // Bauumgebung denselben Commit zweimal (ein Cherry-Pick, der
                // zweimal im Bereich landet), ist das keine zweite Zuordnung —
                // und in der Antwort soll er auch nur einmal stehen.
                if (array_key_exists($commit->id, $links)) {
                    continue;
                }

                $stored[] = $commit;
                $links[$commit->id] = ['position' => $position++];
            }

            $release->commits()->sync($links);

            return $stored;
        });
    }

    /**
     * Ein einzelner Commit.
     *
     * Erwartete Felder — die Namen sind die von sentry-cli, damit vorhandene
     * Auslieferungs-Skripte unverändert damit sprechen (X6):
     *
     *   id          der Hash (Pflicht)
     *   repository  Name des Repositories; ohne ihn gilt `$defaultRepository`
     *   message     die vollständige Commit-Nachricht
     *   author_name, author_email
     *   timestamp   wann der Commit entstand
     *   patch_set   Liste aus {path, type} — die berührten Dateien
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, User>  $authors
     */
    private static function one(
        Organization $organization,
        array $payload,
        ?string $defaultRepository,
        Collection $authors,
    ): ?Commit {
        $sha = trim((string) ($payload['id'] ?? ''));

        if ($sha === '' || strlen($sha) > Commit::SHA_LIMIT) {
            // Ohne Hash ist es kein Commit, und ein zu langer ist keiner, den
            // wir vollständig aufbewahren könnten — gekürzt wäre er ein
            // anderer. Übersprungen und nicht abgewiesen: die Prüfung an der
            // Schnittstelle hat das bereits beanstandet, hier ist es die
            // letzte Sicherung.
            return null;
        }

        $repositoryName = Repository::normalizeName(
            is_string($payload['repository'] ?? null) ? $payload['repository'] : $defaultRepository,
        );

        if ($repositoryName === null) {
            return null;
        }

        $repository = Repository::forName($organization, $repositoryName);

        // Nur ausdrücklich mitgeschickte Felder — wie beim Ankündigen einer
        // Version und aus demselben Grund. Der Unterschied zeigt sich bei der
        // zweiten Übergabe: eine Pipeline, die beim ersten Lauf die vollen
        // Angaben geschickt hat und beim zweiten nur noch die Hashes (weil sie
        // die Liste einer Auslieferung zurechtrückt), würde Nachricht, Autor
        // und Zeitpunkt sonst wieder leeren. Ein Commit ist Geschichte des
        // Repositories — was einmal über ihn bekannt war, geht nicht dadurch
        // verloren, dass jemand ihn erneut nennt.
        $values = [];

        if (array_key_exists('message', $payload)) {
            $values['message'] = self::text($payload['message']);
        }

        if (array_key_exists('author_name', $payload)) {
            $values['author_name'] = self::limited($payload['author_name'], 200);
        }

        if (array_key_exists('author_email', $payload)) {
            $email = self::normalizeEmail($payload['author_email']);

            $values['author_email'] = $email;
            // Die Zuordnung geschieht beim Übernehmen und nicht beim Anzeigen:
            // sie ist eine Aussage über den Stand der Mitgliedschaften zu
            // diesem Zeitpunkt, und beim Anzeigen wäre sie eine Abfrage je
            // Zeile. Sie hängt an der Adresse und wird deshalb genau dann neu
            // gefällt, wenn die Adresse mitkommt.
            $values['author_id'] = self::matchAuthor($authors, $email);
        }

        if (array_key_exists('timestamp', $payload)) {
            $values['committed_at'] = self::timestamp($payload['timestamp']);
        }

        $commit = Commit::query()->updateOrCreate(
            [
                'repository_id' => $repository->id,
                'sha' => $sha,
            ],
            $values,
        );

        self::storeFiles($commit, $payload['patch_set'] ?? null);

        return $commit;
    }

    /**
     * Die berührten Dateien eines Commits.
     *
     * **Fehlt die Angabe, bleibt die vorhandene stehen.** Der Unterschied
     * zeigt sich bei der zweiten Übergabe desselben Commits: eine Pipeline, die
     * beim ersten Lauf die Dateien mitgeschickt hat und beim zweiten nur die
     * Hashes, soll die Liste nicht leeren. Eine **leere** Liste ist dagegen
     * eine Angabe — sie sagt „keine Dateien" und wird übernommen.
     */
    private static function storeFiles(Commit $commit, mixed $patchSet): void
    {
        if (! is_array($patchSet)) {
            return;
        }

        $rows = [];

        foreach ($patchSet as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $path = self::limited($entry['path'] ?? null, 500);

            if ($path === null) {
                continue;
            }

            // Nach Pfad abgelegt und nicht angehängt: dieselbe Datei zweimal in
            // einem Commit gibt es nicht, und der eindeutige Index würde die
            // Übergabe sonst mitten im Schreiben abweisen.
            $rows[$path] = [
                'commit_id' => $commit->id,
                'path' => $path,
                'change_type' => CommitFileChange::fromInput(
                    is_string($entry['type'] ?? null) ? $entry['type'] : null,
                )->value,
            ];
        }

        // Ersetzen und nicht ergänzen — aus demselben Grund wie bei der
        // Zuordnung zur Auslieferung: der zweite Lauf derselben Pipeline soll
        // dieselbe Dateiliste hinterlassen und keine doppelte.
        $commit->files()->delete();

        if ($rows !== []) {
            CommitFile::query()->insert(array_values($rows));
        }
    }

    /**
     * Die Konten, denen sich eine Autoren-Adresse zuordnen lässt.
     *
     * Nur Mitglieder der Organisation, und das ist keine Sparmaßnahme: die
     * Adresse in einem Commit ist eine Angabe aus dem Repository und kein
     * Nachweis. Sie auf jedes Konto dieser Anwendung zu beziehen hieße, dass
     * ein beliebiger Baulauf einer fremden Organisation einen Namen anheften
     * kann, den er sich ausgesucht hat.
     *
     * Einmal geladen und nicht je Commit abgefragt: eine Auslieferung bringt
     * gern dreihundert Commits mit, die Mitgliederliste hat ein paar Dutzend
     * Zeilen.
     *
     * @return Collection<int, User>
     */
    private static function authorsOf(Organization $organization): Collection
    {
        return User::query()
            ->select(['id', 'email'])
            ->whereHas(
                'memberships',
                fn ($memberships) => $memberships->where('organization_id', $organization->id),
            )
            ->get();
    }

    /**
     * @param  Collection<int, User>  $authors
     */
    private static function matchAuthor(Collection $authors, ?string $email): ?int
    {
        if ($email === null) {
            return null;
        }

        $needle = Str::lower($email);

        $match = $authors->first(
            fn (User $user): bool => Str::lower((string) $user->email) === $needle,
        );

        return $match?->id;
    }

    private static function normalizeEmail(mixed $value): ?string
    {
        return self::limited(is_string($value) ? Str::lower(trim($value)) : null, 254);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function limited(mixed $value, int $limit): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        return Str::limit($value, $limit, '');
    }

    /**
     * Der Zeitpunkt eines Commits, nachsichtig gelesen.
     *
     * Eine unbrauchbare Angabe wird zu `null` und nicht zu einem Fehler: sie
     * kostet die Sortierung dieses einen Commits, aber nicht den Baulauf. Was
     * eine gültige Zeit ist, hat die Prüfung an der Schnittstelle bereits
     * gesagt — hier steht nur, was passiert, wenn doch etwas durchkommt.
     */
    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
