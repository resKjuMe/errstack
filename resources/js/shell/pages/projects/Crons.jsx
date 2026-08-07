import React, { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
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

// Überwachte Cronjobs eines Projekts. Die Seite beantwortet zuerst „läuft
// gerade etwas schief?" und erst danach „wie lief es zuletzt?" — deshalb steht
// der Zustand oben in der Karte und der Verlauf darunter, zum Aufklappen.
// Ansehen darf jedes Mitglied; die Formulare bekommt nur zu sehen, wer die
// Überwachung auch ändern darf (entschieden wird das serverseitig).
export default function Crons({
    project,
    organization,
    permissions,
    monitors,
    scheduleTypeOptions,
    intervalUnitOptions,
    timezones,
    defaults,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('crons.title', { project: project.name })}
                appName={shell.appName}
                help={t('crons.help')}
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
                {monitors.length === 0 && (
                    <Card
                        title={t('crons.empty.title')}
                        description={t('crons.empty.description')}
                    />
                )}

                {monitors.map((monitor) => (
                    <MonitorCard
                        key={monitor.id}
                        monitor={monitor}
                        canManage={permissions.manage}
                        scheduleTypeOptions={scheduleTypeOptions}
                        intervalUnitOptions={intervalUnitOptions}
                        timezones={timezones}
                    />
                ))}

                {permissions.manage && (
                    <CreateMonitor
                        project={project}
                        defaults={defaults}
                        scheduleTypeOptions={scheduleTypeOptions}
                        intervalUnitOptions={intervalUnitOptions}
                        timezones={timezones}
                    />
                )}
            </div>
        </>
    );
}

// Farbe des Zustands. Nur drei Stufen: in Ordnung, auffällig, kaputt — mehr
// Abstufungen liest im Vorbeigehen niemand.
const statusStyles = {
    ok: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
    running: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    missed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    timeout: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    error: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    disabled: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    unknown: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
};

function StatusBadge({ status, label }) {
    const style = statusStyles[status] ?? statusStyles.unknown;

    return <span className={`rounded-full px-2 py-0.5 text-xs font-normal ${style}`}>{label}</span>;
}

function MonitorCard({ monitor, canManage, scheduleTypeOptions, intervalUnitOptions, timezones }) {
    const t = useT();

    return (
        <Card
            title={
                <span className="flex flex-wrap items-center gap-2">
                    {monitor.name}
                    <StatusBadge status={monitor.status} label={monitor.statusLabel} />
                </span>
            }
            description={monitor.scheduleLabel ?? t('crons.schedule.invalid')}
        >
            <div className="space-y-4">
                <Facts monitor={monitor} />

                {monitor.checkInUrl && <CheckInUrl url={monitor.checkInUrl} />}

                <History history={monitor.history} />

                {canManage && (
                    <MonitorSettings
                        monitor={monitor}
                        scheduleTypeOptions={scheduleTypeOptions}
                        intervalUnitOptions={intervalUnitOptions}
                        timezones={timezones}
                    />
                )}

                {canManage && <MonitorActions monitor={monitor} />}
            </div>
        </Card>
    );
}

