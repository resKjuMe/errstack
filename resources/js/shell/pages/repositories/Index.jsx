import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
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

// Die verbundenen Repositories einer Organisation: woher der Code kommt.
//
// Verbinden heißt hier eintragen — solange es keine Anbindung gibt (X1/X2),
// holt niemand von selbst Commits ab, sondern eine Bauumgebung übergibt sie
// unter genau dem Namen, der hier steht. Ein Repository taucht deshalb auch von
// selbst auf, sobald eine Übergabe einen unbekannten Namen mitbringt; die Seite
// ist dann der Weg, ihm seine Adresse zu geben.
export default function Index({ organization, repositories, canManage, storeHref }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('repositories.title')}
                appName={shell.appName}
                help={t('repositories.help')}
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
                    {repositories.length === 0 ? (
                        <Card
                            title={t('repositories.list.empty')}
                            description={t('repositories.list.empty_hint')}
                        />
                    ) : (
                        repositories.map((repository) => (
                            <RepositoryCard
                                key={repository.id}
                                repository={repository}
                                canManage={canManage}
                                t={t}
                            />
                        ))
                    )}
                </div>

                {canManage && <ConnectRepository storeHref={storeHref} t={t} />}
            </div>
        </>
    );
}

function RepositoryCard({ repository, canManage, t }) {
    const { delete: destroy, processing } = useForm({});

    return (
        <Card>
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <p className="truncate font-mono font-medium text-gray-900 dark:text-gray-100">
                        {repository.name}
                    </p>

                    <p className="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">
                        {repository.url ? (
                            <a
                                href={repository.url}
                                target="_blank"
                                rel="noreferrer"
                                className="underline hover:text-gray-700 dark:hover:text-gray-200"
                            >
                                {repository.url}
                            </a>
                        ) : (
                            t('repositories.list.no_url')
                        )}
                    </p>

                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {t('repositories.list.commits', { count: repository.commitCountLabel })}
                    </p>
                </div>

                {canManage && (
                    <DangerButton
                        type="button"
                        disabled={processing}
                        onClick={() => {
                            // Ein Repository zu lösen nimmt jeder Auslieferung
                            // ihre Commits — das ist der Fall, in dem eine
                            // Rückfrage nicht Beiwerk ist, sondern die einzige
                            // Stelle, an der es noch jemand bemerkt.
                            if (window.confirm(t('repositories.actions.disconnect_confirm'))) {
                                destroy(repository.deleteHref, { preserveScroll: true });
                            }
                        }}
                    >
                        {t('repositories.actions.disconnect')}
                    </DangerButton>
                )}
            </div>
        </Card>
    );
}

function ConnectRepository({ storeHref, t }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        url: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(storeHref, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <Card title={t('repositories.actions.connect')}>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="repository_name" value={t('repositories.fields.name')} />
                    <TextInput
                        id="repository_name"
                        name="name"
                        value={data.name}
                        required
                        placeholder="acme/webshop"
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('repositories.fields.name_hint')}
                    </p>
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="repository_url" value={t('repositories.fields.url')} />
                    <TextInput
                        id="repository_url"
                        name="url"
                        type="url"
                        value={data.url}
                        placeholder="https://github.com/acme/webshop"
                        className="mt-1"
                        onChange={(e) => setData('url', e.target.value)}
                    />
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {t('repositories.fields.url_hint')}
                    </p>
                    <InputError message={errors.url} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('repositories.actions.connect')}
                </PrimaryButton>
            </form>
        </Card>
    );
}
