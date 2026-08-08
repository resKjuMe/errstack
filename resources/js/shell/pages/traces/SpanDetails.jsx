import React from 'react';
import { Link } from '@inertiajs/react';
import Card from '../../components/Card.jsx';
import { SecondaryButton } from '../../components/Form.jsx';
import { formatDuration } from '../../duration.js';
import { formatDateTime, useTranslations } from '../../i18n.js';

// Die Einzelheiten eines Schrittes: das vollständige SQL, das Ziel eines
// Aufrufs, die Angaben des SDK.
//
// Sie kommen nachgeladen und stehen deshalb nicht schon in der Liste — bei einer
// Spur mit zehntausend Schritten wären die vollen Beschreibungen ein Vielfaches
// der ganzen übrigen Seite. Solange sie unterwegs sind, bleibt der Rahmen
// stehen: eine Karte, die verschwindet und wiederkommt, sieht wie ein Fehler
// aus.
export default function SpanDetails({ span, loading, onClose }) {
    const { t, formats } = useTranslations();

    return (
        <Card
            title={t('traces.detail.heading')}
            className="mt-6"
            description={span?.transaction ?? null}
        >
            <div className="mb-4 flex justify-end">
                <SecondaryButton type="button" onClick={onClose}>
                    {t('traces.detail.close')}
                </SecondaryButton>
            </div>

            {loading && !span && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('traces.detail.loading')}
                </p>
            )}

            {!loading && !span && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('traces.detail.gone')}
                </p>
            )}

            {span && (
                <div className={loading ? 'opacity-60' : ''}>
                    {span.description && (
                        <div className="mb-4">
                            <h3 className="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                {t('traces.detail.description')}
                            </h3>
                            {/* Vorformatiert und umbrechend: hier steht SQL, und
                                SQL ohne Zeilenumbrüche ist eine Zeile von tausend
                                Zeichen. */}
                            <pre className="mt-1 max-h-64 overflow-auto rounded-md bg-gray-50 p-3 font-mono text-xs whitespace-pre-wrap text-gray-800 dark:bg-gray-900/50 dark:text-gray-200">
                                {span.description}
                            </pre>
                        </div>
                    )}

                    <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <Field label={t('traces.detail.operation')} value={span.op} mono />
                        <Field label={t('traces.detail.status')} value={span.status} />
                        <Field label={t('traces.detail.project')} value={span.project} />
                        <Field
                            label={t('traces.detail.transaction')}
                            value={span.transaction}
                            mono
                        />
                        <Field label={t('traces.detail.environment')} value={span.environment} />
                        <Field label={t('traces.detail.release')} value={span.release} mono />
                        <Field
                            label={t('traces.detail.started')}
                            value={formatDateTime(span.startedAt, formats, {
                                dateStyle: 'medium',
                                timeStyle: 'medium',
                            })}
                        />
                        <Field
                            label={t('traces.detail.duration')}
                            value={formatDuration(span.durationUs, t, formats)}
                        />
                        <Field label={t('traces.detail.span_id')} value={span.spanId} mono />
                        <Field
                            label={t('traces.detail.parent_span_id')}
                            value={span.parentSpanId}
                            mono
                        />
                    </dl>

                    <h3 className="mt-6 text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                        {t('traces.detail.data')}
                    </h3>

                    {span.data.length === 0 ? (
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {t('traces.detail.no_data')}
                        </p>
                    ) : (
                        <div className="mt-2 overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <tbody className="divide-y divide-gray-100 dark:divide-gray-700/60">
                                    {span.data.map((entry) => (
                                        <tr key={entry.name}>
                                            <th
                                                scope="row"
                                                className="py-1 pe-4 text-start font-mono text-xs font-normal text-gray-500 dark:text-gray-400"
                                            >
                                                {entry.name}
                                            </th>
                                            <td className="py-1 font-mono text-xs break-all text-gray-800 dark:text-gray-200">
                                                {entry.value}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {span.errors.length > 0 && (
                        <>
                            <h3 className="mt-6 text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                {t('traces.detail.errors')}
                            </h3>
                            <ul className="mt-2 space-y-2">
                                {span.errors.map((error) => (
                                    <li key={error.id}>
                                        <ErrorLine error={error} t={t} formats={formats} />
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
            )}
        </Card>
    );
}

function Field({ label, value, mono = false }) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    return (
        <div>
            <dt className="text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                {label}
            </dt>
            <dd
                className={`text-gray-800 dark:text-gray-200 ${mono ? 'font-mono text-xs break-all' : ''}`}
            >
                {value}
            </dd>
        </div>
    );
}

// Ein Fehler in der Spur. Der Verweis auf die Fehlerseite fehlt, solange es sie
// nicht gibt (sie entsteht in S2) — dann steht der Fehler trotzdem da, nur eben
// ohne Link. Verschweigen wäre die schlechtere Antwort.
export function ErrorLine({ error, t, formats }) {
    const body = (
        <>
            <span className="font-medium">{error.title}</span>
            {error.culprit && (
                <span className="ms-2 font-mono text-xs text-gray-500 dark:text-gray-400">
                    {error.culprit}
                </span>
            )}
            <span className="ms-2 text-xs text-gray-500 dark:text-gray-400">
                {formatDateTime(error.occurredAt, formats, {
                    dateStyle: 'short',
                    timeStyle: 'medium',
                })}
            </span>
        </>
    );

    if (error.href === null) {
        return (
            <span
                title={t('traces.errors.no_link')}
                className="text-sm text-gray-700 dark:text-gray-300"
            >
                {body}
            </span>
        );
    }

    return (
        <Link
            href={error.href}
            title={t('traces.errors.open')}
            className="text-sm text-rose-700 hover:underline dark:text-rose-400"
        >
            {body}
        </Link>
    );
}