// Die drei Angaben, die man beim Draufschauen braucht: Kennung (steht im Job),
// letzte Meldung, nächster Termin.
function Facts({ monitor }) {
    const t = useT();

    const facts = [
        [t('crons.facts.slug'), monitor.slug],
        [t('crons.facts.last_check_in'), monitor.lastCheckInLabel ?? t('crons.facts.never')],
        [t('crons.facts.next_due'), monitor.nextDueLabel ?? t('crons.facts.never')],
    ];

    if (monitor.consecutiveFailures > 0) {
        facts.push([
            t('crons.facts.failures'),
            t('crons.facts.failures_value', {
                count: monitor.consecutiveFailures,
                tolerance: monitor.failureTolerance,
            }),
        ]);
    }

    return (
        <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            {facts.map(([label, value]) => (
                <div key={label}>
                    <dt className="text-xs text-gray-500 dark:text-gray-400">{label}</dt>
                    <dd className="font-medium break-all text-gray-800 dark:text-gray-100">
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

// Die Adresse, die in den Job gehört. Sie enthält den öffentlichen Schlüssel und
// wird deshalb nur denen ausgeliefert, die auch die DSN sehen dürfen.
function CheckInUrl({ url }) {
    const t = useT();
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            // Ohne Zugriff auf die Zwischenablage bleibt der Text zum Markieren
            // stehen.
            setCopied(false);
        }
    };

    return (
        <div>
            <div className="flex flex-wrap items-center gap-3">
                <code className="grow rounded-md bg-gray-100 px-3 py-2 font-mono text-sm break-all text-gray-800 dark:bg-gray-900 dark:text-gray-200">
                    {url}
                </code>

                <SecondaryButton type="button" onClick={copy}>
                    {t(copied ? 'crons.copied' : 'crons.copy')}
                </SecondaryButton>
            </div>

            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('crons.check_in_url_hint')}
            </p>
        </div>
    );
}

// Der Verlauf ist zugeklappt: er beantwortet eine Nachfrage, nicht die erste
// Frage. Aufgeklappt zeigt er je Ausführung Ergebnis, Zeit und Dauer.
function History({ history }) {
    const t = useT();

    if (history.length === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">{t('crons.history.empty')}</p>
        );
    }

    return (
        <details className="rounded-md border border-gray-200 dark:border-gray-700">
            <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                {t('crons.history.title', { count: history.length })}
            </summary>

            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th className="px-3 py-2 font-medium">{t('crons.history.status')}</th>
                            <th className="px-3 py-2 font-medium">{t('crons.history.expected')}</th>
                            <th className="px-3 py-2 font-medium">{t('crons.history.started')}</th>
                            <th className="px-3 py-2 font-medium">{t('crons.history.duration')}</th>
                            <th className="px-3 py-2 font-medium">
                                {t('crons.history.environment')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {history.map((entry) => (
                            <tr
                                key={entry.id}
                                className="border-t border-gray-100 dark:border-gray-700"
                            >
                                <td className="px-3 py-2">
                                    <span
                                        className={
                                            entry.isFailure
                                                ? 'text-red-600 dark:text-red-400'
                                                : 'text-gray-700 dark:text-gray-300'
                                        }
                                    >
                                        {entry.statusLabel}
                                    </span>
                                </td>
                                <td className="px-3 py-2 text-gray-600 dark:text-gray-400">
                                    {entry.expectedLabel ?? '—'}
                                </td>
                                <td className="px-3 py-2 text-gray-600 dark:text-gray-400">
                                    {entry.startedLabel ?? '—'}
                                </td>
                                <td className="px-3 py-2 text-gray-600 dark:text-gray-400">
                                    {entry.durationLabel ?? '—'}
                                </td>
                                <td className="px-3 py-2 text-gray-600 dark:text-gray-400">
                                    {entry.environment ?? '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </details>
    );
}

// Die Zeitplan-Felder. Sie stehen im Anlegen- und im Ändern-Formular, deshalb
// als eigene Komponente: zwei Fassungen derselben Auswahl laufen auseinander.
function ScheduleFields({
    data,
    setData,
    errors,
    idPrefix,
    scheduleTypeOptions,
    intervalUnitOptions,
    timezones,
}) {
    const t = useT();
    const isInterval = data.schedule_type === 'interval';

    return (
        <>
            <div>
                <InputLabel
                    htmlFor={`${idPrefix}_schedule_type`}
                    value={t('crons.schedule_type')}
                />
                <SelectInput
                    id={`${idPrefix}_schedule_type`}
                    name="schedule_type"
                    value={data.schedule_type}
                    options={scheduleTypeOptions}
                    className="mt-1"
                    onChange={(e) => setData('schedule_type', e.target.value)}
                />
                <InputError message={errors.schedule_type} className="mt-2" />
            </div>

            {isInterval ? (
                <div className="grid grid-cols-2 gap-2">
                    <div>
                        <InputLabel
                            htmlFor={`${idPrefix}_interval_value`}
                            value={t('crons.interval_value')}
                        />
                        <TextInput
                            id={`${idPrefix}_interval_value`}
                            name="interval_value"
                            type="number"
                            min="1"
                            value={data.interval_value ?? ''}
                            className="mt-1"
                            onChange={(e) => setData('interval_value', e.target.value)}
                        />
                        <InputError message={errors.interval_value} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor={`${idPrefix}_interval_unit`}
                            value={t('crons.interval_unit')}
                        />
                        <SelectInput
                            id={`${idPrefix}_interval_unit`}
                            name="interval_unit"
                            value={data.interval_unit ?? ''}
                            options={intervalUnitOptions}
                            className="mt-1"
                            onChange={(e) => setData('interval_unit', e.target.value)}
                        />
                        <InputError message={errors.interval_unit} className="mt-2" />
                    </div>
                </div>
            ) : (
                <div>
                    <InputLabel
                        htmlFor={`${idPrefix}_schedule_expression`}
                        value={t('crons.expression')}
                    />
                    <TextInput
                        id={`${idPrefix}_schedule_expression`}
                        name="schedule_expression"
                        value={data.schedule_expression ?? ''}
                        placeholder="0 2 * * *"
                        className="mt-1 font-mono"
                        onChange={(e) => setData('schedule_expression', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('crons.expression_hint')}
                    </p>
                    <InputError message={errors.schedule_expression} className="mt-2" />
                </div>
            )}

            <div>
                <InputLabel htmlFor={`${idPrefix}_timezone`} value={t('crons.timezone')} />
                <SelectInput
                    id={`${idPrefix}_timezone`}
                    name="timezone"
                    value={data.timezone}
                    options={timezones.map((zone) => ({ value: zone, label: zone }))}
                    className="mt-1"
                    onChange={(e) => setData('timezone', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('crons.timezone_hint')}
                </p>
                <InputError message={errors.timezone} className="mt-2" />
            </div>
        </>
    );
}

// Toleranz, Laufzeit und die beiden Schwellen. Ebenfalls in beiden Formularen.
function ThresholdFields({ data, setData, errors, idPrefix }) {
    const t = useT();

    const fields = [
        ['checkin_margin_minutes', 'crons.margin', 'crons.margin_hint', 0],
        ['max_runtime_minutes', 'crons.max_runtime', 'crons.max_runtime_hint', 1],
        ['failure_tolerance', 'crons.failure_tolerance', 'crons.failure_tolerance_hint', 1],
        ['recovery_tolerance', 'crons.recovery_tolerance', 'crons.recovery_tolerance_hint', 1],
    ];

    return fields.map(([field, label, hint, min]) => (
        <div key={field}>
            <InputLabel htmlFor={`${idPrefix}_${field}`} value={t(label)} />
            <TextInput
                id={`${idPrefix}_${field}`}
                name={field}
                type="number"
                min={String(min)}
                value={data[field]}
                className="mt-1"
                onChange={(e) => setData(field, e.target.value)}
            />
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{t(hint)}</p>
            <InputError message={errors[field]} className="mt-2" />
        </div>
    ));
}

function MonitorSettings({ monitor, scheduleTypeOptions, intervalUnitOptions, timezones }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        name: monitor.name,
        schedule_type: monitor.scheduleType,
        schedule_expression: monitor.scheduleExpression ?? '',
        interval_value: monitor.intervalValue ?? '',
        interval_unit: monitor.intervalUnit ?? '',
        timezone: monitor.timezone,
        checkin_margin_minutes: monitor.checkinMarginMinutes,
        max_runtime_minutes: monitor.maxRuntimeMinutes,
        failure_tolerance: monitor.failureTolerance,
        recovery_tolerance: monitor.recoveryTolerance,
        is_active: monitor.isActive,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(monitor.href, { preserveScroll: true });
    };

    return (
        <form
            onSubmit={submit}
            className="grid grid-cols-1 gap-4 border-t border-gray-200 pt-4 md:grid-cols-2 dark:border-gray-700"
        >
            <div>
                <InputLabel htmlFor={`name_${monitor.id}`} value={t('crons.name')} />
                <TextInput
                    id={`name_${monitor.id}`}
                    name="name"
                    value={data.name}
                    required
                    className="mt-1"
                    onChange={(e) => setData('name', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('crons.name_hint')}
                </p>
                <InputError message={errors.name} className="mt-2" />
            </div>

            <ScheduleFields
                data={data}
                setData={setData}
                errors={errors}
                idPrefix={`monitor_${monitor.id}`}
                scheduleTypeOptions={scheduleTypeOptions}
                intervalUnitOptions={intervalUnitOptions}
                timezones={timezones}
            />

            <ThresholdFields
                data={data}
                setData={setData}
                errors={errors}
                idPrefix={`monitor_${monitor.id}`}
            />

            <div className="md:col-span-2">
                <PrimaryButton type="submit" disabled={processing}>
                    {t('crons.save')}
                </PrimaryButton>
            </div>
        </form>
    );
}

function MonitorActions({ monitor }) {
    const t = useT();
    const { post, delete: destroy, processing } = useForm({});

    return (
        <div className="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
            <SecondaryButton
                type="button"
                disabled={processing}
                onClick={() => post(monitor.toggleHref, { preserveScroll: true })}
            >
                {t(monitor.isActive ? 'crons.disable' : 'crons.enable')}
            </SecondaryButton>

            <DangerButton
                type="button"
                disabled={processing}
                onClick={() => destroy(monitor.href, { preserveScroll: true })}
            >
                {t('crons.delete')}
            </DangerButton>

            <p className="text-xs text-gray-500 dark:text-gray-400">{t('crons.disable_hint')}</p>
        </div>
    );
}

function CreateMonitor({ project, defaults, scheduleTypeOptions, intervalUnitOptions, timezones }) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        schedule_type: defaults.scheduleType,
        schedule_expression: defaults.scheduleExpression,
        interval_value: defaults.intervalValue,
        interval_unit: defaults.intervalUnit,
        timezone: defaults.timezone,
        checkin_margin_minutes: defaults.checkinMarginMinutes,
        max_runtime_minutes: defaults.maxRuntimeMinutes,
        failure_tolerance: defaults.failureTolerance,
        recovery_tolerance: defaults.recoveryTolerance,
        is_active: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(project.cronsHref, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Card title={t('crons.create.title')} description={t('crons.create.description')}>
            <form onSubmit={submit} className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <InputLabel htmlFor="new_cron_name" value={t('crons.name')} />
                    <TextInput
                        id="new_cron_name"
                        name="name"
                        value={data.name}
                        required
                        placeholder="Nächtlicher Import"
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('crons.name_hint')}
                    </p>
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <ScheduleFields
                    data={data}
                    setData={setData}
                    errors={errors}
                    idPrefix="new_cron"
                    scheduleTypeOptions={scheduleTypeOptions}
                    intervalUnitOptions={intervalUnitOptions}
                    timezones={timezones}
                />

                <ThresholdFields
                    data={data}
                    setData={setData}
                    errors={errors}
                    idPrefix="new_cron"
                />

                <div className="md:col-span-2">
                    <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <Checkbox
                            name="is_active"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                        />
                        {t('crons.active')}
                    </label>
                </div>

                <div className="md:col-span-2">
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('crons.create.submit')}
                    </PrimaryButton>
                </div>
            </form>
        </Card>
    );
}
