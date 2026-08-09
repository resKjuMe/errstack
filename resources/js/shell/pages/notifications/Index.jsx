import React, { useMemo, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    Checkbox,
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    TextInput,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Benachrichtigungswege einer Organisation: einrichten, testen und im
// Zustellprotokoll nachsehen, ob es angekommen ist. Welche Kanäle es gibt und
// welche Felder sie brauchen, liefert der Server (`catalog`) — diese Seite
// kennt keinen einzigen Kanal namentlich.
export default function Index({
    organization,
    permissions,
    channels,
    catalog,
    deliveries,
    webhookDocs,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('notifications.channels.title')}
                appName={shell.appName}
                help={t('notifications.channels.help', { docs: webhookDocs })}
                meta={
                    <Link
                        href={organization.href}
                        className="text-sm text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                    >
                        {organization.name}
                    </Link>
                }
            />

            <div className="space-y-4">
                {permissions.manage && <NewChannel organization={organization} catalog={catalog} />}

                <Channels channels={channels} canManage={permissions.manage} />

                <Deliveries deliveries={deliveries} canManage={permissions.manage} />
            </div>
        </>
    );
}

// Formular für einen neuen Kanal. Die Feldliste wechselt mit der Auswahl.
function NewChannel({ organization, catalog }) {
    const t = useT();
    const [type, setType] = useState(catalog[0]?.type ?? '');
    const driver = catalog.find((entry) => entry.type === type);

    const { data, setData, post, processing, errors, reset } = useForm({
        type,
        name: '',
        is_active: true,
        config: {},
    });

    const chooseType = (next) => {
        setType(next);
        // Die Felder des vorigen Kanals passen nicht zum neuen — sie würden
        // sonst als unbekannte Werte mitgeschickt.
        setData({ type: next, name: data.name, is_active: true, config: {} });
    };

    const submit = (e) => {
        e.preventDefault();
        post(`/einstellungen/organisationen/${organization.slug}/benachrichtigungen`, {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Card
            title={t('notifications.channels.new_title')}
            description={t('notifications.channels.new_description')}
        >
            <form onSubmit={submit} className="max-w-xl space-y-4">
                <div>
                    <InputLabel htmlFor="type" value={t('notifications.channels.channel')} />
                    <select
                        id="type"
                        value={type}
                        onChange={(e) => chooseType(e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        {catalog.map((entry) => (
                            <option key={entry.type} value={entry.type}>
                                {entry.label}
                            </option>
                        ))}
                    </select>
                    {driver && (
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {driver.description}
                        </p>
                    )}
                    <InputError message={errors.type} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="name" value={t('notifications.channels.name')} />
                    <TextInput
                        id="name"
                        value={data.name}
                        required
                        className="mt-1"
                        placeholder={t('notifications.channels.name_placeholder')}
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <ConfigFields
                    idPrefix="new"
                    fields={driver?.fields ?? []}
                    values={data.config}
                    errors={errors}
                    onChange={(key, value) => setData('config', { ...data.config, [key]: value })}
                />

                <PrimaryButton type="submit" disabled={processing}>
                    {t('notifications.channels.create')}
                </PrimaryButton>
            </form>
        </Card>
    );
}

// Eingabefelder eines Kanals, gebaut aus der Feldbeschreibung des Servers.
function ConfigFields({ idPrefix, fields, values, errors, onChange }) {
    return fields.map((field) => {
        const id = `${idPrefix}-${field.key}`;
        const value = values[field.key] ?? '';
        const error = errors[`config.${field.key}`] ?? errors[`config.${field.key}.0`];

        return (
            <div key={field.key}>
                <InputLabel htmlFor={id} value={field.label} />
                {field.type === 'list' ? (
                    <textarea
                        id={id}
                        rows={3}
                        value={value}
                        placeholder={field.placeholder ?? ''}
                        onChange={(e) => onChange(field.key, e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500"
                    />
                ) : (
                    <TextInput
                        id={id}
                        type={field.type === 'password' ? 'password' : 'text'}
                        value={value}
                        autoComplete={field.secret ? 'new-password' : 'off'}
                        placeholder={field.placeholder ?? ''}
                        className="mt-1"
                        onChange={(e) => onChange(field.key, e.target.value)}
                    />
                )}
                {field.hint && (
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{field.hint}</p>
                )}
                <InputError message={error} className="mt-2" />
            </div>
        );
    });
}

function Channels({ channels, canManage }) {
    const t = useT();

    if (channels.length === 0) {
        return (
            <Card title={t('notifications.channels.list_title')}>
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('notifications.channels.empty')}
                </p>
            </Card>
        );
    }

    return (
        <Card
            title={t('notifications.channels.list_title')}
            description={t('notifications.channels.list_description')}
        >
            <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                {channels.map((channel) => (
                    <ChannelRow key={channel.id} channel={channel} canManage={canManage} />
                ))}
            </ul>
        </Card>
    );
}

function ChannelRow({ channel, canManage }) {
    const t = useT();
    const [editing, setEditing] = useState(false);

    return (
        <li className="py-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {channel.name}
                        {!channel.isActive && (
                            <span className="ms-2 text-xs text-gray-500 dark:text-gray-400">
                                {t('notifications.channels.inactive')}
                            </span>
                        )}
                    </p>
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        {channel.typeLabel} · {channel.summary}
                    </p>
                </div>

                {canManage && (
                    <div className="flex items-center gap-2">
                        <SecondaryButton
                            type="button"
                            onClick={() =>
                                router.post(channel.testHref, {}, { preserveScroll: true })
                            }
                            disabled={!channel.known || !channel.isActive}
                        >
                            {t('notifications.channels.test')}
                        </SecondaryButton>
                        <SecondaryButton
                            type="button"
                            onClick={() => setEditing((open) => !open)}
                            disabled={!channel.known}
                        >
                            {t(
                                editing
                                    ? 'notifications.channels.close'
                                    : 'notifications.channels.edit'
                            )}
                        </SecondaryButton>
                        <DangerButton
                            type="button"
                            onClick={() => router.delete(channel.href, { preserveScroll: true })}
                        >
                            {t('notifications.channels.delete')}
                        </DangerButton>
                    </div>
                )}
            </div>

            {editing && <ChannelForm channel={channel} onDone={() => setEditing(false)} />}
        </li>
    );
}

function ChannelForm({ channel, onDone }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        name: channel.name,
        is_active: channel.isActive,
        config: { ...channel.values },
    });

    const submit = (e) => {
        e.preventDefault();
        patch(channel.href, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mt-4 max-w-xl space-y-4">
            <div>
                <InputLabel
                    htmlFor={`name-${channel.id}`}
                    value={t('notifications.channels.name')}
                />
                <TextInput
                    id={`name-${channel.id}`}
                    value={data.name}
                    required
                    className="mt-1"
                    onChange={(e) => setData('name', e.target.value)}
                />
                <InputError message={errors.name} className="mt-2" />
            </div>

            <ConfigFields
                idPrefix={`channel-${channel.id}`}
                fields={channel.fields}
                values={data.config}
                errors={errors}
                onChange={(key, value) => setData('config', { ...data.config, [key]: value })}
            />

            <p className="text-sm text-gray-500 dark:text-gray-400">
                {t('notifications.channels.secrets_hint')}
            </p>

            <label className="flex items-center gap-2">
                <Checkbox
                    checked={data.is_active}
                    onChange={(e) => setData('is_active', e.target.checked)}
                />
                <span className="text-sm text-gray-700 dark:text-gray-300">
                    {t('notifications.channels.active')}
                </span>
            </label>

            <PrimaryButton type="submit" disabled={processing}>
                {t('notifications.channels.save')}
            </PrimaryButton>
        </form>
    );
}

const statusClasses = {
    sent: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
};

function Deliveries({ deliveries, canManage }) {
    const t = useT();
    const rows = useMemo(() => deliveries ?? [], [deliveries]);

    return (
        <Card
            title={t('notifications.deliveries.title')}
            description={t('notifications.deliveries.description')}
        >
            {rows.length === 0 ? (
                <p className="text-sm text-gray-500 dark:text-gray-400">
                    {t('notifications.deliveries.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-gray-200 dark:divide-gray-700">
                    {rows.map((delivery) => (
                        <li
                            key={delivery.id}
                            className="flex flex-wrap items-start justify-between gap-3 py-3"
                        >
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {delivery.subject}
                                    {delivery.isTest && (
                                        <span className="ms-2 text-xs text-gray-500 dark:text-gray-400">
                                            {t('notifications.deliveries.test')}
                                        </span>
                                    )}
                                </p>
                                <p className="text-sm text-gray-500 dark:text-gray-400">
                                    {delivery.channel} · {delivery.createdAt} ·{' '}
                                    {delivery.attempts === 1
                                        ? t('notifications.deliveries.attempt')
                                        : t('notifications.deliveries.attempts', {
                                              count: delivery.attempts,
                                          })}
                                    {delivery.responseCode
                                        ? ` · HTTP ${delivery.responseCode}`
                                        : ''}
                                </p>
                                {delivery.error && (
                                    <p className="mt-1 break-words text-sm text-red-600 dark:text-red-400">
                                        {delivery.error}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-center gap-3">
                                <span
                                    className={`rounded-full px-2 py-1 text-xs font-medium ${statusClasses[delivery.status] ?? ''}`}
                                >
                                    {delivery.statusLabel}
                                </span>
                                {canManage && delivery.status === 'failed' && (
                                    <SecondaryButton
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                delivery.retryHref,
                                                {},
                                                { preserveScroll: true }
                                            )
                                        }
                                    >
                                        {t('notifications.deliveries.retry')}
                                    </SecondaryButton>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
