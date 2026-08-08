import React from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { InputLabel, PrimaryButton, SecondaryButton, SelectInput } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';
import AlertHistoryChart from './AlertHistoryChart.jsx';

// Die Alarm-Übersicht eines Projekts: alle Regeln beider Arten mit Zustand,
// letzter Auslösung und Verlauf.
//
// Sie ist **keine** Einstellungsseite. Eingerichtet werden Alarme dort, wo sie
// hingehören (A2/A3); hier wird nachgesehen — und bei Bedarf befristet Ruhe
// gegeben, ohne die Auswertung anzuhalten.
export default function AlertOverview({
    project,
    organization,
    filter,
    rows,
    history,
    chart,
    snooze,
    canManage,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('alert_overview.title', { project: project.name })}
                appName={shell.appName}
                help={t('alert_overview.help')}
                meta={
                    <div className="flex items-center gap-3">
                        <Link
                            href={project.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {project.name}
                        </Link>
                        <Link
                            href={organization.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {organization.name}
                        </Link>
                    </div>
                }
            />

            <div className="space-y-4">
                <Card
                    title={t('alert_overview.intro.title')}
                    description={t('alert_overview.intro.description')}
                >
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {t('alert_overview.intro.snooze_hint')}
                    </p>
                </Card>

                <AlertFilterBar filter={filter} href={project.overviewHref} />

                <Card title={t('alert_overview.chart.title')}>
                    <AlertHistoryChart chart={chart} t={t} />
                </Card>

                <Card title={t('alert_overview.list.title')}>
                    {rows.length === 0 ? (
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {t('alert_overview.list.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y divide-gray-100 dark:divide-gray-700">
                            {rows.map((row) => (
                                <li key={row.key} className="py-4 first:pt-0 last:pb-0">
                                    <AlertRow row={row} options={snooze} canManage={canManage} />
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                <AlertHistoryList history={history} withAlertName />
            </div>
        </>
    );
}

// Der Zustand als farbige Marke — die eine Angabe, die man im Vorbeigehen liest.
//
// „Abgeschaltet" ist bewusst grau und nicht grün: eine Regel, die nicht
// ausgewertet wird, ist nicht „in Ordnung", und genau diese Verwechslung wäre
// die gefährlichste Auskunft der Seite.
export function AlertStateBadge({ state, label }) {
    const tone =
        {
            ok: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
            resolved:
                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
            warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
            critical: 'bg-rose-600 text-white',
            fired: 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
            armed: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
            off: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        }[state] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';

    return (
        <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold ${tone}`}>
            {label}
        </span>
    );
}

// Zeitraum und Zustand. Beides steht in der Adresszeile und wird serverseitig
// aufgelöst — ein Wechsel ist deshalb ein Seitenaufruf und keine Filterung im
// Browser: was gefiltert wird, liegt in der Datenbank und nicht in der Liste,
// die gerade zu sehen ist.
export function AlertFilterBar({ filter, href }) {
    const t = useT();

    const go = (changes) =>
        router.get(
            href,
            { zeitraum: filter.period, zustand: filter.state, ...changes },
            { preserveState: true, preserveScroll: true, replace: true }
        );

    return (
        <Card>
            <div className="flex flex-wrap items-end gap-4">
                <div>
                    <InputLabel htmlFor="alert-period" value={t('alert_overview.filter.period')} />
                    <SelectInput
                        id="alert-period"
                        className="mt-1"
                        options={filter.periodOptions}
                        value={filter.period}
                        onChange={(e) => go({ zeitraum: e.target.value })}
                    />
                </div>

                <div>
                    <InputLabel htmlFor="alert-state" value={t('alert_overview.filter.state')} />
                    <SelectInput
                        id="alert-state"
                        className="mt-1"
                        options={filter.stateOptions}
                        value={filter.state}
                        onChange={(e) => go({ zustand: e.target.value })}
                    />
                </div>
            </div>
        </Card>
    );
}

// Eine Regel in der Liste: Name, Art, Zustand — und daneben die zwei Zahlen, die
// man tatsächlich sucht (wann zuletzt, wie oft im Zeitraum).
function AlertRow({ row, options, canManage }) {
    const t = useT();

    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <AlertStateBadge state={row.state} label={row.stateLabel} />
                    <Link
                        href={row.detailHref}
                        className="font-medium text-gray-900 underline hover:text-rose-600 dark:text-gray-100 dark:hover:text-rose-400"
                    >
                        {row.name}
                    </Link>
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        {row.kindLabel}
                    </span>
                </div>

                <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{row.subtitle}</p>

                <SnoozeState snooze={row.snooze} />
            </div>

            <div className="flex flex-col items-end gap-1 text-sm">
                <span className="text-gray-600 dark:text-gray-400">
                    {t('alert_overview.list.last')}:{' '}
                    {row.lastAtLabel ?? t('alert_overview.list.never')}
                </span>
                <span className="text-gray-600 dark:text-gray-400">
                    {t('alert_overview.list.count')}:{' '}
                    {t('alert_overview.list.count_value', { count: row.countInPeriod })}
                </span>

                <div className="mt-2 flex flex-wrap items-center justify-end gap-2">
                    <Link href={row.detailHref}>
                        <SecondaryButton type="button">
                            {t('alert_overview.list.detail')}
                        </SecondaryButton>
                    </Link>
                    <Link href={row.configHref}>
                        <SecondaryButton type="button">
                            {t('alert_overview.list.config')}
                        </SecondaryButton>
                    </Link>
                </div>

                <SnoozeForm snooze={row.snooze} options={options} canManage={canManage} />
            </div>
        </div>
    );
}

// Was gerade still ist. Bei der Stummschaltung für alle steht dabei, wer sie
// gesetzt hat: „seit gestern still" ist eine Angabe, „von wem" die
// Anschlussfrage.
export function SnoozeState({ snooze }) {
    const t = useT();

    if (!snooze.everyone && !snooze.mine) {
        return null;
    }

    return (
        <div className="mt-2 space-y-1 text-xs">
            {snooze.everyone && (
                <p className="text-amber-700 dark:text-amber-400">
                    {snooze.everyone.by
                        ? t('alert_overview.snooze.everyone_active_by', {
                              until: snooze.everyone.untilLabel,
                              by: snooze.everyone.by,
                          })
                        : t('alert_overview.snooze.everyone_active', {
                              until: snooze.everyone.untilLabel,
                          })}
                </p>
            )}

            {snooze.mine && (
                <p className="text-gray-600 dark:text-gray-400">
                    {t('alert_overview.snooze.mine_active', { until: snooze.mine.untilLabel })}
                </p>
            )}
        </div>
    );
}

// Stummschalten und wieder aufheben.
//
// Der Geltungsbereich ist eine Auswahl und kein zweiter Knopf: „für alle" und
// „nur für mich" sind dieselbe Handlung mit verschiedener Reichweite, und wer
// sie als zwei Knöpfe nebeneinander sieht, drückt irgendwann den falschen.
//
// Ohne das Recht zur Verwaltung fehlt „für alle" ganz — ein Eintrag, der
// serverseitig abgewiesen würde, hat in der Auswahl nichts zu suchen.
export function SnoozeForm({ snooze, options, canManage }) {
    const t = useT();

    const scopes = options.scopeOptions.filter((scope) => scope.value !== 'everyone' || canManage);

    const form = useForm({
        scope: scopes[0]?.value ?? 'personal',
        minutes: options.durations[0]?.value ?? 60,
    });

    const submit = (event) => {
        event.preventDefault();

        form.post(snooze.storeHref, { preserveScroll: true });
    };

    const lift = (scope) =>
        router.delete(snooze.destroyHref, { data: { scope }, preserveScroll: true });

    // Eine persönliche Stummschaltung an einer Regel, die nur an gemeinsame
    // Kanäle meldet, bliebe wirkungslos. Das steht hier als Satz — der Knopf
    // bleibt trotzdem bedienbar, wenn er der einzige ist, den jemand hat.
    const hint =
        form.data.scope === 'personal' && !snooze.personalEffective
            ? t('alert_overview.snooze.no_personal_effect')
            : null;

    return (
        <form onSubmit={submit} className="mt-2 flex flex-col items-end gap-2">
            <div className="flex flex-wrap items-end justify-end gap-2">
                <SelectInput
                    aria-label={t('alert_overview.snooze.scope')}
                    options={scopes}
                    value={form.data.scope}
                    onChange={(e) => form.setData('scope', e.target.value)}
                />
                <SelectInput
                    aria-label={t('alert_overview.snooze.duration')}
                    options={options.durations}
                    value={form.data.minutes}
                    onChange={(e) => form.setData('minutes', Number(e.target.value))}
                />
                <PrimaryButton type="submit" disabled={form.processing}>
                    {t('alert_overview.snooze.submit')}
                </PrimaryButton>
            </div>

            {hint && (
                <p className="max-w-md text-end text-xs text-gray-500 dark:text-gray-400">{hint}</p>
            )}

            <div className="flex flex-wrap items-center justify-end gap-2">
                {snooze.everyone && canManage && (
                    <SecondaryButton type="button" onClick={() => lift('everyone')}>
                        {t('alert_overview.snooze.lift')} — {t('alert_overview.scopes.everyone')}
                    </SecondaryButton>
                )}

                {snooze.mine && (
                    <SecondaryButton type="button" onClick={() => lift('personal')}>
                        {t('alert_overview.snooze.lift')} — {t('alert_overview.scopes.personal')}
                    </SecondaryButton>
                )}
            </div>
        </form>
    );
}

// Der Verlauf: was wann gefeuert hat.
//
// Beide Arten in einer Liste — der Grund ist derselbe wie für die Seite selbst:
// wer nach einer Störung nachsieht, sortiert nicht nach Alarm-Art, sondern nach
// Uhrzeit.
export function AlertHistoryList({ history, withAlertName = false }) {
    const t = useT();

    return (
        <Card title={t('alert_overview.history.title')}>
            {history.length === 0 ? (
                <p className="text-sm text-gray-600 dark:text-gray-400">
                    {t('alert_overview.history.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    {history.map((entry) => (
                        <li key={entry.id} className="flex flex-wrap items-center gap-3 py-2">
                            <AlertStateBadge state={entry.state} label={entry.stateLabel} />

                            {withAlertName &&
                                (entry.alertHref ? (
                                    <Link
                                        href={entry.alertHref}
                                        className="font-medium text-gray-900 underline hover:text-rose-600 dark:text-gray-100 dark:hover:text-rose-400"
                                    >
                                        {entry.alert}
                                    </Link>
                                ) : (
                                    <span className="font-medium text-gray-900 dark:text-gray-100">
                                        {entry.alert}
                                    </span>
                                ))}

                            <span className="text-gray-600 dark:text-gray-400">
                                {entry.kindLabel}
                            </span>

                            {entry.detail &&
                                (entry.issueHref ? (
                                    <Link
                                        href={entry.issueHref}
                                        className="truncate text-gray-600 underline hover:text-rose-600 dark:text-gray-400 dark:hover:text-rose-400"
                                    >
                                        {entry.detail}
                                    </Link>
                                ) : (
                                    <span className="truncate text-gray-600 dark:text-gray-400">
                                        {entry.detail}
                                    </span>
                                ))}

                            {/* Null Zustellungen ist die aussagekräftigste Zahl
                                der Liste: die Regel hat gegriffen und es ist
                                nichts hinausgegangen. */}
                            {entry.deliveryCount !== null && (
                                <span
                                    className={
                                        entry.deliveryCount === 0
                                            ? 'text-amber-700 dark:text-amber-400'
                                            : 'text-gray-500 dark:text-gray-400'
                                    }
                                >
                                    {entry.deliveryCount === 0
                                        ? t('alert_overview.history.no_deliveries')
                                        : t('alert_overview.history.deliveries', {
                                              count: entry.deliveryCount,
                                          })}
                                </span>
                            )}

                            <span className="ms-auto text-xs text-gray-500 dark:text-gray-400">
                                {entry.occurredAtLabel}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
