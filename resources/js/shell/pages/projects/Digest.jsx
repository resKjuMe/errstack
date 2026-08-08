import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { InputError, InputLabel, PrimaryButton, TextInput } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Die Bündelung der Benachrichtigungen eines Projekts: mehrere Auslösungen
// innerhalb eines Zeitfensters werden zu einer Nachricht zusammengefasst.
// Ansehen darf jedes Mitglied — die Einstellung erklärt, warum eine Meldung
// erst mit Verzögerung kam. Ändern darf nur die Verwaltung; entschieden wird
// das serverseitig, `canManage` blendet hier lediglich aus, was ohnehin
// abgewiesen würde.
export default function Digest({ project, organization, waiting, canManage, hrefs }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('digests.title', { project: project.name })}
                appName={shell.appName}
                help={t('digests.help')}
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
                <Card title={t('digests.intro.title')} description={t('digests.intro.description')}>
                    <div className="space-y-2 text-xs text-gray-500 dark:text-gray-400">
                        <p>{t('digests.intro.critical_hint')}</p>
                        <p>
                            {t('digests.intro.personal_hint')}{' '}
                            <Link
                                href={hrefs.preferences}
                                className="underline hover:text-gray-900 dark:hover:text-gray-100"
                            >
                                {t('digests.intro.personal_link')}
                            </Link>
                        </p>
                        <p>{t('digests.intro.waiting', { count: waiting })}</p>
                    </div>
                </Card>

                {canManage ? (
                    <Settings project={project} hrefs={hrefs} />
                ) : (
                    <ReadOnlySettings project={project} />
                )}
            </div>
        </>
    );
}

function Settings({ project, hrefs }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        digest_window_minutes: project.windowMinutes,
        digest_min_events: project.minEvents,
        digest_max_events: project.maxEvents,
    });

    const submit = (e) => {
        e.preventDefault();
        patch(hrefs.update, { preserveScroll: true });
    };

    return (
        <Card title={t('digests.settings.title')} description={t('digests.settings.description')}>
            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div>
                        <InputLabel
                            htmlFor="digest_window_minutes"
                            value={t('digests.settings.window')}
                        />
                        <TextInput
                            id="digest_window_minutes"
                            name="digest_window_minutes"
                            type="number"
                            min="0"
                            max="1440"
                            value={data.digest_window_minutes}
                            required
                            className="mt-1"
                            onChange={(e) => setData('digest_window_minutes', e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('digests.settings.window_hint')}
                        </p>
                        <InputError message={errors.digest_window_minutes} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="digest_min_events" value={t('digests.settings.min')} />
                        <TextInput
                            id="digest_min_events"
                            name="digest_min_events"
                            type="number"
                            min="2"
                            max="100"
                            value={data.digest_min_events}
                            required
                            className="mt-1"
                            onChange={(e) => setData('digest_min_events', e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('digests.settings.min_hint')}
                        </p>
                        <InputError message={errors.digest_min_events} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="digest_max_events" value={t('digests.settings.max')} />
                        <TextInput
                            id="digest_max_events"
                            name="digest_max_events"
                            type="number"
                            min="2"
                            max="200"
                            value={data.digest_max_events}
                            required
                            className="mt-1"
                            onChange={(e) => setData('digest_max_events', e.target.value)}
                        />
                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {t('digests.settings.max_hint')}
                        </p>
                        <InputError message={errors.digest_max_events} className="mt-2" />
                    </div>
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('digests.settings.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}

function ReadOnlySettings({ project }) {
    const t = useT();

    const rows = [
        [
            t('digests.settings.window'),
            project.windowMinutes === 0
                ? t('digests.settings.window_off')
                : t('digests.settings.window_value', { minutes: project.windowMinutes }),
        ],
        [t('digests.settings.min'), project.minEvents],
        [t('digests.settings.max'), project.maxEvents],
    ];

    return (
        <Card
            title={t('digests.settings.title')}
            description={t('digests.settings.read_only_description')}
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
