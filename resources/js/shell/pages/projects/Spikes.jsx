import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    Checkbox,
    InputError,
    InputLabel,
    PrimaryButton,
    TextInput,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Der Ausschlag-Schutz eines Projekts: ob er greift, woran er gerade misst, was
// er verworfen hat und der Knopf, mit dem sich eine laufende Drosselung
// aufheben lässt.
//
// Die Reihenfolge der Abschnitte ist die Reihenfolge der Fragen, mit denen
// jemand hierherkommt: „wird gerade gedrosselt?" steht ganz oben — wer die
// Seite in einer Flut aufruft, will nicht erst an den Einstellungen
// vorbeiscrollen. Danach der Verlauf, dann die Vorfälle, zuletzt die Schalter.
export default function Spikes({
    project,
    organization,
    detection,
    current,
    history,
    volumes,
    discardedTotal,
    canManage,
    hrefs,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('spikes.title', { project: project.name })}
                appName={shell.appName}
                help={t('spikes.help')}
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
                {current ? (
                    <Current
                        state={current}
                        project={project}
                        canManage={canManage}
                        hrefs={hrefs}
                    />
                ) : (
                    <Idle discardedTotal={discardedTotal} />
                )}

                <Detection project={project} detection={detection} />
                <Volumes volumes={volumes} />
                <History history={history} />

                {canManage ? (
                    <Settings project={project} hrefs={hrefs} />
                ) : (
                    <ReadOnlySettings project={project} />
                )}
            </div>
        </>
    );
}

// Der laufende Vorfall. Der Knopf zum Aufheben steht direkt daneben und nicht
// bei den Einstellungen: das Abschalten des Schutzes gilt für die Zukunft, das
// Aufheben für genau diesen Vorfall.
function Current({ state, project, canManage, hrefs }) {
    const t = useT();
    const { post, processing } = useForm({});

    const release = (e) => {
        e.preventDefault();
        post(hrefs.release, { preserveScroll: true });
    };

    return (
        <Card
            title={t('spikes.current.title')}
            description={t('spikes.current.description', { since: state.startedAt })}
        >
            <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Figure label={t('spikes.current.discarded')} value={state.discarded} highlight />
                <Figure label={t('spikes.current.peak')} value={state.peak} />
                <Figure label={t('spikes.current.threshold')} value={state.threshold} />
            </dl>

            {canManage && (
                <form onSubmit={release} className="mt-4 space-y-2">
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('spikes.current.release')}
                    </PrimaryButton>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {project.releaseMinutes > 0
                            ? t('spikes.current.release_hint', { minutes: project.releaseMinutes })
                            : t('spikes.current.release_hint_none')}
                    </p>
                </form>
            )}
        </Card>
    );
}

function Idle({ discardedTotal }) {
    const t = useT();

    return (
        <Card
            title={t('spikes.idle.title')}
            description={t('spikes.idle.description', { count: discardedTotal })}
        />
    );
}

// Woran gemessen wird. `ready` ist die ehrliche Antwort auf „warum drosselt er
// nicht?" — ohne sie hielte man einen Schutz für kaputt, der bewusst noch
// nicht entscheidet.
function Detection({ project, detection }) {
    const t = useT();

    return (
        <Card title={t('spikes.detection.title')} description={t('spikes.detection.description')}>
            <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Figure
                    label={t('spikes.detection.baseline')}
                    value={t('spikes.detection.baseline_value', { value: detection.baseline })}
                />
                <Figure
                    label={t('spikes.detection.threshold')}
                    value={
                        detection.threshold > 0
                            ? t('spikes.detection.threshold_value', { value: detection.threshold })
                            : t('spikes.detection.threshold_off')
                    }
                />
                <Figure
                    label={t('spikes.detection.samples')}
                    value={t('spikes.detection.samples_value', {
                        samples: detection.samples,
                        required: detection.requiredSamples,
                    })}
                />
            </dl>

            <div className="mt-3 space-y-2 text-xs text-gray-500 dark:text-gray-400">
                {!project.enabled && <p>{t('spikes.detection.disabled')}</p>}
                {project.enabled && !detection.ready && (
                    <p>
                        {t('spikes.detection.not_ready', {
                            samples: detection.samples,
                            required: detection.requiredSamples,
                        })}
                    </p>
                )}
                <p>{t('spikes.intro.counted_hint')}</p>
                <p>{t('spikes.intro.baseline_hint', { minutes: detection.historyMinutes })}</p>
            </div>
        </Card>
    );
}

// Der Verlauf der letzten Stunde als Balken. Bewusst nur so genau, wie die
// Zahlen es hergeben: die Höhe steht zur größten Minute, gedrosselte Minuten
// sind hervorgehoben, und jeder Balken trägt seine Zahl im Titel.
function Volumes({ volumes }) {
    const t = useT();

    if (volumes.length === 0) {
        return (
            <Card title={t('spikes.chart.title')} description={t('spikes.chart.description')}>
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('spikes.chart.empty')}
                </p>
            </Card>
        );
    }

    const peak = Math.max(...volumes.map((entry) => entry.quantity), 1);

    return (
        <Card title={t('spikes.chart.title')} description={t('spikes.chart.description')}>
            <div className="flex h-24 items-end gap-px">
                {volumes.map((entry) => (
                    <div
                        key={entry.minute}
                        title={`${t('spikes.chart.minute', { minute: formatMinute(entry.minute) })} — ${t(
                            'spikes.chart.events',
                            { count: entry.quantity }
                        )}${entry.throttled ? ` (${t('spikes.chart.throttled')})` : ''}`}
                        className={`flex-1 rounded-t ${
                            entry.throttled
                                ? 'bg-amber-500 dark:bg-amber-400'
                                : 'bg-gray-300 dark:bg-gray-600'
                        } ${entry.partial ? 'opacity-60' : ''}`}
                        style={{
                            height: `${Math.max(2, Math.round((entry.quantity / peak) * 100))}%`,
                        }}
                    />
                ))}
            </div>
        </Card>
    );
}

