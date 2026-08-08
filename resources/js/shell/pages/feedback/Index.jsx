import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import FilterBar from '../../components/FilterBar.jsx';
import Pagination from '../../components/Pagination.jsx';
import { InputLabel, SelectInput } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Die Rückmeldungen betroffener Personen — die einzige Liste dieser Anwendung,
// in der Sätze stehen und keine Messwerte.
//
// Daraus folgt der Zuschnitt: der Text ist nicht eine Spalte unter vielen,
// sondern das, was die Zeile ist. Alles andere — Absender, Bezug, Stand,
// Zuweisung — steht darum herum und kürzt sich, nicht der Text.
//
// Wie überall steht der ganze Zustand in der Adresszeile.
export default function Index({
    filter,
    reports,
    list,
    filterStatusOptions,
    statusOptions,
    assigneeOptions,
    assignableUsers,
    totalLabel,
    environmentIgnored,
}) {
    const { shell } = usePage().props;
    const t = useT();

    const showProject = filter.value.projects.length !== 1;

    // Stand und Zuweisung sind Felder dieser Seite, nicht der Leiste; die
    // übrigen Parameter der Adresszeile bleiben deshalb stehen. Eine neue
    // Auswahl beginnt auf Seite 1.
    const go = (patch) => {
        const query = new URLSearchParams(window.location.search);

        Object.entries(patch).forEach(([key, value]) =>
            value === '' ? query.delete(key) : query.set(key, value)
        );
        query.delete('page');

        router.get(`${window.location.pathname}?${query.toString()}`, {}, { preserveState: true });
    };

    return (
        <>
            <PageHead
                title={t('feedback.title')}
                appName={shell.appName}
                help={t('feedback.help')}
            />

            <FilterBar filter={filter} />

            {environmentIgnored && (
                <p className="mb-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                    {t('feedback.environment_ignored')}
                </p>
            )}

            <Card className="mb-4">
                <div className="flex flex-wrap items-end gap-4">
                    <div>
                        <InputLabel htmlFor="report_status" value={t('feedback.filter.status')} />
                        <SelectInput
                            id="report_status"
                            className="mt-1"
                            options={filterStatusOptions}
                            value={list.status}
                            onChange={(e) => go({ status: e.target.value })}
                        />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="report_assignee"
                            value={t('feedback.filter.assignee')}
                        />
                        <SelectInput
                            id="report_assignee"
                            className="mt-1"
                            options={assigneeOptions}
                            value={list.assignee}
                            onChange={(e) => go({ assignee: e.target.value })}
                        />
                    </div>

                    <p className="ms-auto text-sm text-gray-500 dark:text-gray-400">
                        {t('feedback.list.count', { count: totalLabel })}
                    </p>
                </div>
            </Card>

            {reports.data.length === 0 ? (
                <Card>
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        {t('feedback.list.empty')}
                    </p>
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('feedback.list.empty_hint')}
                    </p>
                </Card>
            ) : (
                <ul className="space-y-3">
                    {reports.data.map((report) => (
                        <ReportCard
                            key={report.id}
                            report={report}
                            showProject={showProject}
                            statusOptions={statusOptions}
                            assignableUsers={assignableUsers}
                            t={t}
                        />
                    ))}
                </ul>
            )}

            <Pagination links={reports.links} />
        </>
    );
}

function ReportCard({ report, showProject, statusOptions, assignableUsers, t }) {
    const setStatus = (status) =>
        router.patch(report.statusHref, { status }, { preserveScroll: true, preserveState: true });

    const setAssignee = (value) =>
        router.patch(
            report.assignmentHref,
            { assigned_to: value === '' ? null : Number(value) },
            { preserveScroll: true, preserveState: true }
        );

    return (
        <li className="rounded-lg bg-white p-4 shadow dark:bg-gray-800">
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                <span className="font-medium text-gray-900 dark:text-gray-100">
                    {report.name ?? t('feedback.list.anonymous')}
                </span>

                {report.email ? (
                    <a
                        href={`mailto:${report.email}`}
                        className="text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                    >
                        {report.email}
                    </a>
                ) : (
                    <span className="text-gray-500 dark:text-gray-400">
                        {t('feedback.list.no_email')}
                    </span>
                )}

                <span className="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                    {report.sourceLabel}
                </span>

                <span className="ms-auto text-xs text-gray-500 dark:text-gray-400">
                    {t('feedback.list.received', { value: report.receivedAtLabel })}
                </span>
            </div>

            {/* Der Text so, wie er getippt wurde — Zeilenumbrüche eingeschlossen.
                Wer eine Fehlerbeschreibung in einen Absatz presst, verliert die
                Aufzählung „1. … 2. … 3. …", also genau die Reproduktion. */}
            <p className="mt-2 whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200">
                {report.comments}
            </p>

            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                {report.issue && (
                    <Link
                        href={report.eventHref ?? report.issue.href}
                        className="truncate text-indigo-600 underline hover:text-indigo-500 dark:text-indigo-400"
                    >
                        {report.issue.title || t('feedback.list.show_event')}
                    </Link>
                )}

                {!report.issue && report.eventReference && (
                    <span
                        title={t('feedback.list.unlinked_hint')}
                        className="font-mono text-xs text-gray-500 dark:text-gray-400"
                    >
                        {t('feedback.list.unlinked', {
                            reference: report.eventReference.slice(0, 8),
                        })}
                    </span>
                )}

                {report.url && (
                    <a
                        href={report.url}
                        rel="noreferrer noopener"
                        target="_blank"
                        className="truncate text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        {report.url}
                    </a>
                )}

                {showProject && report.project && (
                    <Link
                        href={report.project.href}
                        className="text-xs text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        {report.project.name}
                    </Link>
                )}

                <div className="ms-auto flex items-center gap-2">
                    <SelectInput
                        aria-label={t('feedback.assign.label')}
                        className="text-sm"
                        placeholder={t('feedback.assign.nobody')}
                        options={assignableUsers}
                        value={report.assignee === null ? '' : String(report.assignee.id)}
                        onChange={(e) => setAssignee(e.target.value)}
                    />

                    <SelectInput
                        aria-label={t('feedback.status.label')}
                        className="text-sm"
                        options={statusOptions}
                        value={report.status}
                        onChange={(e) => setStatus(e.target.value)}
                    />
                </div>
            </div>
        </li>
    );
}
