import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { PlatformIcon } from '../../icons.jsx';
import {
    InputError,
    InputLabel,
    PrimaryButton,
    SelectInput,
    TextInput,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Alle Projekte der aktiven Organisation, daneben das Formular zum Anlegen
// eines weiteren. Ohne Organisation gibt es nichts anzulegen — die Seite
// verweist dann auf die Organisationen.
export default function Index({ organization, permissions, projects, platformOptions }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('projects.index.title')}
                appName={shell.appName}
                help={t('projects.index.help')}
                meta={
                    organization && (
                        <Link
                            href={organization.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {organization.name}
                        </Link>
                    )
                }
            />

            {!organization ? (
                <Card
                    title={t('projects.index.no_organization_title')}
                    description={t('projects.index.no_organization_description')}
                >
                    <Link
                        href="/organisationen"
                        className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                    >
                        {t('projects.index.to_organizations')}
                    </Link>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        {projects.length === 0 && (
                            <Card
                                title={t('projects.index.empty_title')}
                                description={t(
                                    permissions.create
                                        ? 'projects.index.empty_can_create'
                                        : 'projects.index.empty_read_only'
                                )}
                            />
                        )}

                        {projects.map((project) => (
                            <Card key={project.slug}>
                                <div className="flex flex-wrap items-center gap-3">
                                    <PlatformIcon
                                        platform={project.platform}
                                        short={project.platformShort}
                                        label={project.platformLabel}
                                    />

                                    <div className="grow">
                                        <Link
                                            href={project.href}
                                            className="text-base font-semibold text-gray-900 hover:text-rose-600 dark:text-gray-100 dark:hover:text-rose-400"
                                        >
                                            {project.name}
                                        </Link>
                                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            {project.platformLabel} · {project.environment}
                                            {project.teams.length > 0 &&
                                                ` · ${project.teams.join(', ')}`}
                                        </p>
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>

                    {permissions.create && (
                        <CreateProject
                            organization={organization}
                            platformOptions={platformOptions}
                        />
                    )}
                </div>
            )}
        </>
    );
}

function CreateProject({ organization, platformOptions }) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        platform: platformOptions[0]?.value ?? 'other',
    });

    const submit = (e) => {
        e.preventDefault();
        post(`${organization.href}/projekte`, { onSuccess: () => reset() });
    };

    return (
        <Card
            title={t('projects.create.title')}
            description={t('projects.create.description', { organization: organization.name })}
        >
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="project_name" value={t('projects.create.name')} />
                    <TextInput
                        id="project_name"
                        name="name"
                        value={data.name}
                        required
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="project_platform" value={t('projects.create.platform')} />
                    <SelectInput
                        id="project_platform"
                        name="platform"
                        value={data.platform}
                        options={platformOptions}
                        className="mt-1"
                        onChange={(e) => setData('platform', e.target.value)}
                    />
                    <InputError message={errors.platform} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('projects.create.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}