function History({ history }) {
    const t = useT();

    if (history.length === 0) {
        return (
            <Card title={t('spikes.history.title')} description={t('spikes.history.description')}>
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('spikes.history.empty')}
                </p>
            </Card>
        );
    }

    return (
        <Card title={t('spikes.history.title')} description={t('spikes.history.description')}>
            <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                {history.map((state) => (
                    <li
                        key={state.id}
                        className="flex flex-wrap justify-between gap-3 py-2 text-sm"
                    >
                        <div>
                            <p className="text-gray-900 dark:text-gray-100">
                                {state.startedAt} — {state.endedAt}
                            </p>
                            <p className="text-xs text-gray-500 dark:text-gray-400">
                                {state.releasedBy
                                    ? t('spikes.history.released_by', { name: state.releasedBy })
                                    : t('spikes.history.ended_on_its_own')}
                            </p>
                        </div>
                        <div className="text-right text-xs text-gray-500 dark:text-gray-400">
                            <p>
                                {t('spikes.history.discarded')}: {state.discarded}
                            </p>
                            <p>
                                {t('spikes.history.peak')}: {state.peak}
                            </p>
                        </div>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function Settings({ project, hrefs }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        spike_protection_enabled: project.enabled,
        spike_threshold_factor: project.factor,
        spike_minimum_events: project.minimumEvents,
        spike_release_minutes: project.releaseMinutes,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(hrefs.update, { preserveScroll: true });
    };

    return (
        <Card title={t('spikes.settings.title')} description={t('spikes.settings.description')}>
            <form onSubmit={submit} className="space-y-4">
                <label className="flex items-start gap-2">
                    <Checkbox
                        name="spike_protection_enabled"
                        checked={data.spike_protection_enabled}
                        onChange={(e) => setData('spike_protection_enabled', e.target.checked)}
                    />
                    <span>
                        <span className="text-sm text-gray-900 dark:text-gray-100">
                            {t('spikes.settings.enabled')}
                        </span>
                        <span className="block text-xs text-gray-500 dark:text-gray-400">
                            {t('spikes.settings.enabled_hint')}
                        </span>
                    </span>
                </label>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <InputLabel
                            htmlFor="spike_threshold_factor"
                            value={t('spikes.settings.factor')}
                        />
                        <TextInput
                            id="spike_threshold_factor"
                            name="spike_threshold_factor"
                            type="number"
                            step="0.5"
                            min="1.5"
                            max="100"
                            value={data.spike_threshold_factor}
                            required
                            className="mt-1"
                            onChange={(e) => setData('spike_threshold_factor', e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('spikes.settings.factor_hint')}
                        </p>
                        <InputError message={errors.spike_threshold_factor} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="spike_minimum_events"
                            value={t('spikes.settings.minimum')}
                        />
                        <TextInput
                            id="spike_minimum_events"
                            name="spike_minimum_events"
                            type="number"
                            min="10"
                            value={data.spike_minimum_events}
                            required
                            className="mt-1"
                            onChange={(e) => setData('spike_minimum_events', e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('spikes.settings.minimum_hint')}
                        </p>
                        <InputError message={errors.spike_minimum_events} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="spike_release_minutes"
                            value={t('spikes.settings.release_minutes')}
                        />
                        <TextInput
                            id="spike_release_minutes"
                            name="spike_release_minutes"
                            type="number"
                            min="0"
                            max="1440"
                            value={data.spike_release_minutes}
                            required
                            className="mt-1"
                            onChange={(e) => setData('spike_release_minutes', e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('spikes.settings.release_minutes_hint')}
                        </p>
                        <InputError message={errors.spike_release_minutes} className="mt-2" />
                    </div>
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('spikes.settings.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}

function ReadOnlySettings({ project }) {
    const t = useT();

    const rows = [
        [
            t('spikes.settings.enabled'),
            project.enabled ? t('spikes.settings.on') : t('spikes.settings.off'),
        ],
        [t('spikes.settings.factor'), t('spikes.settings.factor_value', { value: project.factor })],
        [
            t('spikes.settings.minimum'),
            t('spikes.settings.events_value', { value: project.minimumEvents }),
        ],
        [
            t('spikes.settings.release_minutes'),
            t('spikes.settings.minutes_value', { value: project.releaseMinutes }),
        ],
    ];

    return (
        <Card
            title={t('spikes.settings.title')}
            description={t('spikes.settings.read_only_description')}
        >
            <dl className="divide-y divide-gray-200 dark:divide-gray-700">
                {rows.map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-3 py-2 text-sm">
                        <dt className="text-gray-500 dark:text-gray-400">{label}</dt>
                        <dd className="text-gray-900 dark:text-gray-100">{value}</dd>
                    </div>
                ))}
            </dl>
        </Card>
    );
}

function Figure({ label, value, highlight = false }) {
    return (
        <div>
            <dt className="text-xs text-gray-500 dark:text-gray-400">{label}</dt>
            <dd
                className={`text-lg ${
                    highlight
                        ? 'text-amber-600 dark:text-amber-400'
                        : 'text-gray-900 dark:text-gray-100'
                }`}
            >
                {value}
            </dd>
        </div>
    );
}

// Nur die Uhrzeit: die Balken stehen ohnehin in der letzten Stunde, ein Datum
// daneben wäre bei sechzig Beschriftungen nur Rauschen.
function formatMinute(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
}
