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

    return (
        <>
            <PageHead
                title="Zugriffstoken"
                appName={shell.appName}
                help={`Mit einem Token sprechen Skripte, CI-Läufe und Werkzeuge mit der Schnittstelle unter /api/0/ — im Namen der Organisation „${organization.name}“ und nur in den Grenzen der gewählten Geltungsbereiche. Der Wert ersetzt ein Passwort: er gehört in einen Geheimnis-Speicher, nicht ins Repository.`}
                meta={
                    <span className="text-sm text-gray-500 dark:text-gray-400">
                        Organisation: {organization.name}
                    </span>
                }
            />

            <div className="space-y-4">
                {createdToken && <CreatedToken token={createdToken} />}

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        {tokens.length === 0 && (
                            <Card
                                title="Noch kein Token"
                                description="Lege eines an, um die Schnittstelle von außen zu nutzen."
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
    const copy = () => {
        navigator.clipboard?.writeText(token.value);
    };

    return (
        <Card
            className="border-2 border-rose-500 dark:border-rose-500"
            title={`Token „${token.name}“ ist bereit`}
            description="Jetzt kopieren und sicher ablegen — dieser Wert wird nie wieder angezeigt."
        >
            <div className="flex flex-wrap items-center gap-3">
                <code className="grow break-all rounded-md bg-gray-100 px-3 py-2 font-mono text-sm text-gray-900 select-all dark:bg-gray-900 dark:text-gray-100">
                    {token.value}
                </code>
                <PrimaryButton type="button" onClick={copy}>
                    Kopieren
                </PrimaryButton>
            </div>
        </Card>
    );
}

function TokenCard({ token }) {
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
                                Abgelaufen
                            </span>
                        )}
                    </div>

                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Gehört: {token.owner}
                        {token.createdBy && ` · Angelegt von ${token.createdBy}`}
                        {' · '}
                        {token.lastUsedAt
                            ? `Zuletzt benutzt am ${formatDate(token.lastUsedAt)}`
                            : 'Noch nicht benutzt'}
                        {' · '}
                        {token.expiresAt
                            ? `Gültig bis ${formatDate(token.expiresAt)}`
                            : 'Unbefristet'}
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
                        Widerrufen
                    </DangerButton>
                )}
            </div>
        </Card>
    );
}

function CreateToken({ kinds, scopeGroups }) {
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
        <Card title="Neues Token" description="Der Wert wird nur einmal angezeigt.">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="token_name" value="Name" />
                    <TextInput
                        id="token_name"
                        name="name"
                        value={data.name}
                        required
                        placeholder="z. B. Auslieferung aus der CI"
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <fieldset>
                    <legend className="text-sm font-medium text-gray-700 dark:text-gray-300">
                        Art
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
                        Geltungsbereiche
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
                                                    : 'Die eigene Rolle erlaubt diesen Bereich nicht.'
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
                    <InputLabel htmlFor="token_expires" value="Gültigkeit" />
                    <select
                        id="token_expires"
                        name="expires_in_days"
                        value={data.expires_in_days}
                        onChange={(e) => setData('expires_in_days', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                        <option value="">Unbefristet</option>
                        <option value="30">30 Tage</option>
                        <option value="90">90 Tage</option>
                        <option value="365">1 Jahr</option>
                    </select>
                    <InputError message={errors.expires_in_days} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    Token anlegen
                </PrimaryButton>
            </form>
        </Card>
    );
}

function formatDate(value) {
    return new Date(value).toLocaleString('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
