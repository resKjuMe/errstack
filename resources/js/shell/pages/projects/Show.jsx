import React from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { PlatformIcon } from '../../icons.jsx';
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

// Einstellungen eines Projekts: Stammdaten und Verhalten, zuständige Teams, der
// Weg zu den Client-Schlüsseln und das Löschen. Was der Betrachter nicht darf,
// blendet die Seite aus — entschieden wird es serverseitig in den Policies.
export default function Show({
    project,
    organization,
    permissions,
    teams,
    environments,
    platformOptions,
    resolutionOptions,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={project.name}
                appName={shell.appName}
                help={t('projects.show.help')}
                meta={
                    <div className="flex items-center gap-3">
                        <PlatformIcon
                            platform={project.platform}
                            short={project.platformShort}
                            label={project.platformLabel}
                        />
                        <Link
                            href={organization.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {organization.name}
                        </Link>
                        <Link
                            href="/projekte"
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {t('projects.show.all_projects')}
                        </Link>
                    </div>
                }
            />

            <div className="space-y-4">
                {permissions.update ? (
                    <Settings
                        project={project}
                        platformOptions={platformOptions}
                        resolutionOptions={resolutionOptions}
                    />
                ) : (
                    <ReadOnlySettings project={project} resolutionOptions={resolutionOptions} />
                )}

                <Teams project={project} teams={teams} canManage={permissions.manageTeams} />

                <Environments environments={environments} canManage={permissions.update} />

                {project.keysHref && <ClientKeys project={project} />}

                <CronMonitors project={project} />

                <Grouping project={project} />

                <Sampling project={project} />
                <PerformanceDetection project={project} />
                <Privacy project={project} />

                <InboundFilters project={project} />

                {permissions.delete && <DeleteProject project={project} />}
            </div>
        </>
    );
}

function Settings({ project, platformOptions, resolutionOptions }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        name: project.name,
        platform: project.platform,
        default_environment: project.defaultEnvironment,
        resolution_behavior: project.resolutionBehavior,
        retention_days: project.retentionDays,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(project.href, { preserveScroll: true });
    };

    return (
        <Card title={t('projects.settings.title')} description={t('projects.settings.description')}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="name" value={t('projects.settings.name')} />
                        <TextInput
                            id="name"
                            name="name"
                            value={data.name}
                            required
                            className="mt-1"
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="platform" value={t('projects.settings.platform')} />
                        <SelectInput
                            id="platform"
                            name="platform"
                            value={data.platform}
                            options={platformOptions}
                            className="mt-1"
                            onChange={(e) => setData('platform', e.target.value)}
                        />
                        <InputError message={errors.platform} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="default_environment"
                            value={t('projects.settings.default_environment')}
                        />
                        <TextInput
                            id="default_environment"
                            name="default_environment"
                            value={data.default_environment}
                            required
                            placeholder="production"
                            className="mt-1"
                            onChange={(e) => setData('default_environment', e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('projects.settings.default_environment_hint')}
                        </p>
                        <InputError message={errors.default_environment} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel
                            htmlFor="retention_days"
                            value={t('projects.settings.retention')}
                        />
                        <TextInput
                            id="retention_days"
                            name="retention_days"
                            type="number"
                            min="1"
                            max="365"
                            value={data.retention_days}
                            required
                            className="mt-1"
                            onChange={(e) => setData('retention_days', e.target.value)}
                        />
                        <InputError message={errors.retention_days} className="mt-2" />
                    </div>

                    <div className="md:col-span-2">
                        <InputLabel
                            htmlFor="resolution_behavior"
                            value={t('projects.settings.resolution')}
                        />
                        <SelectInput
                            id="resolution_behavior"
                            name="resolution_behavior"
                            value={data.resolution_behavior}
                            options={resolutionOptions}
                            className="mt-1"
                            onChange={(e) => setData('resolution_behavior', e.target.value)}
                        />
                        <InputError message={errors.resolution_behavior} className="mt-2" />
                    </div>
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('projects.settings.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}

function ReadOnlySettings({ project, resolutionOptions }) {
    const t = useT();
    const resolution = resolutionOptions.find(
        (option) => option.value === project.resolutionBehavior
    );

    const rows = [
        [t('projects.settings.platform'), project.platformLabel],
        [t('projects.settings.default_environment'), project.defaultEnvironment],
        [t('projects.settings.resolution'), resolution?.label],
        [
            t('projects.settings.retention_label'),
            t('projects.settings.retention_value', { days: project.retentionDays }),
        ],
    ];

    return (
        <Card
            title={t('projects.settings.title')}
            description={t('projects.settings.read_only_description')}
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

function Teams({ project, teams, canManage }) {
    const t = useT();
    const { data, setData, put, processing } = useForm({
        teams: teams.filter((team) => team.assigned).map((team) => team.id),
    });

    const toggle = (id, checked) => {
        setData(
            'teams',
            checked ? [...data.teams, id] : data.teams.filter((current) => current !== id)
        );
    };

    const submit = (e) => {
        e.preventDefault();
        put(`${project.href}/teams`, { preserveScroll: true });
    };

    return (
        <Card title={t('projects.teams.title')} description={t('projects.teams.description')}>
            {teams.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('projects.teams.empty')}
                </p>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                        {teams.map((team) => (
                            <li key={team.id} className="flex items-center gap-3 py-2">
                                <Checkbox
                                    id={`team_${team.id}`}
                                    checked={data.teams.includes(team.id)}
                                    disabled={!canManage}
                                    onChange={(e) => toggle(team.id, e.target.checked)}
                                />
                                <InputLabel htmlFor={`team_${team.id}`} value={team.name} />
                            </li>
                        ))}
                    </ul>

                    {canManage && (
                        <PrimaryButton type="submit" disabled={processing}>
                            {t('projects.teams.submit')}
                        </PrimaryButton>
                    )}
                </form>
            )}
        </Card>
    );
}

// Umgebungen entstehen aus den eingehenden Meldungen — deshalb gibt es hier kein
// Anlegen, nur den Schalter, ob eine Umgebung in der Filterleiste angeboten wird.
// Versteckt heißt nicht gelöscht: die Daten der Umgebung bleiben in den
// Auswertungen enthalten.
function Environments({ environments, canManage }) {
    const t = useT();

    // Kein useForm: der Schalter schickt nur einen Wert und gehört zur jeweiligen
    // Zeile, nicht zu einem Formular über die ganze Liste.
    const toggle = (environment) =>
        router.patch(environment.href, { hidden: !environment.hidden }, { preserveScroll: true });

    return (
        <Card
            title={t('projects.environments.title')}
            description={t('projects.environments.description')}
        >
            {environments.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('projects.environments.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                    {environments.map((environment) => (
                        <li
                            key={environment.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-2"
                        >
                            <div>
                                <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {environment.name}
                                    {environment.hidden && (
                                        <span className="ms-2 text-xs font-normal text-gray-500 dark:text-gray-400">
                                            {t('projects.environments.hidden')}
                                        </span>
                                    )}
                                </p>
                                {environment.lastSeenAt && (
                                    <p className="text-sm text-gray-500 dark:text-gray-400">
                                        {t('projects.environments.last_seen', {
                                            time: environment.lastSeenAt,
                                        })}
                                    </p>
                                )}
                            </div>

                            {canManage && (
                                <SecondaryButton type="button" onClick={() => toggle(environment)}>
                                    {t(
                                        environment.hidden
                                            ? 'projects.environments.show'
                                            : 'projects.environments.hide'
                                    )}
                                </SecondaryButton>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function ClientKeys({ project }) {
    const t = useT();

    return (
        <Card title={t('projects.keys.title')} description={t('projects.keys.description')}>
            <Link href={project.keysHref}>
                <SecondaryButton type="button">{t('projects.keys.manage')}</SecondaryButton>
            </Link>
        </Card>
    );
}

// Weg zur Cronjob-Überwachung. Ohne Bedingung: den Zustand der überwachten
// Jobs darf jedes Mitglied ansehen — er ist der Grund, warum jemand nachschaut.
function CronMonitors({ project }) {
    const t = useT();

    return (
        <Card title={t('projects.crons.title')} description={t('projects.crons.description')}>
            <Link href={project.cronsHref}>
                <SecondaryButton type="button">{t('projects.crons.manage')}</SecondaryButton>
            </Link>
        </Card>
    );
}

// Weg zu den Fingerprint-Regeln. Ebenfalls ohne Bedingung: sie erklären, warum
// gleichartige Meldungen zu einem Eintrag werden — das ist eine Frage der
// Fehlersuche und keine der Verwaltung.
function Grouping({ project }) {
    const t = useT();

    return (
        <Card title={t('projects.grouping.title')} description={t('projects.grouping.description')}>
            <Link href={project.groupingHref}>
                <SecondaryButton type="button">{t('projects.grouping.manage')}</SecondaryButton>
            </Link>
        </Card>
    );
}

// Weg zu den Stichproben-Regeln. Ohne Bedingung wie die Gruppierung: sie
// erklären, warum in der Performance-Übersicht mehr Aufrufe stehen, als
// Messungen gespeichert sind.
function Sampling({ project }) {
    const t = useT();

    return (
        <Card title={t('projects.sampling.title')} description={t('projects.sampling.description')}>
            <Link href={project.samplingHref}>
                <SecondaryButton type="button">{t('projects.sampling.manage')}</SecondaryButton>
            </Link>
        </Card>
    );
}

// Weg zu den Schwellen der Leistungserkennung. Ohne Bedingung aus demselben
// Grund wie die Stichproben: sie erklären, warum ein bekanntes Problem nicht in
// der Liste der Leistungsprobleme steht.
function PerformanceDetection({ project }) {
    const t = useT();

    return (
        <Card
            title={t('projects.performance.title')}
            description={t('projects.performance.description')}
        >
            <Link href={project.performanceHref}>
                <SecondaryButton type="button">{t('projects.performance.manage')}</SecondaryButton>
            </Link>
        </Card>
    );
}

// Weg zu den Datenschutz-Einstellungen. Ebenfalls ohne Bedingung: was von einer
// Meldung übrig bleibt, muss jeder wissen, der mit den Daten arbeitet — ändern
// darf es dort nur die Verwaltung.
function Privacy({ project }) {
    const t = useT();

    return (
        <Card title={t('projects.privacy.title')} description={t('projects.privacy.description')}>
            <Link href={project.privacyHref}>
                <SecondaryButton type="button">{t('projects.privacy.manage')}</SecondaryButton>
            </Link>
        </Card>
    );
}

// Weg zu den Eingangsfiltern. Auch dieser ohne Bedingung, und hier zählt es am
// meisten: eine gefilterte Meldung hinterlässt in der Fehlerliste keine Lücke —
// wer eine vermisst, findet die Antwort nur auf dieser Seite.
function InboundFilters({ project }) {
    const t = useT();

    return (
        <Card title={t('projects.filters.title')} description={t('projects.filters.description')}>
            <Link href={project.filtersHref}>
                <SecondaryButton type="button">{t('projects.filters.manage')}</SecondaryButton>
            </Link>
        </Card>
    );
}

function DeleteProject({ project }) {
    const t = useT();
    const { delete: destroy, processing } = useForm({});

    return (
        <Card title={t('projects.delete.title')} description={t('projects.delete.description')}>
            <DangerButton type="button" disabled={processing} onClick={() => destroy(project.href)}>
                {t('projects.delete.submit')}
            </DangerButton>
        </Card>
    );
}
