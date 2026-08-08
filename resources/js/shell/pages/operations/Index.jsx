import React from 'react';
import { router, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { DangerButton, PrimaryButton, SecondaryButton } from '../../components/Form.jsx';
import { formatNumber, useTranslations } from '../../i18n.js';
import { formatDuration } from '../../duration.js';

// Der Zustand dieser Installation — die Seite für den Betreiber, nicht für den
// Nutzer. Sie beantwortet drei Fragen in der Reihenfolge, in der sie im
// Ernstfall gestellt werden: steht noch alles, kommt die Verarbeitung mit, was
// ist liegengeblieben.
//
// Nichts hier wird laufend aktualisiert. Eine Seite, die sich selbst neu lädt,
// erzeugt genau in dem Moment Last, in dem am wenigsten davon übrig ist —
// nachgesehen wird per Neuladen, und für den Dauerblick gibt es `/metrics`.
export default function Index({
    health,
    backlog,
    durations,
    latency,
    queues,
    states,
    failedJobs,
    failedPayloads,
    retryLimit,
    actions,
}) {
    const { shell } = usePage().props;
    const { t, formats } = useTranslations();

    return (
        <>
            <PageHead
                title={t('operations.title')}
                appName={shell.appName}
                help={t('operations.help')}
            />

            <div className="space-y-4">
                <Health health={health} />
                <Backlog backlog={backlog} />

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Durations
                        title={t('operations.durations.title')}
                        description={t('operations.durations.description', {
                            count: formatNumber(durations.count, formats),
                        })}
                        values={durations}
                    />
                    <Durations
                        title={t('operations.latency.title')}
                        description={t('operations.latency.description')}
                        values={latency}
                    />
                    <Queues queues={queues} />
                    <States states={states} />
                </div>

                <FailedJobs failedJobs={failedJobs} actions={actions} />
                <FailedPayloads
                    count={failedPayloads}
                    limit={retryLimit}
                    action={actions.retryPayloads}
                />
            </div>
        </>
    );
}

// Die vier Bestandteile, ohne die nichts geht. Die Laufzeit steht dabei, weil
// „antwortet" und „antwortet rechtzeitig" im Betrieb dasselbe Problem sind.
function Health({ health }) {
    const { t, formats } = useTranslations();
    const ok = health.overall === 'ok';

    return (
        <Card
            title={t('operations.health.title')}
            description={t('operations.health.description', { route: '/health' })}
        >
            <p className={`text-sm font-medium ${ok ? okText : failText}`}>
                {ok ? t('operations.health.overall_ok') : t('operations.health.overall_failed')}
            </p>

            <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {health.checks.map((check) => (
                    <div key={check.name}>
                        <dt className="text-sm text-gray-500 dark:text-gray-400">{check.label}</dt>
                        <dd
                            className={`text-lg font-semibold ${check.state === 'ok' ? okText : failText}`}
                        >
                            {t(`operations.health.state.${check.state}`)}
                        </dd>
                        <dd className="text-xs text-gray-400 dark:text-gray-500">
                            {formatDuration(check.durationMs * 1000, t, formats)}
                        </dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

// Rückstand und Alter nebeneinander: die Menge allein schlägt bei jedem Ansturm
// an, das Alter allein bleibt bei einer einzelnen alten Meldung still.
function Backlog({ backlog }) {
    const { t, formats } = useTranslations();

    return (
        <Card
            title={t('operations.backlog.title')}
            description={t('operations.backlog.description')}
        >
            <p className={`text-sm font-medium ${backlog.breaching ? failText : okText}`}>
                {!backlog.breaching && t('operations.backlog.ok')}
                {backlog.breaching &&
                    (backlog.since
                        ? t('operations.backlog.breaching', { since: backlog.since })
                        : t('operations.backlog.breaching_unknown'))}
                {backlog.breaching && backlog.reasons.length > 0 && (
                    <span className="font-normal">
                        {' — '}
                        {backlog.reasons
                            .map((reason) => t(`operations.backlog.reasons.${reason}`))
                            .join(', ')}
                    </span>
                )}
            </p>

            <dl className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Metric
                    label={t('operations.backlog.pending')}
                    value={formatNumber(backlog.pending, formats)}
                />
                <Metric
                    label={t('operations.backlog.oldest')}
                    value={
                        backlog.oldestSeconds === null
                            ? t('operations.backlog.none')
                            : t('operations.backlog.seconds', {
                                  value: formatNumber(backlog.oldestSeconds, formats),
                              })
                    }
                />
            </dl>

            <p className="mt-4 text-xs text-gray-400 dark:text-gray-500">
                {t('operations.backlog.threshold', {
                    pending: formatNumber(backlog.maxPending, formats),
                    age: formatNumber(backlog.maxAgeSeconds, formats),
                })}
            </p>
        </Card>
    );
}

// Rechenzeit und Gesamtdauer teilen sich eine Darstellung: es sind dieselben
// drei Kennzahlen über derselben Messreihe, nur über verschiedene Uhren.
function Durations({ title, description, values }) {
    const { t, formats } = useTranslations();

    return (
        <Card title={title} description={description}>
            {values.count === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('operations.durations.empty')}
                </p>
            )}

            {values.count > 0 && (
                <dl className="grid grid-cols-3 gap-4">
                    <Metric
                        label={t('operations.durations.avg')}
                        value={formatDuration(values.avg_ms * 1000, t, formats)}
                    />
                    <Metric
                        label={t('operations.durations.p95')}
                        value={formatDuration(values.p95_ms * 1000, t, formats)}
                    />
                    <Metric
                        label={t('operations.durations.max')}
                        value={formatDuration(values.max_ms * 1000, t, formats)}
                    />
                </dl>
            )}
        </Card>
    );
}

function Queues({ queues }) {
    const { t, formats } = useTranslations();

    return (
        <Card title={t('operations.queues.title')} description={t('operations.queues.description')}>
            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                {queues.map((queue) => (
                    <Metric
                        key={queue.name}
                        label={queue.name}
                        value={
                            queue.size === null
                                ? t('operations.queues.unknown')
                                : formatNumber(queue.size, formats)
                        }
                    />
                ))}
            </dl>
        </Card>
    );
}

function States({ states }) {
    const { t, formats } = useTranslations();

    return (
        <Card title={t('operations.states.title')} description={t('operations.states.description')}>
            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                {states.map((state) => (
                    <Metric
                        key={state.state}
                        label={state.label}
                        value={formatNumber(state.total, formats)}
                    />
                ))}
            </dl>
        </Card>
    );
}

// Das Liegengebliebene — der einzige Abschnitt mit Schaltflächen. Nachsehen
// genügt nicht, wenn man es nicht auch wieder in Gang setzen kann.
function FailedJobs({ failedJobs, actions }) {
    const { t, formats } = useTranslations();

    const post = (url, data = {}) => router.post(url, data, { preserveScroll: true });

    return (
        <Card
            title={t('operations.failed_jobs.title')}
            description={t('operations.failed_jobs.description')}
        >
            {failedJobs.total === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('operations.failed_jobs.empty')}
                </p>
            )}

            {failedJobs.total > 0 && (
                <>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            {t('operations.failed_jobs.count', {
                                count: formatNumber(failedJobs.total, formats),
                                shown: formatNumber(
                                    Math.min(failedJobs.total, failedJobs.shown),
                                    formats
                                ),
                            })}
                        </p>

                        <PrimaryButton onClick={() => post(actions.retryAllJobs)}>
                            {t('operations.failed_jobs.retry_all')}
                        </PrimaryButton>
                    </div>

                    <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th className="py-2 pe-4">
                                        {t('operations.failed_jobs.columns.name')}
                                    </th>
                                    <th className="py-2 pe-4">
                                        {t('operations.failed_jobs.columns.queue')}
                                    </th>
                                    <th className="py-2 pe-4">
                                        {t('operations.failed_jobs.columns.failed_at')}
                                    </th>
                                    <th className="py-2 pe-4">
                                        {t('operations.failed_jobs.columns.exception')}
                                    </th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                                {failedJobs.entries.map((job) => (
                                    <tr key={job.id} className="align-top">
                                        <td className="py-2 pe-4 font-medium text-gray-900 dark:text-gray-100">
                                            {job.name}
                                        </td>
                                        <td className="py-2 pe-4 text-gray-500 dark:text-gray-400">
                                            {job.queue}
                                        </td>
                                        <td className="py-2 pe-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                            {job.failedAt ?? '—'}
                                        </td>
                                        <td className="py-2 pe-4 text-gray-500 dark:text-gray-400">
                                            {job.exception}
                                        </td>
                                        <td className="py-2">
                                            <div className="flex gap-2">
                                                <SecondaryButton
                                                    onClick={() =>
                                                        post(actions.retryJob, { id: job.id })
                                                    }
                                                >
                                                    {t('operations.failed_jobs.retry')}
                                                </SecondaryButton>
                                                <DangerButton
                                                    onClick={() =>
                                                        post(actions.forgetJob, { id: job.id })
                                                    }
                                                >
                                                    {t('operations.failed_jobs.forget')}
                                                </DangerButton>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </Card>
    );
}

// Etwas anderes als gescheiterte Jobs: hier liegen die Rohdaten noch, und nach
// einem reparierten Schritt der Kette lassen sie sich erneut durchlaufen.
function FailedPayloads({ count, limit, action }) {
    const { t, formats } = useTranslations();

    return (
        <Card
            title={t('operations.failed_payloads.title')}
            description={t('operations.failed_payloads.description')}
        >
            {count === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('operations.failed_payloads.empty')}
                </p>
            )}

            {count > 0 && (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-sm text-gray-700 dark:text-gray-300">
                            {t('operations.failed_payloads.count', {
                                count: formatNumber(count, formats),
                            })}
                        </p>
                        <p className="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            {t('operations.failed_payloads.limit_hint', {
                                limit: formatNumber(limit, formats),
                            })}
                        </p>
                    </div>

                    <PrimaryButton
                        onClick={() => router.post(action, {}, { preserveScroll: true })}
                    >
                        {t('operations.failed_payloads.retry')}
                    </PrimaryButton>
                </div>
            )}
        </Card>
    );
}

function Metric({ label, value }) {
    return (
        <div>
            <dt className="text-sm text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="text-lg font-semibold text-gray-900 dark:text-gray-100">{value}</dd>
        </div>
    );
}

const okText = 'text-emerald-600 dark:text-emerald-400';
const failText = 'text-rose-600 dark:text-rose-400';
