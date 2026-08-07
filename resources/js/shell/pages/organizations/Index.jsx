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

// Alle Organisationen dieses Kontos: aktive markiert, Wechsel per Schaltfläche,
// darunter das Formular zum Anlegen einer weiteren.
export default function Index({ organizations }) {
    const { shell } = usePage().props;

    return (
        <>
            <PageHead
                title="Organisationen"
                appName={shell.appName}
                help="Eine Organisation ist die Klammer um alles Weitere: Projekte, Fehlermeldungen und Alarme gehören immer genau einer. Wer eingeladen wird, sieht nur deren Daten."
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    {organizations.length === 0 && (
                        <Card
                            title="Noch keine Organisation"
                            description="Lege eine an, um loszulegen — oder warte auf eine Einladung per E-Mail."
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
                                        Eigene Rolle: {organization.roleLabel}
                                    </p>
                                </div>

                                {organization.isCurrent ? (
                                    <span className="rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
                                        Aktiv
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
    const { post, processing } = useForm({});

    return (
        <SecondaryButton
            type="button"
            disabled={processing}
            onClick={() => post(`/organisationen/${slug}/wechseln`, { preserveScroll: true })}
        >
            Aktiv setzen
        </SecondaryButton>
    );
}

function CreateOrganization() {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '' });

    const submit = (e) => {
        e.preventDefault();
        post('/organisationen', { onSuccess: () => reset() });
    };

    return (
        <Card title="Neue Organisation" description="Wer sie anlegt, wird ihr Besitzer.">
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <InputLabel htmlFor="organization_name" value="Name" />
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
                    Anlegen
                </PrimaryButton>
            </form>
        </Card>
    );
}
