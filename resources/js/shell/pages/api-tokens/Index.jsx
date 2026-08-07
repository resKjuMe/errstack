import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    Checkbox,
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    TextInput,
} from '../../components/Form.jsx';
import { formatDateTime, useT, useTranslations } from '../../i18n.js';

// Zugriffstoken der aktiven Organisation: Liste, Anlegen, Widerrufen.
// Der Wert eines Tokens ist genau einmal zu sehen — direkt nach dem Anlegen
// (createdToken). Danach ist er nicht mehr zu beschaffen, weil serverseitig nur
// sein Abdruck liegt.
export default function Index({
    organization,
    permissions,
    tokens,
    kinds,
    scopeGroups,
    createdToken = null,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('api_tokens.title')}
                appName={shell.appName}
                help={t('api_tokens.help', { organization: organization.name })}
                meta={
                    <span className="text-sm text-gray-500 dark:text-gray-400">
                        {t('api_tokens.organization', { name: organization.name })}
                    </span>
                }
            />

            <div className="space-y-4">
                {createdToken && <CreatedToken token={createdToken} />}

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        {tokens.length === 0 && (
                            <Card
                                title={t('api_tokens.empty_title')}
                                description={t('api_tokens.empty_description')}
                            />
                        )}

                        {tokens.map((token) => (
                            <TokenCard key={token.id} token={token} />
                        ))}
                    </div>

                    {permissions.create && <CreateToken kinds={kinds} scopeGroups={scopeGroups} />}
                </div>
            </div>
        </>
    );
}

// Einmalige Anzeige des Klartext-Werts. Bewusst auffällig und mit dem Hinweis,
// dass es keine zweite Gelegenheit gibt.
function CreatedToken({ token }) {
    const t = useT();

    const copy = () => {
        navigator.clipboard?.writeText(token.value);
    };

    return (
        <Card
            className="border-2 border-rose-500 dark:border-rose-500"
            title={t('api_tokens.created.title', { name: token.name })}
            description={t('api_tokens.created.description')}
        >
            <div className="flex flex-wrap items-center gap-3">
                <code className="grow break-all rounded-md bg-gray-100 px-3 py-2 font-mono text-sm text-gray-900 select-all dark:bg-gray-900 dark:text-gray-100">
                    {token.value}
                </code>
                <PrimaryButton type="button" onClick={copy}>
                    {t('api_tokens.created.copy')}
                </PrimaryButton>
            </div>
        </Card>
    );
}

function TokenCard({ token }) {
    const { t, formats } = useTranslations();
    const { delete: destroy, processing } = useForm({});

    return (
        <Card>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-base font-semibold text-gray-900 dark:text-gray-100">
                            {token.name}
                        </span>
                        <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            {token.kindLabel}
                        </span>
                        {token.isExpired && (
                            <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">
                                {t('api_tokens.card.expired')}
                            </span>
                        )}
                    </div>

                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {t('api_tokens.card.owner', { owner: token.owner })}
                        {token.createdBy &&
                            ` · ${t('api_tokens.card.created_by', { name: token.createdBy })}`}
                        {' · '}
                        {token.lastUsedAt
                            ? t('api_tokens.card.last_used', {
                                  date: formatDateTime(token.lastUsedAt, formats),
                              })
                            : t('api_tokens.card.never_used')}
                        {' · '}
                        {token.expiresAt
                            ? t('api_tokens.card.valid_until', {
                                  date: formatDateTime(token.expiresAt, formats),
                              })
                            : t('api_tokens.card.unlimited')}
                    </p>

                    <div className="mt-2 flex flex-wrap gap-1">
                        {token.scopes.map((scope) => (
                            <span
                                key={scope.value}
                                title={scope.label}
                                className="rounded bg-indigo-50 px-2 py-0.5 font-mono text-xs text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"
                            >
                                {scope.value}
                            </span>
                        ))}
                    </div>
                </div>

                {token.canRevoke && (
                    <DangerButton
                        type="button"
                        disabled={processing}
                        onClick={() =>
                            destroy(`/zugriffstoken/${token.id}`, { preserveScroll: true })
                        }
                    >
                        {t('api_tokens.card.revoke')}
                    </DangerButton>
                )}
            </div>
        </Card>
    );
}

function CreateToken({ kinds, scopeGroups }) {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        kind: 'personal',
        scopes: [],
        expires_in_days: '',
    });

    const toggleScope = (value) => {
        setData(
            'scopes',
            data.scopes.includes(value)
                ? data.scopes.filter((scope) => scope !== value)
                : [...data.scopes, value]
        );
    };

    const submit = (e) => {
        e.preventDefault();
        post('/zugriffstoken', { onSuccess: () => reset() });
    };

    return (
        <Card title={t('api_tokens.create.title')} description={t('api_tokens.create.description')}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="token_name" value={t('api_tokens.create.name')} />
                    <TextInput
                        id="token_name"
                        name="name"
                        value={data.name}
                        required
                        placeholder={t('api_tokens.create.name_placeholder')}
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <fieldset>
                    <legend className="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {t('api_tokens.create.kind')}
                    </legend>
                    <div className="mt-2 space-y-2">
                        {kinds.map((kind) => (
                            <label
                                key={kind.value}
                                className={`flex gap-2 text-sm ${kind.allowed ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 dark:text-gray-600'}`}
                            >
                                <input
                                    type="radio"
                                    name="kind"
                                    value={kind.value}
                                    checked={data.kind === kind.value}
                                    disabled={!kind.allowed}
                                    onChange={(e) => setData('kind', e.target.value)}
                                    className="mt-1 border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900"
                                />
                                <span>
                                    <span className="font-medium">{kind.label}</span>
                                    <span className="block text-xs">{kind.description}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <InputError message={errors.kind} className="mt-2" />
                </fieldset>

                <fieldset>
                    <legend className="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {t('api_tokens.create.scopes')}
                    </legend>
                    <div className="mt-2 space-y-3">
                        {scopeGroups.map((group) => (
                            <div key={group.label}>
                                <p className="text-xs font-semibold text-gray-500 uppercase dark:text-gray-400">
                                    {group.label}
                                </p>
                                <div className="mt-1 space-y-1">
                                    {group.scopes.map((scope) => (
                                        <label
                                            key={scope.value}
                                            title={
                                                scope.allowed
                                                    ? scope.label
                                                    : t('api_tokens.create.scope_forbidden')
                                            }
                                            className={`flex items-center gap-2 text-sm ${scope.allowed ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400 dark:text-gray-600'}`}
                                        >
                                            <Checkbox
                                                name="scopes[]"
                                                value={scope.value}
                                                checked={data.scopes.includes(scope.value)}
                                                disabled={!scope.allowed}
                                                onChange={() => toggleScope(scope.value)}
                                            />
                                            <span className="font-mono text-xs">{scope.value}</span>
                                            <span>{scope.label}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                    <InputError message={errors.scopes} className="mt-2" />
                </fieldset>

                <div>
                    <InputLabel htmlFor="token_expires" value={t('api_tokens.create.expires')} />
                    <select
                        id="token_expires"
                        name="expires_in_days"
                        value={data.expires_in_days}
                        onChange={(e) => setData('expires_in_days', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        <option value="">{t('api_tokens.create.expires_never')}</option>
                        <option value="30">{t('api_tokens.create.expires_30')}</option>
                        <option value="90">{t('api_tokens.create.expires_90')}</option>
                        <option value="365">{t('api_tokens.create.expires_365')}</option>
                    </select>
                    <InputError message={errors.expires_in_days} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('api_tokens.create.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}
