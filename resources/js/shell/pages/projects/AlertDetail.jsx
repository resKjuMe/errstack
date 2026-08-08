import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { SecondaryButton } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';
import AlertChart from './AlertChart.jsx';
import AlertHistoryChart from './AlertHistoryChart.jsx';
import {
    AlertFilterBar,
    AlertHistoryList,
    AlertStateBadge,
    SnoozeForm,
    SnoozeState,
} from './AlertOverview.jsx';

// Ein einzelner Alarm: seine Einstellung, sein Verlauf und das, was daraufhin
// hinausgegangen ist.
//
// Eine Seite für beide Arten. Der Unterschied zwischen einem Schwellwert-Alarm
// und einer Fehler-Regel ist hier genau eine zusätzliche Grafik — die Kurve der
// Kennzahl mit ihren Schwellen. Alles andere ist dieselbe Frage in derselben
// Reihenfolge: Was ist eingestellt? Was ist passiert? Kam etwas an?
export default function AlertDetail({
    project,
    organization,
    filter,
    alert,
    history,
    chart,
    metricChart,
    deliveries,
    snooze,
    canManage,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('alert_overview.detail_title', {
                    alert: alert.name,
                    project: project.name,
                })}
                appName={shell.appName}
                help={t('alert_overview.help')}
                meta={
                    <div className="flex items-center gap-3">
                        <Link
                            href={project.overviewHref}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {t('alert_overview.detail.back')}
                        </Link>
                        <Link
                            href={project.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {project.name}
                        </Link>
                    </div>
                }
            />

            <div className="space-y-4">
                <Card>
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <AlertStateBadge state={alert.state} label={alert.stateLabel} />
                                <span className="text-xs text-gray-500 dark:text-gray-400">
                                    {alert.kindLabel}
                                </span>
                            </div>

                            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                {alert.subtitle}
                            </p>

                            {alert.stateSinceLabel && (
                                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {t('alert_overview.list.state_since')}: {alert.stateSinceLabel}
                                </p>
                            )}

                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {t('alert_overview.list.last')}:{' '}
                                {alert.lastAtLabel ?? t('alert_overview.list.never')} ·{' '}
                                {t('alert_overview.list.count')}:{' '}
                                {t('alert_overview.list.count_value', {
                                    count: alert.countInPeriod,
                                })}
                            </p>

                            <SnoozeState snooze={alert.snooze} />
                        </div>

                        <div className="flex flex-col items-end gap-2">
                            <Link href={alert.configHref}>
                                <SecondaryButton type="button">
                                    {t('alert_overview.list.config')}
                                </SecondaryButton>
                            </Link>

                            <SnoozeForm
                                snooze={alert.snooze}
                                options={snooze}
                                canManage={canManage}
                            />
                        </div>
                    </div>
                </Card>

                <Card title={t('alert_overview.detail.facts')}>
                    <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        {alert.facts.map((fact) => (
                            <div key={fact.label} className="flex justify-between gap-4">
                                <dt className="text-gray-500 dark:text-gray-400">{fact.label}</dt>
                                <dd className="text-end font-medium text-gray-900 dark:text-gray-100">
                                    {fact.value}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    <div className="mt-4">
                        <p className="text-xs font-semibold text-gray-500 dark:text-gray-400">
                            {t('alert_overview.detail.actions')}
                        </p>
                        <ul className="mt-1 list-inside list-disc text-sm text-gray-700 dark:text-gray-300">
                            {alert.actions.map((action) => (
                                <li key={action}>{action}</li>
                            ))}
                        </ul>
                    </div>
                </Card>

                <AlertFilterBar filter={filter} href={alert.detailHref} />

                <Card title={t('alert_overview.chart.title')}>
                    <AlertHistoryChart chart={chart} t={t} />
                </Card>

                {/* Die Kurve der Kennzahl gibt es nur beim Schwellwert-Alarm —
                    eine Fehler-Regel misst nichts, sie trifft zu oder nicht. */}
                {metricChart && (
                    <Card title={t('alert_overview.detail.metric_chart')}>
                        <AlertChart preview={metricChart} t={t} />
                    </Card>
                )}

                <AlertHistoryList history={history} />

                <Deliveries deliveries={deliveries} />
            </div>
        </>
    );
}

// Was hinausgegangen ist — und ob es ankam.
//
// Der Hinweis darunter ist kein Kleingedrucktes: eine leere Liste heißt hier
// nicht zwingend „nichts verschickt". Persönliche Benachrichtigungen gehen als
// Mail an den Einzelnen und werden nicht protokolliert, und Zustellungen von vor
// der Einführung dieser Ansicht tragen die Kennung noch nicht, über die hier
// gesucht wird.
function Deliveries({ deliveries }) {
    const t = useT();

    return (
        <Card title={t('alert_overview.detail.deliveries')}>
            {deliveries.length === 0 ? (
                <p className="text-sm text-gray-600 dark:text-gray-400">
                    {t('alert_overview.detail.deliveries_empty')}
                </p>
            ) : (
                <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    {deliveries.map((delivery) => (
                        <li key={delivery.id} className="flex flex-wrap items-center gap-3 py-2">
                            <DeliveryBadge status={delivery.status} label={delivery.statusLabel} />
                            <span className="font-medium text-gray-900 dark:text-gray-100">
                                {delivery.channel}
                            </span>
                            <span className="truncate text-gray-600 dark:text-gray-400">
                                {delivery.subject}
                            </span>

                            {delivery.error && (
                                <span className="truncate text-rose-700 dark:text-rose-400">
                                    {delivery.error}
                                </span>
                            )}

                            <span className="ms-auto text-xs text-gray-500 dark:text-gray-400">
                                {delivery.deliveredAtLabel
                                    ? t('alert_overview.detail.delivered_at', {
                                          at: delivery.deliveredAtLabel,
                                      })
                                    : delivery.createdAtLabel}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                {t('alert_overview.detail.deliveries_hint')}
            </p>
        </Card>
    );
}

function DeliveryBadge({ status, label }) {
    const tone =
        {
            sent: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
            pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
            failed: 'bg-rose-600 text-white',
        }[status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';

    return (
        <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold ${tone}`}>
            {label}
        </span>
    );
}
