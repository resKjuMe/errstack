import React from 'react';
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
    invitableRoles,
    auditLogHref,
    privacyHref,
    repositoriesHref,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={organization.name}
                appName={shell.appName}
                help={t('organizations.show.help')}
                meta={
                    <div className="flex items-center gap-3">
                        <span className="text-sm text-gray-500 dark:text-gray-400">
                            {t('organizations.show.own_role')}{' '}
                            {members.find((member) => member.userId === viewer.id)?.roleLabel}
                        </span>
                        {permissions.viewAuditLog && (
                            <Link
                                href={auditLogHref}
                                className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                            >
                                {t('organizations.show.audit_log')}
                            </Link>
                        )}
                        <Link
                            href={privacyHref}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {t('organizations.show.privacy')}
                        </Link>
                        <Link
                            href={repositoriesHref}
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {t('organizations.show.repositories')}
                        </Link>
                        <Link
                            href="/organisationen"
                            className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                        >
                            {t('organizations.show.all_organizations')}
                        </Link>
                    </div>
                }
            />

            <div className="space-y-4">
                {permissions.update && <GeneralSettings organization={organization} />}

                <Members members={members} canInvite={permissions.invite} />

                {permissions.invite && (
                    <Invitations
                        organization={organization}
                        invitations={invitations}
                        invitableRoles={invitableRoles}
                    />
                )}

                <Teams
                    organization={organization}
                    teams={teams}
                    canManage={permissions.manageTeams}
                />

                <Notifications organization={organization} />

                {permissions.delete && <DeleteOrganization organization={organization} />}
            </div>
        </>
    );
}

function GeneralSettings({ organization }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({ name: organization.name });

    const submit = (e) => {
        e.preventDefault();
        patch(organization.href, { preserveScroll: true });
    };

    return (
        <Card
            title={t('organizations.settings.title')}
            description={t('organizations.settings.description')}
        >
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <div>
                    <InputLabel htmlFor="name" value={t('organizations.settings.name')} />
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
                    {t('organizations.settings.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}

function Members({ members, canInvite }) {
    const t = useT();

    return (
        <Card
            title={t('organizations.members.title')}
            description={t(
                canInvite
                    ? 'organizations.members.description_manage'
                    : 'organizations.members.description_read'
            )}
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
                                        {t('organizations.members.self')}
                                    </span>
                                )}
                            </p>
                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                {member.email}
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            {member.assignableRoles.length > 0 ? (
                                <RoleSelect
                                    url={`/mitgliedschaften/${member.id}`}
                                    label={t('organizations.members.role_of', {
                                        name: member.name,
                                    })}
                                    role={member.role}
                                    options={member.assignableRoles}
                                />
                            ) : (
                                <span
                                    className="text-sm text-gray-600 dark:text-gray-400"
                                    title={member.roleHint ?? undefined}
                                >
                                    {member.roleLabel}
                                    {member.roleHint && (
                                        <span className="block text-xs text-gray-400 dark:text-gray-500">
                                            {member.roleHint}
                                        </span>
                                    )}
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

// Die Auswahl zeigt immer den Stand vom Server: schlägt der Wechsel fehl,
// springt sie von selbst zurück, statt eine Rolle vorzugaukeln, die gar nicht
// gespeichert wurde.
function RoleSelect({ url, label, role, options }) {
    return (
        <select
            value={role}
            aria-label={label}
            onChange={(e) => router.patch(url, { role: e.target.value }, { preserveScroll: true })}
            className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
        >
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

function RemoveMember({ member }) {
    const t = useT();
    const { delete: destroy, processing } = useForm({});

    return (
        <DangerButton
            type="button"
            disabled={processing}
            onClick={() => destroy(`/mitgliedschaften/${member.id}`, { preserveScroll: true })}
        >
            {t(member.isSelf ? 'organizations.members.leave' : 'organizations.members.remove')}
        </DangerButton>
    );
}

function Invitations({ organization, invitations, invitableRoles }) {
    const t = useT();
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
            title={t('organizations.invitations.title')}
            description={t('organizations.invitations.description')}
        >
            <form onSubmit={submit} className="flex flex-wrap items-start gap-3">
                <div className="grow">
                    <InputLabel
                        htmlFor="invite_email"
                        value={t('organizations.invitations.email')}
                    />
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
                    <InputLabel htmlFor="invite_role" value={t('organizations.invitations.role')} />
                    <select
                        id="invite_role"
                        value={data.role}
                        onChange={(e) => setData('role', e.target.value)}
                        className="mt-1 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        {invitableRoles.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.role} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={processing} className="mt-6">
                    {t('organizations.invitations.submit')}
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
                                    {invitation.isExpired
                                        ? t('organizations.invitations.expired')
                                        : t('organizations.invitations.valid_until', {
                                              date: invitation.expiresAt,
                                          })}
                                </p>
                            </div>

                            <div className="flex items-center gap-3">
                                {invitation.assignableRoles.length > 0 ? (
                                    <RoleSelect
                                        url={`/einladungen/${invitation.id}`}
                                        label={t('organizations.invitations.role_of', {
                                            email: invitation.email,
                                        })}
                                        role={invitation.role}
                                        options={invitation.assignableRoles}
                                    />
                                ) : (
                                    <span className="text-sm text-gray-600 dark:text-gray-400">
                                        {invitation.roleLabel}
                                    </span>
                                )}

                                <WithdrawInvitation invitation={invitation} />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

// Die Benachrichtigungswege haben eine eigene Seite — hier steht nur der Weg
// dorthin, damit die Detailseite nicht zur Sammelstelle wird.
function Notifications({ organization }) {
    const t = useT();

    return (
        <Card
            title={t('organizations.notifications.title')}
            description={t('organizations.notifications.description')}
        >
            <Link
                href={organization.notificationsHref}
                className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
            >
                {t('organizations.notifications.link')}
            </Link>
        </Card>
    );
}

function WithdrawInvitation({ invitation }) {
    const t = useT();
    const { delete: destroy, processing } = useForm({});

    return (
        <DangerButton
            type="button"
            disabled={processing}
            onClick={() => destroy(`/einladungen/${invitation.id}`, { preserveScroll: true })}
        >
            {t('organizations.invitations.withdraw')}
        </DangerButton>
    );
}

function Teams({ organization, teams, canManage }) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({ name: '' });

    const submit = (e) => {
        e.preventDefault();
        post(`${organization.href}/teams`, { onSuccess: () => reset() });
    };

    return (
        <Card
            title={t('organizations.teams.title')}
            description={t('organizations.teams.description')}
        >
            {teams.length === 0 && (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('organizations.teams.empty')}
                </p>
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
                        <InputLabel htmlFor="team_name" value={t('organizations.teams.new')} />
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
                        {t('organizations.teams.submit')}
                    </PrimaryButton>
                </form>
            )}
        </Card>
    );
}

function DeleteOrganization({ organization }) {
    const t = useT();
    const { delete: destroy, processing } = useForm({});

    return (
        <Card
            title={t('organizations.delete.title')}
            description={t('organizations.delete.description')}
        >
            <DangerButton
                type="button"
                disabled={processing}
                onClick={() => destroy(organization.href)}
            >
                {t('organizations.delete.submit')}
            </DangerButton>
        </Card>
    );
}
