<?php

namespace App\Support\Feedback;

use App\Enums\UserReportStatus;
use App\Http\Requests\UserReportListRequest;
use App\Models\Event;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\UserReport;
use App\Support\Filters\GlobalFilter;
use App\Support\Formats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Die Rückmeldungs-Liste: Abfrage und Darstellung einer Seite.
 *
 * Der Zeitraum wirkt hier auf den **Eingang** und nicht auf eine Zeitspanne wie
 * bei den Fehler-Einträgen: eine Zuschrift ist ein Zeitpunkt. Die Umgebung wirkt
 * gar nicht — eine Rückmeldung wird von einem Menschen geschrieben und nicht von
 * einer Umgebung; die Seite sagt das ausdrücklich, statt die Auswahl still zu
 * übergehen.
 */
final class UserReportList
{
    /**
     * Einträge je Seite. Kleiner als bei den Fehlern (50): jede Zeile trägt
     * einen Absatz Text, und fünfzig Absätze sind keine Liste mehr.
     */
    public const PER_PAGE = 25;

    /**
     * Eine Seite der Liste, fertig für die Oberfläche.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function paginate(
        GlobalFilter $filter,
        User $viewer,
        ?UserReportStatus $status = null,
        ?string $assignee = null,
    ): LengthAwarePaginator {
        $page = self::query($filter, $viewer, $status, $assignee)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Was beim Eintreffen noch kein Ereignis hatte, bekommt hier seinen
        // Bezug — in einer Abfrage für die ganze Seite. Warum beim Anzeigen und
        // nicht in einem eigenen Hintergrundlauf, steht an {@see UserReport::link()}.
        UserReport::link($page->getCollection());

        $page->through(fn (UserReport $report): array => self::present($report));

        return $page;
    }

    /**
     * Die Abfrage hinter der Liste.
     *
     * @return Builder<UserReport>
     */
    public static function query(
        GlobalFilter $filter,
        User $viewer,
        ?UserReportStatus $status = null,
        ?string $assignee = null,
    ): Builder {
        $query = UserReport::query()
            ->with([
                'project:id,name,slug,organization_id',
                'project.organization:id,slug',
                'assignee:id,name',
                'issue:id,title,culprit',
                'event:id,event_id',
            ])
            ->whereIn('project_id', $filter->projectIds())
            ->whereBetween('received_at', [$filter->fromUtc(), $filter->toUtc()]);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($assignee === UserReportListRequest::ASSIGNEE_ME) {
            $query->where('assigned_to', $viewer->id);
        }

        if ($assignee === UserReportListRequest::ASSIGNEE_NOBODY) {
            $query->whereNull('assigned_to');
        }

        $query->latestFirst();

        return $query;
    }

    /**
     * Eine Zeile.
     *
     * @return array<string, mixed>
     */
    private static function present(UserReport $report): array
    {
        return [
            'id' => $report->id,
            'name' => $report->name,
            'email' => $report->email,
            'comments' => $report->comments,
            'url' => $report->url,
            'status' => $report->status->value,
            'statusLabel' => $report->status->label(),
            'source' => $report->source()->value,
            'sourceLabel' => $report->source()->label(),
            'receivedAt' => $report->received_at->toIso8601String(),
            'receivedAtLabel' => Formats::dateTime($report->received_at),
            'assignee' => $report->assignee === null ? null : [
                'id' => $report->assignee->id,
                'name' => $report->assignee->name,
            ],
            // Die genannte Ereignisnummer bleibt sichtbar, auch ohne Treffer:
            // sonst sähe eine Rückmeldung, deren Ereignis ausgesiebt oder
            // abgelaufen ist, aus wie eine ohne Bezug.
            'eventReference' => $report->event_reference,
            'issue' => self::issue($report),
            'eventHref' => self::eventHref($report),
            'project' => self::project($report),
            // Die Adressen kommen vom Server und werden nicht in der Oberfläche
            // zusammengesetzt — wie überall sonst auch: eine umbenannte Route
            // soll an einer Stelle nachgezogen werden und nicht an zweien.
            'statusHref' => route('feedback.status', $report),
            'assignmentHref' => route('feedback.assignment', $report),
        ];
    }

    /**
     * Das Projekt der Zeile — samt Link auf seine Seite, wie in der
     * Versionsliste.
     *
     * @return array{name: string, slug: string, href: string}|null
     */
    private static function project(UserReport $report): ?array
    {
        $project = $report->project;

        if (! $project instanceof Project) {
            return null;
        }

        return [
            'name' => $project->name,
            'slug' => $project->slug,
            'href' => route('projects.show', [$project->organization, $project]),
        ];
    }

    /**
     * Der Fehler-Eintrag hinter der Rückmeldung — das Sprungziel, wegen dem der
     * Bezug überhaupt geführt wird.
     *
     * @return array{title: string, href: string}|null
     */
    private static function issue(UserReport $report): ?array
    {
        $issue = $report->issue;

        if (! $issue instanceof Issue) {
            return null;
        }

        return [
            'title' => $issue->title ?? $issue->culprit ?? '',
            'href' => route('issues.show', $issue),
        ];
    }

    /**
     * Der Weg zu genau der Meldung, auf die sich die Rückmeldung bezieht — nicht
     * nur zum Fehler-Eintrag. „Was hat der Kunde gesehen?" ist eine Frage an
     * dieses eine Ereignis und nicht an das neueste seiner Art.
     */
    private static function eventHref(UserReport $report): ?string
    {
        $issue = $report->issue;
        $event = $report->event;

        return $issue instanceof Issue && $event instanceof Event
            ? route('issues.events.show', [$issue, $event])
            : null;
    }

    /**
     * Die Personen, denen sich eine Rückmeldung übergeben lässt: die Mitglieder
     * der Organisation, in der sie eingetroffen ist.
     *
     * Nicht die Mitglieder des Projekts: die Zuweisung ist eine Frage an das
     * Haus („wer kümmert sich?"), und wer eine Rückmeldung beantwortet, ist
     * regelmäßig nicht dieselbe Person, die den Fehler behebt.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function assignableUsers(GlobalFilter $filter): array
    {
        if ($filter->organization === null) {
            return [];
        }

        return $filter->organization->memberships()
            ->with('user:id,name')
            ->get()
            ->map(fn (Membership $membership): ?User => $membership->user)
            ->filter()
            ->sortBy('name')
            ->map(fn (User $user): array => [
                'value' => (string) $user->id,
                'label' => $user->name,
            ])
            ->values()
            ->all();
    }
}
