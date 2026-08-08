import React, { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    SelectInput,
    TextInput,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';
import AlertChart from './AlertChart.jsx';

// Die Schwellwert-Alarme eines Projekts.
//
// Ansehen darf jedes Mitglied — welche Alarme scharf sind, ist die erste Frage,
// wenn etwas **nicht** gemeldet wurde. Ändern darf nur die Verwaltung;
// entschieden wird das serverseitig, `canManage` blendet hier lediglich aus, was
// ohnehin abgewiesen würde.
export default function Alerts({
    project,
    organization,
    alerts,
    history,
    metricOptions,
    directionOptions,
    comparisonOptions,
    environmentOptions,
    limits,
    canManage,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('alerts.title', { project: project.name })}
                appName={shell.appName}
                help={t('alerts.help')}
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
                <Card title={t('alerts.intro.title')} description={t('alerts.intro.description')}>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {t('alerts.intro.gaps_hint')}
                    </p>
                </Card>

                {alerts.length === 0 && (
                    <Card>
                        <p className="text-sm text-gray-600 dark:text-gray-400">
                            {t('alerts.list.empty')}
                        </p>
                    </Card>
                )}

                {alerts.map((alert) => (
                    <AlertCard
                        key={alert.id}
                        alert={alert}
                        project={project}
                        metricOptions={metricOptions}
                        directionOptions={directionOptions}
                        comparisonOptions={comparisonOptions}
                        environmentOptions={environmentOptions}
                        limits={limits}
                        canManage={canManage}
                    />
                ))}

                {canManage && alerts.length < limits.maxPerProject && (
                    <CreateAlert
                        project={project}
                        metricOptions={metricOptions}
                        directionOptions={directionOptions}
                        comparisonOptions={comparisonOptions}
                        environmentOptions={environmentOptions}
                        limits={limits}
                    />
                )}

                <History history={history} />
            </div>
        </>
    );
}

