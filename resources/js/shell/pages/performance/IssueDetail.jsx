import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { useTranslations } from '../../i18n.js';
import { bytes, duration, Missing } from '../../durations.jsx';
import { ProblemBadge } from './Issues.jsx';

// Ein einzelnes Leistungsproblem: was es ist, was es kostet — und vor allem, wo
// man es sich ansehen kann.
//
// **Die Beispiele sind der Zweck dieser Seite.** Die Zahlen oben stehen schon in
// der Liste; was dort fehlt und hier hingehört, ist der Beleg: ein tatsächlicher
// Ablauf, die tatsächlich betroffenen Schritte, die tatsächliche Abfrage. Ohne
// ihn wäre die Überschrift eine Behauptung, die man nicht nachprüfen kann, und
// die erste Frage nach dem Lesen bliebe offen.
export default function IssueDetail({ issue, examples, indexHref }) {
    const { shell } = usePage().props;
    const { t, formats } = useTranslations();

    return (
        <>
            <PageHead
                title={issue.title}
                appName={shell.appName}
                help={issue.problemDescription ?? t('performance_issues.help')}
            />

            <div className="mb-4">
                <Link
                    href={indexHref}
                    className="text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                >
                    ← {t('performance_issues.detail.back')}
                </Link>
            </div>

            <Card className="mb-4">
                <div className="flex flex-wrap items-center gap-2">
                    <ProblemBadge label={issue.problemLabel} />
                    <h1 className="min-w-0 break-words text-lg font-semibold text-gray-900 dark:text-gray-100">
                        {issue.title}
                    </h1>
                </div>

                <p className="mt-1 break-words text-sm text-gray-500 dark:text-gray-400">
                    {issue.culprit}
                    {issue.project && (
                        <>
                            <span className="mx-2">·</span>
                            <Link
                                href={issue.project.href}
                                className="underline hover:text-gray-700 dark:hover:text-gray-200"
                            >
                                {issue.project.name}
                            </Link>
                        </>
                    )}
                </p>

                {issue.problemDescription && (
                    <p className="mt-3 text-sm text-gray-600 dark:text-gray-300">
                        {issue.problemDescription}
                    </p>
                )}

                <dl className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <Stat
                        label={t('performance_issues.detail.total_time_lost')}
                        value={duration(issue.timeLostUs, t, formats)}
                    />
                    <Stat
                        label={t('performance_issues.detail.time_lost_per_event')}
                        value={duration(issue.timeLostPerEventUs, t, formats)}
                    />
                    <Stat
                        label={t('performance_issues.detail.times_seen')}
                        value={issue.timesSeenLabel}
                    />
                    <Stat
                        label={t('performance_issues.detail.users_seen')}
                        value={issue.usersSeenLabel}
                    />
                    <Stat
                        label={t('performance_issues.detail.first_seen')}
                        value={issue.firstSeenLabel}
                    />
                    <Stat
                        label={t('performance_issues.detail.last_seen')}
                        value={issue.lastSeenLabel}
                    />
                </dl>
            </Card>

            <Card>
                <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                    {t('performance_issues.detail.examples')}
                </h2>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {t('performance_issues.detail.examples_hint')}
                </p>

                {examples.length === 0 ? (
                    <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">
                        {t('performance_issues.detail.no_examples')}
                    </p>
                ) : (
                    <ul className="mt-4 space-y-4">
                        {examples.map((example) => (
                            <Example key={example.id} example={example} t={t} formats={formats} />
                        ))}
                    </ul>
                )}
            </Card>
        </>
    );
}

function Stat({ label, value }) {
    return (
        <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {label}
            </dt>
            <dd className="mt-0.5 font-medium text-gray-900 dark:text-gray-100">{value}</dd>
        </div>
    );
}

// Ein Beleg: der Ablauf, seine Kennzahlen, die Belegangaben des Erkenners und
// die betroffenen Schritte.
//
// Die Trace-Kennung steht als Text und nicht als Verweis: eine Ansicht des
// ganzen Ablaufs gibt es noch nicht, und ein Link, der ins Leere führt, ist
// schlimmer als eine Kennung, die man kopieren kann.
function Example({ example, t, formats }) {
    return (
        <li className="rounded-md border border-gray-200 p-4 dark:border-gray-700">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium text-gray-900 dark:text-gray-100">
                    {example.transaction?.name ?? t('performance_issues.detail.transaction')}
                </p>
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('performance_issues.detail.time_lost')}:{' '}
                    <span className="font-medium text-gray-900 dark:text-gray-100">
                        {duration(example.timeLostUs, t, formats)}
                    </span>
                </p>
            </div>

            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('performance_issues.detail.occurred_at')}: {example.occurredAtLabel}
                <span className="mx-2">·</span>
                {t('performance_issues.detail.trace')}:{' '}
                <code className="font-mono">{example.traceId}</code>
                <span className="mx-2">·</span>
                {t('performance_issues.detail.span_count', { count: example.spanCount })}
            </p>

            {example.description && (
                <pre className="mt-3 overflow-x-auto rounded bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-900/60 dark:text-gray-200">
                    {example.description}
                </pre>
            )}

            <Evidence evidence={example.evidence} t={t} formats={formats} />

            {example.spans.length > 0 && (
                <div className="mt-3">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {t('performance_issues.detail.spans')}
                    </p>
                    <ul className="mt-1 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                        {example.spans.map((span) => (
                            <li key={span.spanId} className="flex items-baseline gap-3 py-1.5">
                                <code className="shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">
                                    {span.op}
                                </code>
                                <span className="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">
                                    {span.description}
                                </span>
                                <span className="shrink-0 text-gray-900 dark:text-gray-100">
                                    {duration(span.durationUs, t, formats)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </li>
    );
}

// Die Belegangaben des Erkenners — je Muster andere Schlüssel, deshalb keine
// feste Liste, sondern eine Schleife über das, was dasteht.
//
// Die Einheit steckt im Schlüssel (`_us`, `_bytes`), weil sie am Server bekannt
// ist und die Werte roh herüberkommen: eine Dauer, die als fertiger Text käme,
// ließe sich hier nicht mehr in die Schreibweise der übrigen Zahlen bringen.
function Evidence({ evidence, t, formats }) {
    const entries = Object.entries(evidence ?? {}).filter(
        ([, value]) => value !== null && value !== undefined && value !== ''
    );

    if (entries.length === 0) {
        return null;
    }

    return (
        <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs">
            {entries.map(([key, value]) => (
                <div key={key} className="flex items-baseline gap-1">
                    <dt className="text-gray-500 dark:text-gray-400">{evidenceLabel(key, t)}:</dt>
                    <dd className="font-medium text-gray-900 dark:text-gray-100">
                        {evidenceValue(key, value, t, formats)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function evidenceLabel(key, t) {
    const label = t(`performance_issues.evidence.${key}`);

    // Ein unbekannter Schlüssel liefert den Schlüsselpfad zurück. Dann lieber
    // den nackten Namen zeigen als „performance_issues.evidence.foo".
    return label.startsWith('performance_issues.') ? key : label;
}

function evidenceValue(key, value, t, formats) {
    if (typeof value === 'boolean') {
        return value ? t('performance_issues.evidence.yes') : t('performance_issues.evidence.no');
    }

    if (key.endsWith('_us')) {
        return duration(value, t, formats);
    }

    if (key.endsWith('_bytes')) {
        return bytes(value, t, formats);
    }

    if (value === null || value === undefined) {
        return <Missing />;
    }

    return String(value);
}
