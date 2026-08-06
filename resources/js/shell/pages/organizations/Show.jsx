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

// Detailseite einer Organisation: Stammdaten, Mitglieder samt Rolle, offene
// Einladungen und Teams. Was der Betrachter nicht darf, blendet die Seite aus —
// entschieden wird es serverseitig in den Policies.
export default function Show({
    organization,
    viewer,
    permissions,
    members,
    invitations,
    teams,
    roleOptions,
}) {
    const { shell } = usePage().props;

    return (
        <>
            <PageHead
                title={organization.name}
                appName={shell.appName}
                help="Rollen bestimmen, wer was darf: Besitzer alles, Verwaltung die Organisation samt Mitgliedern und Teams, Mitglied die tägliche Arbeit, Lesend nur schauen."
                meta={
                    <div className="flex items-center gap-3">
                        <span className="text-sm text-gray-500 dark:text-gray-400">
                            Eigene Rolle:{' '}
                            {members.find((member) => member.userId === viewer.id)?.roleLabel}
                        </span>
                        <Link
                            href="/organisationen"
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            Alle Organisationen
                        </Link>
                    </div>
                }
            />

            <div className="space-y-4">
                {permissions.update && <GeneralSettings organization={organization} />}

                <Members
                    members={members}
                    roleOptions={roleOptions}
                    canInvite={permissions.invite}
                />

                {permissions.invite && (
                    <Invitations
                        organization={organization}
                        invitations={invitations}
                        roleOptions={roleOptions}
                    />
                )}

                <Teams
                    organization={organization}
                    teams={teams}
                    canManage={permissions.manageTeams}
                />

                {permissions.delete && <DeleteOrganization organization={organization} />}
            </div>
        </>
    );
}

function GeneralSettings({ organization }) {
    const { data, setData, patch, processing, errors } = useForm({ name: organization.name });

    const submit = (e) => {
        e.preventDefault();
        patch(organization.href, { preserveScroll: true });
    };

    return (
        <Card title="Stammdaten" description="Der Name der Organisation.">
            <form onSubmit={submit} className="max-w-xl space-y-4">
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

                <PrimaryButton type="submit" disabled={processing}>
                    Speichern
                </PrimaryButton>
            </form>
        </Card>
    );
}

function Members({ members, roleOptions, canInvite }) {
    return (
        <Card
            title="Mitglieder"
            description={
                canInvite
                    ? 'Rolle ändern oder Mitglied entfernen.'
                    : 'Wer zu dieser Organisation gehört.'
            }
        >
            <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                {members.map((member) => (
                    <li
                        key={member.id}
                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <div>
                            <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {member.name}
                                {member.isSelf && (
                                    <span className="ms-2 text-xs text-gray-500 dark:text-gray-400">
                                        (das bist du)
                                    </span>
                                )}
                            </p>
                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                {member.email}
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            {member.canUpdateRole ? (
                                <RoleSelect member={member} roleOptions={roleOptions} />
                            ) : (
                                <span className="text-sm text-gray-600 dark:text-gray-400">
                                    {member.roleLabel}
                                </span>
                            )}

                            {member.canRemove && <RemoveMember member={member} />}
                        </div>
                    </li>
                ))}
            </ul>
        </Card>
    );
}

function RoleSelect({ member, roleOptions }) {
    const [role, setRole] = useState(member.role);

    const change = (next) => {
        setRole(next);
        router.patch(`/mitgliedschaften/${member.id}`, { role: next }, { preserveScroll: true });
    };

    return (
        <select
            value={role}
            aria-label={`Rolle von ${member.name}`}
            onChange={(e) => change(e.target.value)}
            className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
        >
            {roleOptions.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

function RemoveMember({ member }) {
    const { delete: destroy, processing } = useForm({});

    return (
        <DangerButton
            type="button"
            disabled={processing}
            onClick={() => destroy(`/mitgliedschaften/${member.id}`, { preserveScroll: true })}
        >
            {member.isSelf ? 'Verlassen' : 'Entfernen'}
        </DangerButton>
    );
}

function Invitations({ organization, invitations, roleOptions }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        role: 'member',
    });

    const submit = (e) => {
        e.preventDefault();
        post(`${organization.href}/einladungen`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Card
            title="Einladungen"
            description="Der Link in der E-Mail führt direkt zum Beitritt — ein Konto kann dabei noch angelegt werden."
        >
            <form onSubmit={submit} className="flex flex-wrap items-start gap-3">
                <div className="grow">
                    <InputLabel htmlFor="invite_email" value="E-Mail-Adresse" />
                    <TextInput
                        id="invite_email"
                        type="email"
                        name="email"
                        value={data.email}
                        required
                        className="mt-1"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="invite_role" value="Rolle" />
                    <select
                        id="invite_role"
                        value={data.role}
                        onChange={(e) => setData('role', e.target.value)}
                        className="mt-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        {roleOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.role} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={processing} className="mt-6">
                    Einladen
                </PrimaryButton>
            </form>

            {invitations.length > 0 && (
                <ul className="mt-4 divide-y divide-gray-200 dark:divide-gray-700">
                    {invitations.map((invitation) => (
                        <li
                            key={invitation.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-3"
                        >
                            <div>
                                <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {invitation.email}
                                </p>
                                <p className="text-sm text-gray-500 dark:text-gray-400">
                                    {invitation.roleLabel} ·{' '}
                                    {invitation.isExpired
                                        ? 'abgelaufen'
                                        : `gültig bis ${invitation.expiresAt}`}
                                </p>
                            </div>

                            <WithdrawInvitation invitation={invitation} />
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

function WithdrawInvitation({ invitation }) {
    const { delete: destroy, processing } = useForm({});

    return (
        <DangerButton
            type="button"
            disabled={processing}
            onClick={() => destroy(`/einladungen/${invitation.id}`, { preserveScroll: true })}
        >
            Zurückziehen
        </DangerButton>
    );
}

function Teams({ organization, teams, canManage }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '' });

    const submit = (e) => {
        e.preventDefault();
        post(`${organization.href}/teams`, { onSuccess: () => reset() });
    };

    return (
        <Card title="Teams" description="Teams bündeln Mitglieder innerhalb der Organisation.">
            {teams.length === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">Noch keine Teams.</p>
            )}

            <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                {teams.map((team) => (
                    <li key={team.id} className="py-3">
                        <Link
                            href={team.href}
                            className="text-sm font-medium text-gray-900 hover:text-rose-600 dark:text-gray-100 dark:hover:text-rose-400"
                        >
                            {team.name}
                        </Link>
                    </li>
                ))}
            </ul>

            {canManage && (
                <form onSubmit={submit} className="mt-4 flex flex-wrap items-start gap-3">
                    <div className="grow">
                        <InputLabel htmlFor="team_name" value="Neues Team" />
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

                    <PrimaryButton type="submit" disabled={processing} className="mt-6">
                        Anlegen
                    </PrimaryButton>
                </form>
            )}
        </Card>
    );
}

function DeleteOrganization({ organization }) {
    const { delete: destroy, processing } = useForm({});

    return (
        <Card
            title="Organisation löschen"
            description="Mit der Organisation verschwinden Mitgliedschaften, Teams und alle daran hängenden Daten — unwiderruflich."
        >
            <DangerButton
                type="button"
                disabled={processing}
                onClick={() => destroy(organization.href)}
            >
                Organisation löschen
            </DangerButton>
        </Card>
    );
}
