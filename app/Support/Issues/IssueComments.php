<?php

namespace App\Support\Issues;

use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\IssueCommentMention;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kommentare schreiben, ändern und löschen — die einzige Stelle, die das darf.
 *
 * Dieselbe Regel wie beim Aktivitätsverlauf und beim Änderungsprotokoll, hier
 * aber aus einem handfesteren Grund: an einem Kommentar hängen die Nennungen,
 * und an den Nennungen hängen Benachrichtigungen. Ein `IssueComment::create()`
 * an anderer Stelle wäre ein Kommentar, von dem der Genannte nie erfährt — und
 * das fällt niemandem auf, weil nichts fehlschlägt.
 *
 * **Beim Bearbeiten wird nur benachrichtigt, wer neu hinzugekommen ist.** Das
 * ist der eigentliche Zweck der festgehaltenen Nennungen: ohne sie wäre jede
 * Korrektur eines Tippfehlers eine zweite Benachrichtigung an dieselben Leute,
 * und nach der dritten schaltet der Genannte die Nennungen ab.
 */
final class IssueComments
{
    public function __construct(private readonly IssueMentionNotifier $notifier) {}

    /**
     * Ein neuer Kommentar.
     */
    public function create(Issue $issue, User $author, string $body): IssueComment
    {
        $organization = $issue->project?->organization;

        $mentions = $organization === null ? [] : Mentions::resolve($body, $organization);

        $comment = DB::transaction(function () use ($issue, $author, $body, $mentions): IssueComment {
            /** @var IssueComment $comment */
            $comment = IssueComment::query()->create([
                'issue_id' => $issue->id,
                'project_id' => $issue->project_id,
                'user_id' => $author->id,
                // Der Name zum Zeitpunkt des Schreibens — siehe IssueComment.
                'author_name' => $author->name,
                'body' => $body,
            ]);

            $this->store($comment, $mentions);

            return $comment;
        });

        // Erst nach dem Festschreiben: die Zustellung läuft in der
        // Warteschlange, und ein Arbeiter, der den Kommentar noch nicht sieht,
        // hätte nichts zu berichten.
        $this->notifier->send($comment->setRelation('issue', $issue), $mentions, $author);

        return $comment;
    }

    /**
     * Ein geänderter Kommentar.
     *
     * Ein unveränderter Rumpf ist kein Bearbeiten: wer das Feld öffnet und
     * wieder speichert, soll den Vermerk „bearbeitet" nicht auslösen — er ist
     * eine Aussage an den Leser und keine Buchführung über Formularabsendungen.
     */
    public function update(IssueComment $comment, string $body): IssueComment
    {
        if ($body === $comment->body) {
            return $comment;
        }

        $organization = $comment->issue?->project?->organization;

        $mentions = $organization === null ? [] : Mentions::resolve($body, $organization);

        $fresh = DB::transaction(function () use ($comment, $body, $mentions): array {
            $comment->forceFill([
                'body' => $body,
                'edited_at' => Carbon::now(),
            ])->save();

            return $this->store($comment, $mentions);
        });

        $this->notifier->send($comment, $fresh, $comment->author);

        return $comment;
    }

    /**
     * Ein gelöschter Kommentar — mitsamt seinen Nennungen.
     *
     * Ohne Grabstein in der Zeitleiste: „hier stand einmal etwas" beantwortet
     * keine Frage und lädt dazu ein, aus dem Umfeld zu erraten, was es war. Wer
     * einen Kommentar zurücknimmt, nimmt ihn zurück. Was am **Fehler**
     * geschehen ist, steht ohnehin unveränderlich daneben — dort ist der Beleg
     * zu Hause, nicht in den Wortmeldungen.
     */
    public function delete(IssueComment $comment): void
    {
        $comment->delete();
    }

    /**
     * Gleicht die festgehaltenen Nennungen an den neuen Stand an.
     *
     * Bestehende Zeilen bleiben stehen, statt gelöscht und neu geschrieben zu
     * werden — genau daran hängt, wer schon benachrichtigt wurde.
     *
     * @param  list<array{user_id: int|null, team_id: int|null, label: string}>  $mentions
     * @return list<array{user_id: int|null, team_id: int|null, label: string}> die neu hinzugekommenen
     */
    private function store(IssueComment $comment, array $mentions): array
    {
        $existing = $comment->mentions()->get();

        $keyOf = static fn (?int $userId, ?int $teamId): string => $userId.':'.$teamId;

        $known = $existing
            ->mapWithKeys(static fn (IssueCommentMention $mention): array => [
                $keyOf($mention->user_id, $mention->team_id) => $mention,
            ]);

        $wanted = [];
        $added = [];

        foreach ($mentions as $mention) {
            $key = $keyOf($mention['user_id'], $mention['team_id']);
            $wanted[$key] = true;

            if ($known->has($key)) {
                /** @var IssueCommentMention $row */
                $row = $known->get($key);

                // Die Beschriftung wird nachgezogen, der Eintrag bleibt: wer
                // beim Bearbeiten „@anna beck" zu „@Anna Beck" verbessert, meint
                // dieselbe Person — die Hervorhebung sucht aber nach dem Wortlaut
                // und fände den alten nicht mehr.
                if ($row->label !== $mention['label']) {
                    $row->forceFill(['label' => $mention['label']])->save();
                }

                continue;
            }

            $comment->mentions()->create([
                'user_id' => $mention['user_id'],
                'team_id' => $mention['team_id'],
                'label' => $mention['label'],
            ]);

            $added[] = $mention;
        }

        $stale = $known->reject(static fn (IssueCommentMention $mention, string $key): bool => isset($wanted[$key]));

        if ($stale->isNotEmpty()) {
            IssueCommentMention::query()->whereIn('id', $stale->pluck('id'))->delete();
        }

        $comment->unsetRelation('mentions');

        return $added;
    }
}
