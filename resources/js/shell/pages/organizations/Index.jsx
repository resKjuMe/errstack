import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import {
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    TextInput,
} from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Alle Organisationen dieses Kontos: aktive markiert, Wechsel per Schaltfläche,
// darunter das Formular zum Anlegen einer weiteren.
export default function Index({ organizations }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead
                title={t('organizations.index.title')}
                appName={shell.appName}
                help={t('organizations.index.help')}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {organizations.length === 0 && (
                        <Card
                            title={t('organizations.index.empty_title')}
                            description={t('organizations.index.empty_description')}
                        />
                    )}

                    {organizations.map((organization) => (
                        <Card key={organization.slug}>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <Link
                                        href={organization.href}
                                        className="text-base font-semibold text-gray-900 hover:text-rose-600 dark:text-gray-100 dark:hover:text-rose-400"
                                    >
                                        {organization.name}
                                    </Link>
                                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        {t('organizations.index.own_role', {
                                            role: organization.roleLabel,
                                        })}
                                    </p>
                                </div>

                                {organization.isCurrent ? (
                                    <span className="rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
                                        {t('organizations.index.current')}
                                    </span>
                                ) : (
                                    <SwitchButton slug={organization.slug} />
                                )}
                            </div>
                        </Card>
                    ))}
                </div>

                <CreateOrganization />
            </div>
        </>
    );
}

function SwitchButton({ slug }) {
    const t = useT();
    const { post, processing } = useForm({});

    return (
        <SecondaryButton
            type="button"
            disabled={processing}
            onClick={() => post(`/organisationen/${slug}/wechseln`, { preserveScroll: true })}
        >
            {t('organizations.index.switch')}
        </SecondaryButton>
    );
}

function CreateOrganization() {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({ name: '' });

    const submit = (e) => {
        e.preventDefault();
        post('/organisationen', { onSuccess: () => reset() });
    };

    return (
        <Card
            title={t('organizations.create.title')}
            description={t('organizations.create.description')}
        >
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel
                        htmlFor="organization_name"
                        value={t('organizations.create.name')}
                    />
                    <TextInput
                        id="organization_name"
                        name="name"
                        value={data.name}
                        required
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('organizations.create.submit')}
                </PrimaryButton>
            </form>
        </Card>
    );
}
