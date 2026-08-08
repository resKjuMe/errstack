<?php

namespace App\Support\Issues;

use App\Enums\EventLevel;
use App\Enums\IssueActivityType;
use App\Enums\IssueIgnoreMode;
use App\Enums\IssuePriority;
use App\Enums\IssueResolveMode;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueComment;
use App\Models\IssueCommentMention;
use App\Models\User;
use App\Support\Formats;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;

/**
 * Die Zeitleiste eines Fehlers: was geschehen ist und was dazu gesagt wurde.
 *
 * **Eine Leiste aus zwei Tabellen.** Vermerke ({@see IssueActivity}) sind
 * unveränderlich, Kommentare ({@see IssueComment}) sind es nicht — deshalb
 * liegen sie getrennt (siehe die Migration der Kommentar-Tabellen). Gelesen
 * werden sie gemeinsam, denn die Frage, die hier beantwortet wird, ist eine
 * einzige: „was ist mit diesem Fehler passiert?" Ob die Antwort „Anna hat
 * erledigt" oder „Anna hat geschrieben: das lag am Zeitlimit" lautet, ist für
 * den Lesenden derselbe Faden.
 *
 * **Der Satz wird auf dem Server gebildet, nicht im Browser.** Ein Vermerk
 * lautet je nach Art und Bedingung „stummgeschaltet, bis 100 weitere Ereignisse
 * in 60 Minuten" oder „erledigt in Version 1.4.2" — das aus Bausteinen in der
 * Oberfläche zusammenzusetzen hieße, die Beugung zweier Sprachen in JavaScript
 * nachzubauen. Dieselbe Überlegung gilt für die Kommentare: sie kommen in
 * Abschnitte zerlegt, damit die Nennungen hervorgehoben werden können, ohne sie
 * ein zweites Mal zu erkennen ({@see Mentions::segments()}).
 *
 * **Geblättert wird, weil die Leiste wächst.** Bis S6 war der Verlauf eine
 * Randnotiz von zwanzig Zeilen; mit Kommentaren ist er die Absprache zu diesem
 * Fehler, und die schneidet man nicht nach zwanzig Einträgen ab. Die Seitenzahl
 * steht unter einem eigenen Namen in der Adresszeile ({@see PAGE_NAME}) — die
 * Detailseite hat mit den Meldungen bereits eine zweite Blätterung, und eine
 * gemeinsame `page` würde beide zugleich weiterschalten.
 */
final class IssueActivityFeed
{
    /**
     * Wie viele Einträge eine Seite zeigt.
     *
     * Genug für den üblichen Verlauf eines Fehlers ohne Blättern, wenig genug,
     * dass ein Fehler mit dreihundert Wortmeldungen die Seite nicht sprengt.
     */
    public const PER_PAGE = 20;

