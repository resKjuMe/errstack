import React, { useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    DangerButton,
    InputError,
    PrimaryButton,
    SecondaryButton,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Die Anbindung an einen Anbieter (X1).
//
// Die Seite beantwortet drei Fragen in der Reihenfolge, in der sie aufkommen:
// Ist etwas verbunden? Trägt es noch? Welche Repositories versorgt es?
//
// Die zweite ist der Grund, dass es diese Seite gibt und nicht nur einen Knopf
// auf der Repository-Seite. Ein zurückgezogenes Token macht sich sonst nirgends
// bemerkbar — die Commits einer Auslieferung kommen einfach nicht mehr, und das
// sieht aus wie „diese Version hatte keine".
export default function Index({
    organization,
    canManage,
    configured,
    provider,
    integration,
    connectHref,
    repositoriesHref,
    availableRepositoriesHref,
}) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('integrations.title')}
                appName={shell.appName}
                help={t('integrations.help')}
                meta={
                    <Link
                        href={organization.href}
                        className="text-sm text-gray-500 underline hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    >
                        {organization.name}
                    </Link>
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {!configured ? (
                        <Card
                            title={t('integrations.not_configured.title')}
                            description={t('integrations.not_configured.hint')}
                        />
                    ) : integration === null ? (
                        <Card title={provider.label} description={t('integrations.empty')}>
                            {canManage && (
                                // Ein normaler Link und kein Inertia-Besuch: das
                                // Ziel liegt außerhalb dieser Anwendung, und ein
                                // Inertia-Aufruf bekäme von GitHub eine HTML-Seite
                                // zurück, mit der er nichts anfangen kann.
                                <a
                                    href={connectHref}
                                    className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-xs font-semibold tracking-widest text-white uppercase hover:bg-indigo-500"
                                >
                                    {t('integrations.actions.connect')}
                                </a>
                            )}
                        </Card>
                    ) : (
                        <Connection
                            integration={integration}
                            provider={provider}
                            canManage={canManage}
                            connectHref={connectHref}
                            repositoriesHref={repositoriesHref}
                            t={t}
                        />
                    )}
                </div>

                {integration !== null && canManage && (
                    <AddRepository href={availableRepositoriesHref} t={t} />
                )}
            </div>
        </>
    );
}

function Connection({ integration, provider, canManage, connectHref, repositoriesHref, t }) {
    const { delete: destroy, processing } = useForm({});
    const lost = integration.status === 'disconnected';

    return (
        <>
            {/* Der verlorene Zugang steht ganz oben und nicht als Zeile in der
                Aufzählung: er ist der einzige Zustand, in dem jemand handeln
                muss, und alles darunter ist dann ohnehin ohne Wirkung. */}
            {lost && (
                <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-100">
                    <p className="font-medium">{t('integrations.lost.title')}</p>
                    <p className="mt-1">{t('integrations.lost.hint')}</p>
                    {integration.lastError && (
                        <p className="mt-1 font-mono text-xs">{integration.lastError}</p>
                    )}
                    {canManage && (
                        <a
                            href={connectHref}
                            className="mt-3 inline-flex items-center rounded-md bg-amber-600 px-4 py-2 text-xs font-semibold tracking-widest text-white uppercase hover:bg-amber-500"
                        >
                            {t('integrations.actions.reconnect')}
                        </a>
                    )}
                </div>
            )}

            <Card title={provider.label}>
                <dl className="space-y-2 text-sm">
                    <Row label={t('integrations.fields.account')} value={integration.account} />
                    <Row label={t('integrations.fields.status')} value={integration.statusLabel} />
                    <Row
                        label={t('integrations.fields.connected_at')}
                        value={
                            integration.connectedBy
                                ? t('integrations.fields.connected_by', {
                                      at: integration.connectedAtLabel ?? '—',
                                      name: integration.connectedBy,
                                  })
                                : integration.connectedAtLabel
                        }
                    />
                    <Row
                        label={t('integrations.fields.last_synced_at')}
                        value={integration.lastSyncedAtLabel ?? t('integrations.fields.never')}
                    />
                </dl>

                {canManage && (
                    <div className="mt-4">
                        <DangerButton
                            type="button"
                            disabled={processing}
                            onClick={() => {
                                if (window.confirm(t('integrations.actions.disconnect_confirm'))) {
                                    destroy(integration.disconnectHref, {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            {t('integrations.actions.disconnect')}
                        </DangerButton>
                    </div>
                )}
            </Card>

            <Card
                title={t('integrations.repositories.title')}
                description={t('integrations.repositories.hint')}
            >
                {integration.repositories.length === 0 ? (
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        {t('integrations.repositories.empty')}
                    </p>
                ) : (
                    <ul className="divide-y divide-gray-200 text-sm dark:divide-gray-700">
                        {integration.repositories.map((repository) => (
                            <li key={repository.id} className="py-2 first:pt-0 last:pb-0">
                                <span className="font-mono">{repository.name}</span>
                            </li>
                        ))}
                    </ul>
                )}

                <Link
                    href={repositoriesHref}
                    className="mt-3 inline-block text-sm text-indigo-600 underline dark:text-indigo-400"
                >
                    {t('integrations.repositories.manage')}
                </Link>
            </Card>
        </>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex justify-between gap-4">
            <dt className="text-gray-500 dark:text-gray-400">{label}</dt>
            <dd className="text-right text-gray-900 dark:text-gray-100">{value ?? '—'}</dd>
        </div>
    );
}

// Die Auswahlliste wird **auf Anforderung** geholt und nicht mit der Seite
// mitgeliefert: sie ist ein Aufruf über das Netz, und die Seite soll auch dann
// laden, wenn GitHub gerade nicht antwortet.
function AddRepository({ href, t }) {
    const [available, setAvailable] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        external_id: '',
        url: '',
    });

    const load = async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await fetch(href, {
                headers: { Accept: 'application/json' },
            });
            const body = await response.json();

            setAvailable(body.repositories ?? []);
            setError(body.error ?? null);
        } catch (e) {
            setError(t('integrations.repositories.load_failed'));
        } finally {
            setLoading(false);
        }
    };

    const choose = (repository) => {
        setData({
            name: repository.name,
            external_id: repository.external_id,
            url: repository.url,
        });
    };

    const submit = (e) => {
        e.preventDefault();
        post(href, { preserveScroll: true, onSuccess: () => setAvailable(null) });
    };

    return (
        <Card
            title={t('integrations.repositories.add')}
            description={t('integrations.repositories.add_hint')}
        >
            {available === null ? (
                <SecondaryButton type="button" disabled={loading} onClick={load}>
                    {loading
                        ? t('integrations.repositories.loading')
                        : t('integrations.repositories.load')}
                </SecondaryButton>
            ) : (
                <form onSubmit={submit} className="space-y-3">
                    <select
                        value={data.name}
                        onChange={(e) =>
                            choose(available.find((r) => r.name === e.target.value) ?? {})
                        }
                        className="w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <option value="">{t('integrations.repositories.choose')}</option>
                        {available.map((repository) => (
                            <option key={repository.name} value={repository.name}>
                                {repository.name}
                            </option>
                        ))}
                    </select>

                    <InputError message={errors.name} />

                    <PrimaryButton type="submit" disabled={processing || data.name === ''}>
                        {t('integrations.repositories.connect')}
                    </PrimaryButton>
                </form>
            )}

            {error && <p className="mt-3 text-sm text-red-600 dark:text-red-400">{error}</p>}
        </Card>
    );
}