// Der Zustand als farbige Marke. Er ist die eine Angabe, die man im
// Vorbeigehen liest — alles andere steht darunter.
function StatusBadge({ status, label }) {
    const tone =
        {
            ok: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
            warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
            critical: 'bg-rose-600 text-white',
        }[status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';

    return (
        <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-semibold ${tone}`}>
            {label}
        </span>
    );
}

function AlertCard({
    alert,
    project,
    metricOptions,
    directionOptions,
    comparisonOptions,
    environmentOptions,
    limits,
    canManage,
}) {
    const t = useT();

    return (
        <Card
            title={
                <span className="flex flex-wrap items-center gap-2">
                    {alert.name}
                    <StatusBadge status={alert.status} label={alert.statusLabel} />
                    {!alert.active && (
                        <span className="rounded-full bg-gray-200 px-2 py-0.5 text-xs font-normal text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                            {t('alerts.list.inactive_badge')}
                        </span>
                    )}
                </span>
            }
            description={t('alerts.list.subtitle', {
                metric: alert.metricLabel,
                minutes: alert.windowMinutes,
            })}
        >
            <div className="space-y-4">
                <Summary alert={alert} />

                {canManage ? (
                    <div className="space-y-4">
                        <AlertForm
                            alert={alert}
                            project={project}
                            metricOptions={metricOptions}
                            directionOptions={directionOptions}
                            comparisonOptions={comparisonOptions}
                            environmentOptions={environmentOptions}
                            limits={limits}
                        />
                        <Actions alert={alert} />
                    </div>
                ) : null}
            </div>
        </Card>
    );
}

// Was der Alarm zuletzt gesehen hat.
//
// Der Zeitpunkt der letzten Auswertung steht bewusst daneben: ein Alarm, der
// noch nie ausgewertet wurde, ist etwas anderes als einer, bei dem alles in
// Ordnung ist — und in der Marke sähen beide gleich aus.
function Summary({ alert }) {
    const t = useT();

    return (
        <dl className="grid gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt className="text-xs text-gray-500 dark:text-gray-400">
                    {t('alerts.list.last_value')}
                </dt>
                <dd className="mt-1 text-gray-900 dark:text-gray-100">
                    {alert.lastValueLabel === null
                        ? t('alerts.list.no_value')
                        : `${alert.lastValueLabel} ${alert.unit}`.trim()}
                </dd>
            </div>

            <div>
                <dt className="text-xs text-gray-500 dark:text-gray-400">
                    {t('alerts.list.last_evaluated')}
                </dt>
                <dd className="mt-1 text-gray-900 dark:text-gray-100">
                    {alert.lastEvaluatedLabel ?? t('alerts.list.never_evaluated')}
                </dd>
            </div>

            <div>
                <dt className="text-xs text-gray-500 dark:text-gray-400">
                    {t('alerts.list.status_since')}
                </dt>
                <dd className="mt-1 text-gray-900 dark:text-gray-100">
                    {alert.statusSinceLabel ?? '—'}
                </dd>
            </div>
        </dl>
    );
}

function Actions({ alert }) {
    const t = useT();
    const toggle = useForm({});
    const remove = useForm({});

    return (
        <div className="flex flex-wrap gap-2">
            <SecondaryButton
                type="button"
                disabled={toggle.processing}
                onClick={() => toggle.post(alert.toggleHref, { preserveScroll: true })}
            >
                {alert.active ? t('alerts.actions.disable') : t('alerts.actions.enable')}
            </SecondaryButton>

            <DangerButton
                type="button"
                disabled={remove.processing}
                onClick={() => remove.delete(alert.href, { preserveScroll: true })}
            >
                {t('alerts.actions.delete')}
            </DangerButton>
        </div>
    );
}

// Die Vorgaben eines neuen Alarms — dieselben, die auch das Formular eines
// bestehenden füllt, nur leer.
const blank = {
    name: '',
    metric: 'error_count',
    direction: 'above',
    comparison: 'absolute',
    environment: '',
    transaction_name: '',
    window_minutes: 5,
    warning_threshold: '',
    critical_threshold: '',
    resolve_threshold: '',
    minimum_samples: 0,
};

function fromAlert(alert) {
    return {
        name: alert.name,
        metric: alert.metric,
        direction: alert.direction,
        comparison: alert.comparison,
        environment: alert.environment ?? '',
        transaction_name: alert.transactionName ?? '',
        window_minutes: alert.windowMinutes,
        warning_threshold: alert.warningThreshold ?? '',
        critical_threshold: alert.criticalThreshold ?? '',
        resolve_threshold: alert.resolveThreshold ?? '',
        minimum_samples: alert.minimumSamples,
    };
}

// Leere Felder gehen als `null` hinaus und nicht als leerer Text: „keine
// kritische Schwelle" und „kritische Schwelle 0" sind zwei verschiedene
// Einstellungen, und der leere Text sähe für die Prüfung wie eine 0 aus.
function payload(data) {
    const blanked = (value) => (value === '' || value === null ? null : value);

    return {
        ...data,
        environment: blanked(data.environment),
        transaction_name: blanked(data.transaction_name),
        warning_threshold: blanked(data.warning_threshold),
        critical_threshold: blanked(data.critical_threshold),
        resolve_threshold: blanked(data.resolve_threshold),
    };
}

function CreateAlert({
    project,
    metricOptions,
    directionOptions,
    comparisonOptions,
    environmentOptions,
    limits,
}) {
    const t = useT();

    return (
        <Card title={t('alerts.create.title')} description={t('alerts.create.description')}>
            <AlertForm
                project={project}
                metricOptions={metricOptions}
                directionOptions={directionOptions}
                comparisonOptions={comparisonOptions}
                environmentOptions={environmentOptions}
                limits={limits}
            />
        </Card>
    );
}

// Das Formular — für einen bestehenden Alarm (`alert` gesetzt) und für einen
// neuen (ohne). Eines für beide, weil die Felder dieselben sind: zwei Fassungen
// wären die Stelle, an der ein neues Feld nur an einer von beiden landet.
function AlertForm({
    alert = null,
    project,
    metricOptions,
    directionOptions,
    comparisonOptions,
    environmentOptions,
    limits,
}) {
    const t = useT();
    const { shell } = usePage().props;
    const form = useForm(alert === null ? blank : fromAlert(alert));
    const [preview, setPreview] = useState(null);
    const [previewing, setPreviewing] = useState(false);

    const id = (field) => `alert_${alert?.id ?? 'new'}_${field}`;
    const metric = metricOptions.find((option) => option.value === form.data.metric);
    const percentChange = form.data.comparison === 'percent_change_week';
    const unit = percentChange ? '%' : (metric?.unit ?? '');

    const submit = (e) => {
        e.preventDefault();

        const options = { preserveScroll: true };

        if (alert === null) {
            form.transform(payload).post(project.alertsHref, {
                ...options,
                onSuccess: () => form.reset(),
            });

            return;
        }

        form.transform(payload).patch(alert.href, options);
    };

    // Die Vorschau ist bewusst kein Inertia-Aufruf: sie liefert Zahlen und
    // keine Seite, und sie bezieht sich auf einen Entwurf, der noch nicht
    // gespeichert ist.
    const showPreview = async () => {
        setPreviewing(true);

        try {
            const response = await fetch(project.previewHref, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': shell.csrf,
                },
                body: JSON.stringify(payload(form.data)),
            });

            setPreview(response.ok ? await response.json() : null);
        } catch {
            setPreview(null);
        } finally {
            setPreviewing(false);
        }
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div>
                <InputLabel htmlFor={id('name')} value={t('alerts.fields.name')} />
                <TextInput
                    id={id('name')}
                    value={form.data.name}
                    required
                    className="mt-1"
                    onChange={(e) => form.setData('name', e.target.value)}
                />
                <InputError message={form.errors.name} className="mt-2" />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel htmlFor={id('metric')} value={t('alerts.fields.metric')} />
                    <SelectInput
                        id={id('metric')}
                        className="mt-1"
                        value={form.data.metric}
                        options={metricOptions}
                        onChange={(e) => form.setData('metric', e.target.value)}
                    />
                    <InputError message={form.errors.metric} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor={id('direction')} value={t('alerts.fields.direction')} />
                    <SelectInput
                        id={id('direction')}
                        className="mt-1"
                        value={form.data.direction}
                        options={directionOptions}
                        onChange={(e) => form.setData('direction', e.target.value)}
                    />
                    <InputError message={form.errors.direction} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor={id('comparison')} value={t('alerts.fields.comparison')} />
                    <SelectInput
                        id={id('comparison')}
                        className="mt-1"
                        value={form.data.comparison}
                        options={comparisonOptions}
                        onChange={(e) => form.setData('comparison', e.target.value)}
                    />
                    <InputError message={form.errors.comparison} className="mt-2" />
                </div>

                <div>
                    <InputLabel
                        htmlFor={id('window')}
                        value={t('alerts.fields.window', {
                            min: limits.minWindowMinutes,
                            max: limits.maxWindowMinutes,
                        })}
                    />
                    <TextInput
                        id={id('window')}
                        type="number"
                        min={limits.minWindowMinutes}
                        max={limits.maxWindowMinutes}
                        value={form.data.window_minutes}
                        className="mt-1"
                        onChange={(e) => form.setData('window_minutes', e.target.value)}
                    />
                    <InputError message={form.errors.window_minutes} className="mt-2" />
                </div>

                <div>
                    <InputLabel
                        htmlFor={id('environment')}
                        value={t('alerts.fields.environment')}
                    />
                    <SelectInput
                        id={id('environment')}
                        className="mt-1"
                        value={form.data.environment}
                        placeholder={t('alerts.fields.all_environments')}
                        options={environmentOptions.map((name) => ({
                            value: name,
                            label: name,
                        }))}
                        onChange={(e) => form.setData('environment', e.target.value)}
                    />
                    <InputError message={form.errors.environment} className="mt-2" />
                </div>

                {/* Ein Vorgangsname gibt es nur bei den Antwortzeit-Kennzahlen —
                    eine Fehlermeldung trägt keinen. */}
                {metric?.transaction && (
                    <div>
                        <InputLabel
                            htmlFor={id('transaction')}
                            value={t('alerts.fields.transaction')}
                        />
                        <TextInput
                            id={id('transaction')}
                            value={form.data.transaction_name}
                            className="mt-1"
                            placeholder={t('alerts.fields.all_transactions')}
                            onChange={(e) => form.setData('transaction_name', e.target.value)}
                        />
                        <InputError message={form.errors.transaction_name} className="mt-2" />
                    </div>
                )}
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
                <div>
                    <InputLabel
                        htmlFor={id('warning')}
                        value={`${t('alerts.fields.warning')}${unit ? ` (${unit})` : ''}`}
                    />
                    <TextInput
                        id={id('warning')}
                        type="number"
                        step="any"
                        value={form.data.warning_threshold}
                        className="mt-1"
                        onChange={(e) => form.setData('warning_threshold', e.target.value)}
                    />
                    <InputError message={form.errors.warning_threshold} className="mt-2" />
                </div>

                <div>
                    <InputLabel
                        htmlFor={id('critical')}
                        value={`${t('alerts.fields.critical')}${unit ? ` (${unit})` : ''}`}
                    />
                    <TextInput
                        id={id('critical')}
                        type="number"
                        step="any"
                        value={form.data.critical_threshold}
                        className="mt-1"
                        onChange={(e) => form.setData('critical_threshold', e.target.value)}
                    />
                    <InputError message={form.errors.critical_threshold} className="mt-2" />
                </div>

                <div>
                    <InputLabel
                        htmlFor={id('resolve')}
                        value={`${t('alerts.fields.resolve')}${unit ? ` (${unit})` : ''}`}
                    />
                    <TextInput
                        id={id('resolve')}
                        type="number"
                        step="any"
                        value={form.data.resolve_threshold}
                        className="mt-1"
                        onChange={(e) => form.setData('resolve_threshold', e.target.value)}
                    />
                    <InputError message={form.errors.resolve_threshold} className="mt-2" />
                </div>
            </div>

            {/* Die Mindestzahl gilt nur dort, wo eine Quote oder eine
                Antwortzeit gerechnet wird: bei einer Anzahl ist auch null eine
                Aussage. */}
            {metric && !metric.count && (
                <div>
                    <InputLabel htmlFor={id('samples')} value={t('alerts.fields.minimum_samples')} />
                    <TextInput
                        id={id('samples')}
                        type="number"
                        min="0"
                        value={form.data.minimum_samples}
                        className="mt-1 max-w-32"
                        onChange={(e) => form.setData('minimum_samples', e.target.value)}
                    />
                    <InputError message={form.errors.minimum_samples} className="mt-2" />
                </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <PrimaryButton type="submit" disabled={form.processing}>
                    {alert === null ? t('alerts.actions.create') : t('alerts.actions.save')}
                </PrimaryButton>

                <SecondaryButton type="button" disabled={previewing} onClick={showPreview}>
                    {previewing ? t('alerts.actions.previewing') : t('alerts.actions.preview')}
                </SecondaryButton>

                {percentChange && (
                    <span className="text-xs text-gray-500 dark:text-gray-400">
                        {t('alerts.fields.percent_change_hint')}
                    </span>
                )}
            </div>

            {preview && (
                <div className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
                        {t('alerts.preview.caption', {
                            windows: limits.previewWindows,
                            minutes: preview.windowMinutes,
                        })}
                    </p>
                    <AlertChart preview={preview} t={t} />
                </div>
            )}
        </form>
    );
}

// Der Verlauf über alle Alarme des Projekts.
//
// Über alle und nicht je Alarm: die Frage nach einer Störung ist „was war heute
// Nacht los?" und nicht „was war mit Alarm Nr. 3?".
function History({ history }) {
    const t = useT();

    return (
        <Card title={t('alerts.history.title')} description={t('alerts.history.description')}>
            {history.length === 0 ? (
                <p className="text-sm text-gray-600 dark:text-gray-400">
                    {t('alerts.history.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    {history.map((entry) => (
                        <li key={entry.id} className="flex flex-wrap items-center gap-3 py-2">
                            <StatusBadge status={entry.toStatus} label={entry.kindLabel} />
                            <span className="font-medium text-gray-900 dark:text-gray-100">
                                {entry.alert}
                            </span>
                            <span className="text-gray-600 dark:text-gray-400">
                                {entry.valueLabel}
                            </span>
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
