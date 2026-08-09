import React from 'react';
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

// Erreichbarkeits-Überwachung eines Projekts. Die Seite beantwortet drei Fragen
// in dieser Reihenfolge: „ist es gerade da?", „wie zuverlässig war es?" und
// „wann war es weg?" — deshalb Zustand oben, Quote und Kurve in der Mitte, die
// Ausfälle darunter zum Aufklappen. Ansehen darf jedes Mitglied; die Formulare
// bekommt nur zu sehen, wer die Überwachung ändern darf (serverseitig
// entschieden).
export default function Uptime({
    project,
    organization,
    permissions,
    monitors,
    methodOptions,
    defaults,
    minimumIntervalSeconds,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('uptime.title', { project: project.name })}
                appName={shell.appName}
                help={t('uptime.help')}
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
                        title={t('uptime.empty.title')}
                        description={t('uptime.empty.description')}
                    />
                )}

                {monitors.map((monitor) => (
                    <MonitorCard
                        key={monitor.id}
                        monitor={monitor}
                        canManage={permissions.manage}
                        methodOptions={methodOptions}
                        minimumIntervalSeconds={minimumIntervalSeconds}
                    />
                ))}

                {permissions.manage && (
                    <CreateMonitor
                        project={project}
                        defaults={defaults}
                        methodOptions={methodOptions}
                        minimumIntervalSeconds={minimumIntervalSeconds}
                    />
                )}
            </div>
        </>
    );
}

// Farbe des Zustands. Vier Stufen, weil „auffällig" eine eigene Aussage ist:
// eine Prüfung ist gescheitert, aber die Schwelle für einen Ausfall ist noch
// nicht erreicht — die Minute, in der man noch etwas tun kann.
const statusStyles = {
    up: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200',
    degraded: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
    down: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    disabled: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    unknown: 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
};

function StatusBadge({ status, label }) {
    const style = statusStyles[status] ?? statusStyles.unknown;

    return <span className={`rounded-full px-2 py-0.5 text-xs font-normal ${style}`}>{label}</span>;
}

function MonitorCard({ monitor, canManage, methodOptions, minimumIntervalSeconds }) {
    const t = useT();

    return (
        <Card
            title={
                <span className="flex flex-wrap items-center gap-2">
                    {monitor.name}
                    <StatusBadge status={monitor.status} label={monitor.statusLabel} />
                </span>
            }
            description={`${monitor.method} ${monitor.url}`}
        >
            <div className="space-y-4">
                <Facts monitor={monitor} />

                <Availability availability={monitor.availability} />

                <ResponseTimes points={monitor.responseTimes} />

                <Outages outages={monitor.outages} />

                {canManage && (
                    <MonitorSettings
                        monitor={monitor}
                        methodOptions={methodOptions}
                        minimumIntervalSeconds={minimumIntervalSeconds}
                    />
                )}

                {canManage && <MonitorActions monitor={monitor} />}
            </div>
        </Card>
    );
}

