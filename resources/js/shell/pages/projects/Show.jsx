import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
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

// Einstellungen eines Projekts: Stammdaten und Verhalten, zuständige Teams, der
// Weg zu den Client-Schlüsseln und das Löschen. Was der Betrachter nicht darf,
// blendet die Seite aus — entschieden wird es serverseitig in den Policies.
export default function Show({
    project,
    organization,
    permissions,
    teams,
    platformOptions,
    resolutionOptions,
}) {
    const { shell } = usePage().props;

    return (
        <>
            <PageHead
                title={project.name}
                appName={shell.appName}
                help="Die Einstellungen wirken auf alles, was für dieses Projekt aufgenommen wird: die Umgebung ist der Standard für Meldungen ohne eigene Angabe, das Auflösungs-Verhalten schließt ruhige Issues von selbst, und die Aufbewahrung bestimmt, wie lange Ereignisse erhalten bleiben."
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
                            Alle Projekte
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

                {project.keysHref && <ClientKeys project={project} />}

                {permissions.delete && <DeleteProject project={project} />}
            </div>
        </>
    );
}

function Settings({ project, platformOptions, resolutionOptions }) {
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
        <Card
            title="Einstellungen"
            description="Der Slug in der Adresszeile bleibt beim Umbenennen unverändert, damit verteilte Links gültig bleiben."
        >
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="name" value="Name" />
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
                        <InputLabel htmlFor="platform" value="Plattform" />
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
                        <InputLabel htmlFor="default_environment" value="Standard-Umgebung" />
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
                            Gilt für Meldungen, die keine eigene Umgebung mitschicken.
                        </p>
                        <InputError message={errors.default_environment} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="retention_days" value="Datenaufbewahrung (Tage)" />
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
                        <InputLabel htmlFor="resolution_behavior" value="Auflösungs-Verhalten" />
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
                    Speichern
                </PrimaryButton>
            </form>
        </Card>
    );
}

function ReadOnlySettings({ project, resolutionOptions }) {
    const resolution = resolutionOptions.find(
        (option) => option.value === project.resolutionBehavior
    );

    const rows = [
        ['Plattform', project.platformLabel],
        ['Standard-Umgebung', project.defaultEnvironment],
        ['Auflösungs-Verhalten', resolution?.label],
        ['Datenaufbewahrung', `${project.retentionDays} Tage`],
    ];

    return (
        <Card title="Einstellungen" description="Ändern darf sie die Verwaltung der Organisation.">
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
        <Card
            title="Zuständige Teams"
            description="Ohne Zuordnung ist das Projekt Sache der ganzen Organisation."
        >
            {teams.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    Diese Organisation hat noch keine Teams.
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
                            Speichern
                        </PrimaryButton>
                    )}
                </form>
            )}
        </Card>
    );
}

function ClientKeys({ project }) {
    return (
        <Card
            title="Client-Schlüssel"
            description="Die DSN ist die Adresse, an die das SDK seine Meldungen schickt. Sie steht mit allen Schlüsseln dieses Projekts auf einer eigenen Seite."
        >
            <Link href={project.keysHref}>
                <SecondaryButton type="button">Client-Schlüssel verwalten</SecondaryButton>
            </Link>
        </Card>
    );
}

function DeleteProject({ project }) {
    const { delete: destroy, processing } = useForm({});

    return (
        <Card
            title="Projekt löschen"
            description="Mit dem Projekt verschwinden seine Einstellungen, die Team-Zuordnung und alle daran hängenden Daten — unwiderruflich."
        >
            <DangerButton type="button" disabled={processing} onClick={() => destroy(project.href)}>
                Projekt löschen
            </DangerButton>
        </Card>
    );
}