    /**
     * Der Name der Seitenzahl in der Adresszeile.
     *
     * Deutsch wie die übrigen Pfade dieser Anwendung — und ausdrücklich nicht
     * `page`: die Detailseite blättert schon durch die Meldungen.
     */
    public const PAGE_NAME = 'verlauf';

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function forIssue(Issue $issue, ?User $viewer = null): LengthAwarePaginator
    {
        $page = max(1, (int) Paginator::resolveCurrentPage(self::PAGE_NAME));

        $total = self::countOf($issue);
        $rows = self::rows($issue, $page);

        return (new LengthAwarePaginator(
            self::present($issue, $rows, $viewer),
            $total,
            self::PER_PAGE,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => self::PAGE_NAME,
            ],
        ))
            // Die übrigen Felder der Adresszeile bleiben stehen: wer eine
            // bestimmte Meldung offen hat, soll sie beim Blättern im Verlauf
            // nicht verlieren.
            ->withQueryString();
    }

    /**
     * Die Kennungen einer Seite, über beide Tabellen hinweg geordnet.
     *
     * Zuerst nur Art, Kennung und Zeitpunkt — die Inhalte werden danach in
     * **zwei** Abfragen nachgeladen. Der Umweg spart nicht Abfragen, sondern
     * eine Wahl: eine Vereinigung, die schon die Inhalte mitführt, müsste beide
     * Tabellen auf dieselben Spalten zwingen, und ein Kommentartext stünde dann
     * in einer Spalte namens `data`.
     *
     * Neueste zuerst, wie im Änderungsprotokoll: die Frage „was ist zuletzt
     * passiert?" ist die häufigere, und wer den Anfang sucht, blättert ans
     * Ende.
     *
     * @return list<object{kind: string, id: int}>
     */
    private static function rows(Issue $issue, int $page): array
    {
        /** @var list<object{kind: string, id: int}> $rows */
        $rows = DB::query()
            ->fromSub(self::union($issue), 'feed')
            ->orderByDesc('created_at')
            // Der zweite Schlüssel entscheidet nur bei gleicher Sekunde. Dass
            // dabei Kennungen aus zwei Tabellen verglichen werden, ist ohne
            // Bedeutung: gebraucht wird eine **stabile** Reihenfolge, keine
            // inhaltlich richtige — bei gleicher Sekunde gibt es keine.
            ->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)
            ->get()
            ->all();

        return $rows;
    }

    private static function countOf(Issue $issue): int
    {
        return DB::query()->fromSub(self::union($issue), 'feed')->count();
    }

    private static function union(Issue $issue): QueryBuilder
    {
        return DB::table('issue_activities')
            ->selectRaw("'activity' as kind, id, created_at")
            ->where('issue_id', $issue->id)
            ->unionAll(
                DB::table('issue_comments')
                    ->selectRaw("'comment' as kind, id, created_at")
                    ->where('issue_id', $issue->id),
            );
    }

    /**
     * Die Einträge einer Seite, in der Reihenfolge der Vereinigung.
     *
     * @param  list<object{kind: string, id: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private static function present(Issue $issue, array $rows, ?User $viewer): array
    {
        $activityIds = [];
        $commentIds = [];

        foreach ($rows as $row) {
            if ($row->kind === 'comment') {
                $commentIds[] = (int) $row->id;

                continue;
            }

            $activityIds[] = (int) $row->id;
        }

        $activities = $activityIds === []
            ? collect()
            : IssueActivity::query()->whereIn('id', $activityIds)->get()->keyBy('id');

        $comments = $commentIds === []
            ? collect()
            : IssueComment::query()->with('mentions')->whereIn('id', $commentIds)->get()->keyBy('id');

        $entries = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;

            $entry = $row->kind === 'comment'
                // Der Eintrag wird gesetzt statt nachgeladen: die Rechtefrage
                // beim Löschen geht über Projekt und Organisation, und die
                // hängen für alle Kommentare dieser Seite an **einem** Eintrag,
                // den die Detailseite ohnehin schon geladen hat.
                ? ($comments->has($id) ? self::comment($comments->get($id)->setRelation('issue', $issue), $viewer) : null)
                : ($activities->has($id) ? self::activity($activities->get($id)) : null);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private static function activity(IssueActivity $activity): array
    {
        return [
            // Die Kennung ist innerhalb einer Tabelle eindeutig, in der
            // gemeinsamen Leiste aber nicht — deshalb mit der Art davor.
            'key' => 'activity-'.$activity->id,
            'kind' => 'activity',
            'type' => $activity->type->value,
            'text' => self::text($activity),
            // Der Name aus dem Vermerk und nicht aus dem Konto: er ist der zum
            // Zeitpunkt der Handlung, und genau der gehört in einen Verlauf.
            'actor' => $activity->actor_name,
            'at' => $activity->created_at->toIso8601String(),
            'atLabel' => Formats::dateTime($activity->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function comment(IssueComment $comment, ?User $viewer): array
    {
        $labels = $comment->mentions
            ->map(static fn (IssueCommentMention $mention): string => $mention->label)
            ->all();

        return [
            'key' => 'comment-'.$comment->id,
            'kind' => 'comment',
            'id' => $comment->id,
            // Zerlegt fürs Anzeigen …
            'segments' => Mentions::segments($comment->body, $labels),
            // … und roh fürs Bearbeiten: das Eingabefeld zeigt, was geschrieben
            // wurde, und nicht, was daraus dargestellt wird.
            'body' => $comment->body,
            'actor' => $comment->author_name,
            'at' => $comment->created_at->toIso8601String(),
            'atLabel' => Formats::dateTime($comment->created_at),
            'edited' => $comment->wasEdited(),
            'editedAtLabel' => Formats::dateTime($comment->edited_at),
            // Die Rechte kommen mit dem Eintrag: die Oberfläche soll keine
            // Schaltfläche zeigen, die beim Klick abgewiesen wird — und sie
            // soll die Regel nicht ein zweites Mal kennen.
            'canEdit' => $viewer !== null && Gate::forUser($viewer)->allows('update', $comment),
            'canDelete' => $viewer !== null && Gate::forUser($viewer)->allows('delete', $comment),
            // Die Adressen kommen mit dem Eintrag und werden nicht in der
            // Oberfläche zusammengesetzt: die Pfade dieser Anwendung sind
            // deutsch und stehen an einer Stelle (routes/issues.php).
            'updateHref' => route('issues.comments.update', [$comment->issue_id, $comment->id]),
            'destroyHref' => route('issues.comments.destroy', [$comment->issue_id, $comment->id]),
        ];
    }

    /**
     * Der Satz zu einem Vermerk — mit Bedingung, wo es eine gibt.
     */
    private static function text(IssueActivity $activity): string
    {
        $data = $activity->data ?? [];
        $key = 'issues.activity.'.$activity->type->value;

        // Die beiden Vermerke aus S11 stehen für sich, weil ihr Satz nicht aus
        // einer Bedingung entsteht, sondern aus Zahlen: die Herleitung der
        // Wichtigkeit und das Vielfache, mit dem ein stummgeschalteter Fehler
        // über seinem Verlauf liegt. Über dieselbe Ersetzungsliste geführt hinge
        // an jedem Satz ein halbes Dutzend Platzhalter, von denen er einen
        // braucht.
        if ($activity->type === IssueActivityType::PriorityChanged) {
            return self::priorityText($data);
        }

        if ($activity->type === IssueActivityType::Escalated) {
            return self::escalationText($data);
        }

        return __($key, [
            'condition' => self::condition($data),
            'count' => Formats::number((int) ($data['count'] ?? $data['users'] ?? 0)),
            'minutes' => Formats::number((int) ($data['window'] ?? 0)),
        ]);
    }

    /**
     * Der Satz zu einer geänderten Wichtigkeit — und, wo die Ableitung sie
     * gesetzt hat, ihre Herleitung.
     *
     * **Die Herleitung wird hier gebildet und nicht beim Rechnen.** Im Vermerk
     * stehen die Beiträge als Schlüssel und Zahl
     * ({@see IssuePriorityScore::$reasons}); erst hier werden sie zu Wörtern,
     * und zwar in der Sprache dessen, der sie liest. Der fertige Satz in der
     * Datenbank wäre der von damals — und in der falschen Sprache.
     *
     * @param  array<string, mixed>  $data
     */
    private static function priorityText(array $data): string
    {
        $priority = IssuePriority::tryFrom((string) ($data['priority'] ?? ''));
        $mode = (string) ($data['mode'] ?? '');

        if ($mode === 'auto' || $priority === null) {
            return __('issues.activity.priority_auto');
        }

        if ($mode !== 'derived') {
            return __('issues.activity.priority', ['priority' => $priority->label()]);
        }

        return __('issues.activity.priority_derived', [
            'priority' => $priority->label(),
            'reason' => self::reasons($data['reasons'] ?? []),
        ]);
    }

    /**
     * Die Beiträge der Ableitung, aneinandergereiht: „Grad fatal, 512
     * Ereignisse in 24 Stunden, stark steigend".
     *
     * Ein unbekannter Schlüssel wird übergangen und nicht rohbelassen
     * ausgegeben: Vermerke sind unveränderlich und überleben jede Änderung an
     * der Ableitung — ein Beitrag, den es nicht mehr gibt, darf im Verlauf
     * keinen Platzhalter hinterlassen.
     */
    private static function reasons(mixed $reasons): string
    {
        if (! is_array($reasons)) {
            return '';
        }

        $parts = [];

        foreach ($reasons as $reason) {
            if (! is_array($reason) || ! isset($reason['key'])) {
                continue;
            }

            $key = (string) $reason['key'];
            $value = $reason['value'] ?? null;
            $line = 'issues.priority.reason.'.$key;

            if (! Lang::has($line)) {
                continue;
            }

            $parts[] = __($line, [
                // Der Grad kommt als abgelegter Wert und wird hier beschriftet;
                // alles andere ist eine Zahl.
                'value' => $key === 'level'
                    ? (EventLevel::tryFrom((string) $value)?->label() ?? (string) $value)
                    : Formats::number((int) $value),
            ]);
        }

        return implode(', ', $parts);
    }

    /**
     * Der Satz zu einer erkannten Eskalation — mit dem Vielfachen, wo es eines
     * gibt.
     *
     * Ohne Erwartungswert („der Eintrag war seit Wochen still") gibt es kein
     * Vielfaches; dann nennt der Satz nur die Zahl, statt „unendlich mal mehr"
     * zu behaupten.
     *
     * @param  array<string, mixed>  $data
     */
    private static function escalationText(array $data): string
    {
        $observed = Formats::number((int) ($data['observed'] ?? 0));
        $factor = $data['factor'] ?? null;

        return $factor === null
            ? __('issues.activity.escalated_plain', ['observed' => $observed])
            : __('issues.activity.escalated', [
                'observed' => $observed,
                'factor' => Formats::number((float) $factor, 1),
            ]);
    }

    /**
     * Die Bedingung in Worten — leer, wo keine mitgegeben wurde.
     *
     * @param  array<string, mixed>  $data
     */
    private static function condition(array $data): string
    {
        $mode = (string) ($data['mode'] ?? '');

        if ($mode === '') {
            return '';
        }

        return match (true) {
            isset($data['users']) => __('issues.actions.condition.users', [
                'count' => Formats::number((int) $data['users']),
            ]),
            isset($data['count'], $data['window']) => __('issues.actions.condition.count_window', [
                'count' => Formats::number((int) $data['count']),
                'minutes' => Formats::number((int) $data['window']),
            ]),
            isset($data['count']) => __('issues.actions.condition.count', [
                'count' => Formats::number((int) $data['count']),
            ]),
            // Ohne Schwelle sagt die Art alles: „dauerhaft", „bis es wieder
            // auftritt", „mit der nächsten Auslieferung".
            default => IssueIgnoreMode::tryFrom($mode)?->label()
                ?? IssueResolveMode::tryFrom($mode)?->label()
                ?? '',
        };
    }
}
