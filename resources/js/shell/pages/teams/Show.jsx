import React, { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    TextInput,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Ein Team der Organisation: umbenennen, Mitglieder zuordnen und herausnehmen.
// Zur Auswahl stehen nur Mitglieder der Organisation.
export default function Show({ team, organization, permissions, members, candidates }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={team.name}
                appName={shell.appName}
                help={t('teams.help')}
                meta={
                    <div className="flex flex-wrap items-center gap-3">
                        <Link
                            href={organization.href}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {organization.name}
                        </Link>
                        {/* Hier wird das Team verwaltet; die Sicht daneben
                            zeigt, was auf es wartet. */}
                        <Link
                            href={team.overviewHref}
                            className="text-sm font-medium text-rose-600 hover:text-rose-500 dark:text-rose-400"
                        >
                            {t('teams.overview')}
                        </Link>
                    </div>
                }
            />

            <div className="space-y-4">
                {permissions.manage && <TeamSettings team={team} />}

                <Card title={t('teams.members.title')} description={t('teams.members.description')}>
                    {members.length === 0 && (
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            {t('teams.members.empty')}
                        </p>
                    )}

                    <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                        {members.map((member) => (
                            <li
                                key={member.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-3"
                            >
                                <div>
                                    <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                        {member.name}
                                    </p>
                                    <p className="text-sm text-gray-500 dark:text-gray-400">
                                        {member.email}
                                    </p>
                                </div>

                                {permissions.manage && (
                                    <DangerButton
                                        type="button"
                                        onClick={() =>
                                            router.delete(
                                                `/einstellungen/teams/${team.id}/mitglieder/${member.id}`,
                                                { preserveScroll: true }
                                            )
                                        }
                                    >
                                        {t('teams.members.remove')}
                                    </DangerButton>
                                )}
                            </li>
                        ))}
                    </ul>

                    {permissions.manage && <AddMember team={team} candidates={candidates} />}
                </Card>

                {permissions.manage && <DeleteTeam team={team} />}
            </div>
        </>
    );
}

function TeamSettings({ team }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({ name: team.name });

    const submit = (e) => {
        e.preventDefault();
        patch(`/einstellungen/teams/${team.id}`, { preserveScroll: true });
    };

    return (
        <Card title={t('teams.settings.title')} description={t('teams.settings.description')}>
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <div>
                    <InputLabel htmlFor="team_name" value={t('teams.settings.name')} />
                    <TextInput
                        id="team_name"
                        name="name"
                        value={data.name}
                        required
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('teams.settings.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}

function AddMember({ team, candidates }) {
    const t = useT();
    const [userId, setUserId] = useState('');

    if (candidates.length === 0) {
        return (
            <p className="mt-4 text-sm text-gray-500 dark:text-gray-400">
                {t('teams.members.all_assigned')}
            </p>
        );
    }

    const submit = (e) => {
        e.preventDefault();

        if (userId) {
            router.post(
                `/einstellungen/teams/${team.id}/mitglieder`,
                { user_id: userId },
                { preserveScroll: true, onSuccess: () => setUserId('') }
            );
        }
    };

    return (
        <form onSubmit={submit} className="mt-4 flex flex-wrap items-end gap-3">
            <div>
                <InputLabel htmlFor="team_member" value={t('teams.members.add')} />
                <select
                    id="team_member"
                    value={userId}
                    onChange={(e) => setUserId(e.target.value)}
                    className="mt-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                >
                    <option value="">{t('teams.members.choose')}</option>
                    {candidates.map((candidate) => (
                        <option key={candidate.id} value={candidate.id}>
                            {candidate.name}
                        </option>
                    ))}
                </select>
            </div>

            <PrimaryButton type="submit" disabled={!userId}>
                {t('teams.members.submit')}
            </PrimaryButton>
        </form>
    );
}

function DeleteTeam({ team }) {
    const t = useT();
    const { delete: destroy, processing } = useForm({});

    return (
        <Card title={t('teams.delete.title')} description={t('teams.delete.description')}>
            <DangerButton
                type="button"
                disabled={processing}
                onClick={() => destroy(`/einstellungen/teams/${team.id}`)}
            >
                {t('teams.delete.submit')}
            </DangerButton>
        </Card>
    );
}
