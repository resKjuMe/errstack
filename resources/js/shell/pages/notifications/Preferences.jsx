import React, { useMemo, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    Checkbox,
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    SelectInput,
    TextInput,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Persönliche Benachrichtigungen: was erreicht mich worüber — überall, je
// Organisation und je Projekt.
//
// Die Übersicht ist bewusst eine Tabelle je Bereich statt eines langen
// Formulars über alle Bereiche: gefragt wird immer „was passiert in diesem
// Projekt", nie „wo überall habe ich Zuweisungen abgeschaltet". Die Spalte
// „Wirksam" beantwortet dabei die eigentliche Frage — eine geerbte Einstellung
// sagt für sich genommen nichts darüber, ob am Ende eine Mail kommt.

// Die Auswahl je Zelle. Sie hängt an der Sprache und entsteht deshalb erst im
// Render, nicht als Modul-Konstante.
function choicesFor(t, inherits) {
    const choices = [
        { value: 'on', label: t('notifications.preferences.choice_on') },
        { value: 'off', label: t('notifications.preferences.choice_off') },
    ];

    return inherits
        ? [{ value: 'inherit', label: t('notifications.preferences.choice_inherit') }, ...choices]
        : choices;
}

export default function Preferences({
    events,
    transports,
    scopes,
    quietHours,
    digestEnabled,
    unsubscribedAt,
    mutedCritical,
    hrefs,
}) {
    const { shell } = usePage().props;
    const t = useT();
    const [scopeKey, setScopeKey] = useState(scopes[0]?.key ?? 'global');
    const scope = useMemo(
        () => scopes.find((entry) => entry.key === scopeKey) ?? scopes[0],
        [scopes, scopeKey]
    );

    return (
        <>
            <PageHead
                title={t('notifications.preferences.title')}
                appName={shell.appName}
                help={t('notifications.preferences.help')}
            />

            <div className="space-y-6">
                <CriticalWarning muted={mutedCritical} />

                <Card
                    title={t('notifications.preferences.scope_title')}
                    description={t('notifications.preferences.scope_description')}
                >
                    <SelectInput
                        aria-label={t('notifications.preferences.scope_title')}
                        value={scopeKey}
                        options={scopes.map((entry) => ({
                            value: entry.key,
                            label: labelFor(t, entry),
                        }))}
                        onChange={(e) => setScopeKey(e.target.value)}
                    />
                    <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">{scope?.hint}</p>
                </Card>

                {scope && (
                    <Matrix
                        key={scope.key}
                        scope={scope}
                        events={events}
                        transports={transports}
                        href={hrefs.update}
                    />
                )}

                <QuietHours quietHours={quietHours} href={hrefs.quietHours} />

                <Digest enabled={digestEnabled} href={hrefs.digest} />

                <Subscription unsubscribedAt={unsubscribedAt} href={hrefs.subscription} />
            </div>
        </>
    );
}

function labelFor(t, scope) {
    if (scope.kind === 'project') {
        return t('notifications.preferences.scope_project', { name: scope.label });
    }

    if (scope.kind === 'organization') {
        return t('notifications.preferences.scope_organization', { name: scope.label });
    }

    return scope.label;
}

// Ein kritischer Alarm, der nirgends mehr ankommt, ist der eine Zustand, den
// niemand versehentlich haben will — deshalb steht er ganz oben und nicht als
// Fußnote unter der Tabelle.
function CriticalWarning({ muted }) {
    const t = useT();

    if (!muted || muted.length === 0) {
        return null;
    }

    return (
        <div className="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            <p className="font-semibold">{t('notifications.preferences.critical_warning')}</p>
            <ul className="mt-2 list-disc space-y-1 ps-5">
                {muted.map((entry) => (
                    <li key={`${entry.scope}-${entry.event}`}>
                        {t('notifications.preferences.critical_entry', {
                            event: entry.event,
                            scope: entry.scope,
                        })}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Matrix({ scope, events, transports, href }) {
    const t = useT();
    const choices = choicesFor(t, scope.inherits);
    const { data, setData, transform, put, processing, errors, recentlySuccessful } = useForm({
        scope: scope.key,
        preferences: scope.rows,
    });

    // Die Zellen tragen im Formular auch den wirksamen Zustand mit sich, damit
    // er unter dem Auswahlfeld stehen kann. Der Server will davon nur die
    // Entscheidung sehen — alles andere wäre für ihn ein unerwarteter Wert.
    transform((payload) => ({
        scope: payload.scope,
        preferences: Object.fromEntries(
            Object.entries(payload.preferences).map(([event, cells]) => [
                event,
                Object.fromEntries(
                    Object.entries(cells).map(([transport, cell]) => [transport, cell.choice])
                ),
            ])
        ),
    }));

    const submit = (e) => {
        e.preventDefault();
        put(href, { preserveScroll: true });
    };

    const setChoice = (event, transport, choice) =>
        setData('preferences', {
            ...data.preferences,
            [event]: {
                ...data.preferences[event],
                [transport]: { ...data.preferences[event][transport], choice },
            },
        });

    return (
        <Card
            title={labelFor(t, scope)}
            description={t('notifications.preferences.matrix_description')}
        >
            <form onSubmit={submit}>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 dark:text-gray-400">
                                <th className="py-2 pe-4 font-medium">
                                    {t('notifications.preferences.event_column')}
                                </th>
                                {transports.map((transport) => (
                                    <th key={transport.value} className="px-4 py-2 font-medium">
                                        {transport.label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                            {events.map((event) => (
                                <tr key={event.value}>
                                    <th scope="row" className="py-3 pe-4 text-left font-normal">
                                        <span className="font-medium text-gray-900 dark:text-gray-100">
                                            {event.label}
                                        </span>
                                        {event.critical && (
                                            <span className="ms-2 rounded bg-rose-100 px-1.5 py-0.5 text-xs font-semibold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
                                                {t('notifications.preferences.critical')}
                                            </span>
                                        )}
                                        <span className="block text-xs text-gray-500 dark:text-gray-400">
                                            {event.description}
                                        </span>
                                    </th>
                                    {transports.map((transport) => {
                                        const cell = data.preferences[event.value][transport.value];

                                        return (
                                            <td key={transport.value} className="px-4 py-3">
                                                <SelectInput
                                                    aria-label={t(
                                                        'notifications.preferences.cell_label',
                                                        {
                                                            event: event.label,
                                                            transport: transport.label,
                                                        }
                                                    )}
                                                    value={cell.choice}
                                                    options={choices}
                                                    onChange={(e) =>
                                                        setChoice(
                                                            event.value,
                                                            transport.value,
                                                            e.target.value
                                                        )
                                                    }
                                                />
                                                <Effective cell={cell} />
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <InputError message={errors.scope} className="mt-2" />

                <div className="mt-6 flex items-center gap-4">
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('notifications.preferences.save')}
                    </PrimaryButton>
                    {recentlySuccessful && (
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {t('notifications.preferences.saved')}
                        </p>
                    )}
                </div>
            </form>
        </Card>
    );
}

// Der gespeicherte Zustand, nicht der gerade ausgewählte: was wirksam ist,
// weiß erst der Server (die Vererbung hängt an allen Bereichen zugleich).
function Effective({ cell }) {
    const t = useT();

    if (cell.choice !== 'inherit') {
        return null;
    }

    return (
        <span className="mt-1 block text-xs text-gray-500 dark:text-gray-400">
            {t(
                cell.effective
                    ? 'notifications.preferences.effective_on'
                    : 'notifications.preferences.effective_off'
            )}
        </span>
    );
}

function QuietHours({ quietHours, href }) {
    const t = useT();
    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        quiet_hours_enabled: quietHours.enabled,
        quiet_from: quietHours.from,
        quiet_until: quietHours.until,
        timezone: quietHours.timezone,
    });

    const submit = (e) => {
        e.preventDefault();
        put(href, { preserveScroll: true });
    };

    return (
        <Card
            title={t('notifications.preferences.quiet_title')}
            description={t('notifications.preferences.quiet_description')}
        >
            {quietHours.activeUntil && (
                <p className="mb-4 rounded-md bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                    {t('notifications.preferences.quiet_active', {
                        time: quietHours.activeUntil,
                    })}
                </p>
            )}

            <form onSubmit={submit} className="space-y-4">
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <Checkbox
                        checked={data.quiet_hours_enabled}
                        onChange={(e) => setData('quiet_hours_enabled', e.target.checked)}
                    />
                    {t('notifications.preferences.quiet_enabled')}
                </label>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <InputLabel
                            htmlFor="quiet_from"
                            value={t('notifications.preferences.quiet_from')}
                        />
                        <TextInput
                            id="quiet_from"
                            type="time"
                            value={data.quiet_from}
                            className="mt-1"
                            onChange={(e) => setData('quiet_from', e.target.value)}
                        />
                        <InputError message={errors.quiet_from} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="quiet_until"
                            value={t('notifications.preferences.quiet_until')}
                        />
                        <TextInput
                            id="quiet_until"
                            type="time"
                            value={data.quiet_until}
                            className="mt-1"
                            onChange={(e) => setData('quiet_until', e.target.value)}
                        />
                        <InputError message={errors.quiet_until} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="timezone"
                            value={t('notifications.preferences.timezone')}
                        />
                        <SelectInput
                            id="timezone"
                            value={data.timezone}
                            className="mt-1"
                            options={quietHours.timezones.map((zone) => ({
                                value: zone,
                                label: zone,
                            }))}
                            onChange={(e) => setData('timezone', e.target.value)}
                        />
                        <InputError message={errors.timezone} className="mt-2" />
                    </div>
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('notifications.preferences.save')}
                    </PrimaryButton>
                    {recentlySuccessful && (
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {t('notifications.preferences.saved')}
                        </p>
                    )}
                </div>
            </form>
        </Card>
    );
}

// Die Bündelung abschalten (A6). Sie wird je Projekt eingestellt, hier
// widerspricht der Einzelne für sich — und zwar für alle Projekte zugleich:
// wer Sammelnachrichten nicht mag, mag sie nirgends, und eine Einstellung je
// Projekt wäre eine Liste, die niemand pflegt.
function Digest({ enabled, href }) {
    const t = useT();
    const [processing, setProcessing] = useState(false);

    const toggle = (next) => {
        setProcessing(true);

        router.post(
            href,
            { digest_enabled: next },
            { preserveScroll: true, onFinish: () => setProcessing(false) }
        );
    };

    return (
        <Card
            title={t('notifications.preferences.digest_title')}
            description={
                enabled
                    ? t('notifications.preferences.digest_description_on')
                    : t('notifications.preferences.digest_description_off')
            }
        >
            <SecondaryButton type="button" disabled={processing} onClick={() => toggle(!enabled)}>
                {enabled
                    ? t('notifications.preferences.digest_disable')
                    : t('notifications.preferences.digest_enable')}
            </SecondaryButton>
        </Card>
    );
}

// Bewusst über `router` statt über useForm: das Formular hat kein Feld, nur
// zwei Knöpfe, und der Wert steht erst beim Klick fest.
function Subscription({ unsubscribedAt, href }) {
    const t = useT();
    const [processing, setProcessing] = useState(false);

    const toggle = (unsubscribed) => {
        setProcessing(true);

        router.post(
            href,
            { unsubscribed },
            { preserveScroll: true, onFinish: () => setProcessing(false) }
        );
    };

    if (unsubscribedAt) {
        return (
            <Card
                title={t('notifications.preferences.unsubscribed_title')}
                description={t('notifications.preferences.unsubscribed_description', {
                    date: unsubscribedAt,
                })}
            >
                <SecondaryButton type="button" disabled={processing} onClick={() => toggle(false)}>
                    {t('notifications.preferences.resubscribe')}
                </SecondaryButton>
            </Card>
        );
    }

    return (
        <Card
            title={t('notifications.preferences.unsubscribe_title')}
            description={t('notifications.preferences.unsubscribe_description')}
        >
            <DangerButton type="button" disabled={processing} onClick={() => toggle(true)}>
                {t('notifications.preferences.unsubscribe')}
            </DangerButton>
        </Card>
    );
}