// Die Angaben, die man beim Draufschauen braucht: Ziel, Takt, letzte und
// nächste Prüfung — und die laufende Fehlerserie, sobald es eine gibt.
function Facts({ monitor }) {
    const t = useT();

    const facts = [
        [
            t('uptime.facts.interval'),
            t('uptime.facts.interval_value', { seconds: monitor.intervalSeconds }),
        ],
        [t('uptime.facts.last_checked'), monitor.lastCheckedLabel ?? t('uptime.facts.never')],
        [t('uptime.facts.next_check'), monitor.nextCheckLabel ?? t('uptime.facts.never')],
        [
            t('uptime.facts.average'),
            monitor.averageResponseMs === null ? '—' : `${monitor.averageResponseMs} ms`,
        ],
    ];

    if (monitor.consecutiveFailures > 0) {
        facts.push([
            t('uptime.facts.failures'),
            t('uptime.facts.failures_value', {
                count: monitor.consecutiveFailures,
                threshold: monitor.failureThreshold,
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

// Die Verfügbarkeitsquote über drei Fenster. Ein einzelnes beantwortet entweder
// „läuft es gerade" oder „wie war der Monat", nie beides.
function Availability({ availability }) {
    const t = useT();

    const windows = ['day', 'week', 'month'];

    return (
        <div>
            <h3 className="text-xs font-medium text-gray-500 dark:text-gray-400">
                {t('uptime.availability.title')}
            </h3>

            <dl className="mt-2 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                {windows.map((key) => {
                    const window = availability[key];

                    return (
                        <div key={key}>
                            <dt className="text-xs text-gray-500 dark:text-gray-400">
                                {t(`uptime.availability.${key}`)}
                            </dt>
                            <dd className="font-medium text-gray-800 dark:text-gray-100">
                                {window.availability === null
                                    ? t('uptime.availability.none')
                                    : `${formatPercent(window.availability)} %`}
                            </dd>
                            {window.checks > 0 && (
                                <dd className="text-xs text-gray-500 dark:text-gray-400">
                                    {t('uptime.availability.checks', {
                                        count: window.checks,
                                        failures: window.failures,
                                    })}
                                </dd>
                            )}
                        </div>
                    );
                })}
            </dl>
        </div>
    );
}

// Drei Nachkommastellen sind die Auflösung, in der über Verfügbarkeit geredet
// wird („99,9 %"). Nachlaufende Nullen fallen weg — „100 %" liest sich besser
// als „100,000 %".
function formatPercent(value) {
    return Number(value)
        .toFixed(3)
        .replace(/\.?0+$/, '');
}

// Der Antwortzeit-Verlauf als Kurve. Gescheiterte Prüfungen sind Lücken, keine
// Nullen — die Lücke ist die Aussage; eine Null wäre die schnellste Antwort des
// Tages.
function ResponseTimes({ points }) {
    const t = useT();

    if (points.length === 0) {
        return (
            <div>
                <h3 className="text-xs font-medium text-gray-500 dark:text-gray-400">
                    {t('uptime.response_times.title')}
                </h3>
                <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {t('uptime.response_times.empty')}
                </p>
            </div>
        );
    }

    const width = 600;
    const height = 60;
    const values = points.filter((point) => point.ms !== null).map((point) => point.ms);
    // Ohne eine einzige gemessene Zeit gibt es keinen Maßstab — dann bleibt nur
    // die Fläche der Ausfälle, und die braucht keinen.
    const max = values.length > 0 ? Math.max(...values) : 1;
    const step = points.length > 1 ? width / (points.length - 1) : width;
    const summary =
        values.length > 0
            ? t('uptime.response_times.summary', {
                  count: points.length,
                  from: `${Math.min(...values)} ms`,
                  to: `${max} ms`,
              })
            : t('uptime.response_times.empty');

    // Jede zusammenhängende Folge gemessener Punkte wird ein eigener Linienzug.
    // Über eine Lücke hinweg zu zeichnen ergäbe eine glatte Linie durch einen
    // Ausfall — genau die Auskunft, die hier fehlen soll.
    const segments = [];
    let current = [];

    points.forEach((point, index) => {
        if (point.ms === null) {
            if (current.length > 0) {
                segments.push(current);
                current = [];
            }

            return;
        }

        current.push(
            `${(index * step).toFixed(1)},${(height - (point.ms / max) * height).toFixed(1)}`
        );
    });

    if (current.length > 0) {
        segments.push(current);
    }

    return (
        <div>
            <h3 className="text-xs font-medium text-gray-500 dark:text-gray-400">
                {t('uptime.response_times.title')}
            </h3>

            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                role="img"
                aria-label={summary}
                className="mt-2 h-16 w-full text-blue-600 dark:text-blue-400"
            >
                {points.map((point, index) =>
                    point.ms === null ? (
                        <rect
                            key={`gap-${index}`}
                            x={(index * step - step / 2).toFixed(1)}
                            y="0"
                            width={Math.max(step, 1).toFixed(1)}
                            height={height}
                            className="fill-red-200 dark:fill-red-900"
                        />
                    ) : null
                )}

                {segments.map((segment, index) => (
                    <polyline
                        key={`line-${index}`}
                        points={segment.join(' ')}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.5"
                        vectorEffect="non-scaling-stroke"
                    />
                ))}
            </svg>

            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{summary}</p>
        </div>
    );
}

// Die Ausfälle sind zugeklappt: sie beantworten eine Nachfrage, nicht die erste
// Frage. Aufgeklappt zeigen sie Grund, Beginn, Ende, Dauer und den Weg zum
// Fehler-Eintrag.
function Outages({ outages }) {
    const t = useT();

    if (outages.length === 0) {
        return (
            <p className="text-sm text-gray-500 dark:text-gray-400">{t('uptime.outages.empty')}</p>
        );
    }

    return (
        <details className="rounded-md border border-gray-200 dark:border-gray-700">
            <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                {t('uptime.outages.title', { count: outages.length })}
            </summary>

            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th className="px-3 py-2 font-medium">{t('uptime.outages.reason')}</th>
                            <th className="px-3 py-2 font-medium">{t('uptime.outages.started')}</th>
                            <th className="px-3 py-2 font-medium">{t('uptime.outages.ended')}</th>
                            <th className="px-3 py-2 font-medium">
                                {t('uptime.outages.duration')}
                            </th>
                            <th className="px-3 py-2 font-medium">{t('uptime.outages.issue')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {outages.map((outage) => (
                            <tr
                                key={outage.id}
                                className="border-t border-gray-100 dark:border-gray-700"
                            >
                                <td className="px-3 py-2">
                                    <span className="text-red-600 dark:text-red-400">
                                        {outage.reasonLabel}
                                    </span>
                                    {outage.httpStatus !== null && (
                                        <span className="ml-1 text-gray-500 dark:text-gray-400">
                                            ({outage.httpStatus})
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2 text-gray-600 dark:text-gray-400">
                                    {outage.startedLabel}
                                </td>
                                <td className="px-3 py-2 text-gray-600 dark:text-gray-400">
                                    {outage.isRunning
                                        ? t('uptime.outages.running')
                                        : (outage.endedLabel ?? '—')}
                                </td>
                                <td className="px-3 py-2 text-gray-600 dark:text-gray-400">
                                    {outage.durationLabel}
                                </td>
                                <td className="px-3 py-2">
                                    {outage.issueHref ? (
                                        <Link
                                            href={outage.issueHref}
                                            className="text-blue-600 underline dark:text-blue-400"
                                        >
                                            {t('uptime.outages.open_issue')}
                                        </Link>
                                    ) : (
                                        <span className="text-gray-500 dark:text-gray-400">—</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </details>
    );
}

// Die Felder der Anfrage. Sie stehen im Anlegen- und im Ändern-Formular,
// deshalb als eigene Komponente: zwei Fassungen derselben Eingabe laufen
// auseinander.
function RequestFields({ data, setData, errors, idPrefix, methodOptions }) {
    const t = useT();
    const acceptsBody = ['POST', 'PUT', 'PATCH'].includes(data.method);
    // Ein HEAD überträgt keinen Rumpf; eine Inhaltsprüfung daneben würde bei
    // jedem Lauf scheitern. Der Server weist das ab — hier steht nur, dass es
    // gar nicht erst eingetippt wird.
    const contentDisabled = data.method === 'HEAD';

    return (
        <>
            <div className="md:col-span-2">
                <InputLabel htmlFor={`${idPrefix}_url`} value={t('uptime.url')} />
                <TextInput
                    id={`${idPrefix}_url`}
                    name="url"
                    type="url"
                    value={data.url}
                    required
                    placeholder="https://example.com/health"
                    className="mt-1"
                    onChange={(e) => setData('url', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('uptime.url_hint')}
                </p>
                <InputError message={errors.url} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor={`${idPrefix}_method`} value={t('uptime.method')} />
                <SelectInput
                    id={`${idPrefix}_method`}
                    name="method"
                    value={data.method}
                    options={methodOptions}
                    className="mt-1"
                    onChange={(e) => setData('method', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('uptime.method_hint')}
                </p>
                <InputError message={errors.method} className="mt-2" />
            </div>

            <div>
                <InputLabel
                    htmlFor={`${idPrefix}_expected_status_codes`}
                    value={t('uptime.expected_status_codes')}
                />
                <TextInput
                    id={`${idPrefix}_expected_status_codes`}
                    name="expected_status_codes"
                    value={data.expected_status_codes}
                    placeholder="200-299"
                    className="mt-1 font-mono"
                    onChange={(e) => setData('expected_status_codes', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('uptime.expected_status_codes_hint')}
                </p>
                <InputError message={errors.expected_status_codes} className="mt-2" />
            </div>

            <div className="md:col-span-2">
                <InputLabel
                    htmlFor={`${idPrefix}_expected_content`}
                    value={t('uptime.expected_content')}
                />
                <TextInput
                    id={`${idPrefix}_expected_content`}
                    name="expected_content"
                    value={data.expected_content ?? ''}
                    disabled={contentDisabled}
                    className="mt-1"
                    onChange={(e) => setData('expected_content', e.target.value)}
                />
                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {t('uptime.expected_content_hint')}
                </p>
                <InputError message={errors.expected_content} className="mt-2" />
            </div>

            <HeaderFields data={data} setData={setData} errors={errors} idPrefix={idPrefix} />

            {acceptsBody && (
                <div className="md:col-span-2">
                    <InputLabel htmlFor={`${idPrefix}_body`} value={t('uptime.body')} />
                    <textarea
                        id={`${idPrefix}_body`}
                        name="body"
                        rows={3}
                        value={data.body ?? ''}
                        className="mt-1 block w-full rounded-md border-gray-300 font-mono text-sm shadow-xs focus:border-blue-500 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                        onChange={(e) => setData('body', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('uptime.body_hint')}
                    </p>
                    <InputError message={errors.body} className="mt-2" />
                </div>
            )}
        </>
    );
}

// Kopfzeilen als Paare. Eine leere Zeile steht immer zum Ausfüllen bereit; der
// Server verwirft sie, wenn sie leer bleibt.
function HeaderFields({ data, setData, errors, idPrefix }) {
    const t = useT();
    const headers = data.headers ?? [];
    const rows = [...headers, { name: '', value: '' }];

    // Geschrieben wird immer die ganze Liste ohne die leeren Zeilen: die letzte
    // Zeile ist nur die Einladung zum Ausfüllen und wird erst dann zu einem
    // Eintrag, wenn jemand etwas hineinschreibt.
    const write = (next) =>
        setData(
            'headers',
            next.filter((row) => (row.name ?? '') !== '' || (row.value ?? '') !== '')
        );

    const change = (index, field, value) =>
        write(rows.map((row, position) => (position === index ? { ...row, [field]: value } : row)));

    const remove = (index) => write(rows.filter((_, position) => position !== index));

    return (
        <div className="md:col-span-2">
            <InputLabel htmlFor={`${idPrefix}_header_0_name`} value={t('uptime.headers')} />

            <div className="mt-1 space-y-2">
                {rows.map((row, index) => (
                    <div key={index} className="flex flex-wrap items-center gap-2">
                        <TextInput
                            id={`${idPrefix}_header_${index}_name`}
                            name={`headers[${index}][name]`}
                            value={row.name ?? ''}
                            placeholder={t('uptime.header_name')}
                            className="w-full sm:w-56"
                            onChange={(e) => change(index, 'name', e.target.value)}
                        />
                        <TextInput
                            id={`${idPrefix}_header_${index}_value`}
                            name={`headers[${index}][value]`}
                            value={row.value ?? ''}
                            placeholder={t('uptime.header_value')}
                            className="w-full grow sm:w-auto"
                            onChange={(e) => change(index, 'value', e.target.value)}
                        />
                        {index < headers.length && (
                            <SecondaryButton type="button" onClick={() => remove(index)}>
                                {t('uptime.header_remove')}
                            </SecondaryButton>
                        )}
                    </div>
                ))}
            </div>

            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {t('uptime.headers_hint')}
            </p>
            <InputError message={errors.headers} className="mt-2" />
        </div>
    );
}

// Takt, Zeitgrenze, Bestätigung und die beiden Schwellen. Ebenfalls in beiden
// Formularen.
function ThresholdFields({ data, setData, errors, idPrefix, minimumIntervalSeconds }) {
    const t = useT();

    const fields = [
        ['interval_seconds', 'uptime.interval', 'uptime.interval_hint', minimumIntervalSeconds],
        ['timeout_seconds', 'uptime.timeout', 'uptime.timeout_hint', 1],
        [
            'confirmation_retries',
            'uptime.confirmation_retries',
            'uptime.confirmation_retries_hint',
            0,
        ],
        [
            'confirmation_delay_seconds',
            'uptime.confirmation_delay',
            'uptime.confirmation_delay_hint',
            0,
        ],
        ['failure_threshold', 'uptime.failure_threshold', 'uptime.failure_threshold_hint', 1],
        ['recovery_threshold', 'uptime.recovery_threshold', 'uptime.recovery_threshold_hint', 1],
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

function ToggleFields({ data, setData, idPrefix }) {
    const t = useT();

    return (
        <div className="space-y-2 md:col-span-2">
            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <Checkbox
                    name="follow_redirects"
                    id={`${idPrefix}_follow_redirects`}
                    checked={data.follow_redirects}
                    onChange={(e) => setData('follow_redirects', e.target.checked)}
                />
                {t('uptime.follow_redirects')}
            </label>

            <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <Checkbox
                    name="verify_tls"
                    id={`${idPrefix}_verify_tls`}
                    checked={data.verify_tls}
                    onChange={(e) => setData('verify_tls', e.target.checked)}
                />
                {t('uptime.verify_tls')}
            </label>

            <p className="text-xs text-gray-500 dark:text-gray-400">
                {t('uptime.verify_tls_hint')}
            </p>
        </div>
    );
}

function MonitorSettings({ monitor, methodOptions, minimumIntervalSeconds }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        name: monitor.name,
        url: monitor.url,
        method: monitor.method,
        headers: monitor.headers,
        body: monitor.body ?? '',
        expected_status_codes: monitor.expectedStatusCodes,
        expected_content: monitor.expectedContent ?? '',
        interval_seconds: monitor.intervalSeconds,
        timeout_seconds: monitor.timeoutSeconds,
        confirmation_retries: monitor.confirmationRetries,
        confirmation_delay_seconds: monitor.confirmationDelaySeconds,
        failure_threshold: monitor.failureThreshold,
        recovery_threshold: monitor.recoveryThreshold,
        follow_redirects: monitor.followRedirects,
        verify_tls: monitor.verifyTls,
        is_active: monitor.isActive,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(monitor.href, { preserveScroll: true });
    };

    return (
        <details className="rounded-md border border-gray-200 dark:border-gray-700">
            <summary className="cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                {t('uptime.settings')}
            </summary>

            <form onSubmit={submit} className="grid grid-cols-1 gap-4 p-3 md:grid-cols-2">
                <div className="md:col-span-2">
                    <InputLabel htmlFor={`uptime_name_${monitor.id}`} value={t('uptime.name')} />
                    <TextInput
                        id={`uptime_name_${monitor.id}`}
                        name="name"
                        value={data.name}
                        required
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('uptime.name_hint')}
                    </p>
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <RequestFields
                    data={data}
                    setData={setData}
                    errors={errors}
                    idPrefix={`uptime_${monitor.id}`}
                    methodOptions={methodOptions}
                />

                <ThresholdFields
                    data={data}
                    setData={setData}
                    errors={errors}
                    idPrefix={`uptime_${monitor.id}`}
                    minimumIntervalSeconds={minimumIntervalSeconds}
                />

                <ToggleFields data={data} setData={setData} idPrefix={`uptime_${monitor.id}`} />

                <div className="md:col-span-2">
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('uptime.save')}
                    </PrimaryButton>
                </div>
            </form>
        </details>
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
                {t(monitor.isActive ? 'uptime.disable' : 'uptime.enable')}
            </SecondaryButton>

            <DangerButton
                type="button"
                disabled={processing}
                onClick={() => destroy(monitor.href, { preserveScroll: true })}
            >
                {t('uptime.delete')}
            </DangerButton>

            <p className="text-xs text-gray-500 dark:text-gray-400">{t('uptime.disable_hint')}</p>
        </div>
    );
}

function CreateMonitor({ project, defaults, methodOptions, minimumIntervalSeconds }) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        url: '',
        method: defaults.method,
        headers: [],
        body: '',
        expected_status_codes: defaults.expectedStatusCodes,
        expected_content: defaults.expectedContent,
        interval_seconds: defaults.intervalSeconds,
        timeout_seconds: defaults.timeoutSeconds,
        confirmation_retries: defaults.confirmationRetries,
        confirmation_delay_seconds: defaults.confirmationDelaySeconds,
        failure_threshold: defaults.failureThreshold,
        recovery_threshold: defaults.recoveryThreshold,
        follow_redirects: defaults.followRedirects,
        verify_tls: defaults.verifyTls,
        is_active: true,
    });

    const submit = (e) => {
        e.preventDefault();
        post(project.uptimeHref, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Card title={t('uptime.create.title')} description={t('uptime.create.description')}>
            <form onSubmit={submit} className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="md:col-span-2">
                    <InputLabel htmlFor="new_uptime_name" value={t('uptime.name')} />
                    <TextInput
                        id="new_uptime_name"
                        name="name"
                        value={data.name}
                        required
                        placeholder="Startseite"
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('uptime.name_hint')}
                    </p>
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <RequestFields
                    data={data}
                    setData={setData}
                    errors={errors}
                    idPrefix="new_uptime"
                    methodOptions={methodOptions}
                />

                <ThresholdFields
                    data={data}
                    setData={setData}
                    errors={errors}
                    idPrefix="new_uptime"
                    minimumIntervalSeconds={minimumIntervalSeconds}
                />

                <ToggleFields data={data} setData={setData} idPrefix="new_uptime" />

                <div className="md:col-span-2">
                    <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <Checkbox
                            name="is_active"
                            checked={data.is_active}
                            onChange={(e) => setData('is_active', e.target.checked)}
                        />
                        {t('uptime.active')}
                    </label>
                </div>

                <div className="md:col-span-2">
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('uptime.create.submit')}
                    </PrimaryButton>
                </div>
            </form>
        </Card>
    );
}
