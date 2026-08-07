import React, { useEffect, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import PageHead from '../components/PageHead.jsx';
import {
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    SelectInput,
    TextInput,
} from '../components/Form.jsx';
import { useT } from '../i18n.js';

// Eigenes Konto verwalten: drei unabhängige Formulare (Stammdaten, Passwort,
// Konto löschen) in je einer Karte — Aufbau wie die Profilseite in Planstack.
// Passwort- und Löschformular haben serverseitig einen eigenen Fehler-Bag, damit
// die gleichnamigen Felder nicht ineinanderlaufen.
const card = 'bg-white p-4 shadow sm:rounded-lg sm:p-8 dark:bg-gray-800';

export default function Profile({ user, localeOptions = [], status = null }) {
    const { shell } = usePage().props;
    const t = useT();

    return (
        <>
            <PageHead title={t('profile.title')} appName={shell.appName} help={t('profile.help')} />

            <div className="space-y-6">
                <div className={card}>
                    <div className="max-w-xl">
                        <ProfileInformation
                            user={user}
                            localeOptions={localeOptions}
                            status={status}
                        />
                    </div>
                </div>
                <div className={card}>
                    <div className="max-w-xl">
                        <UpdatePassword />
                    </div>
                </div>
                <div className={card}>
                    <div className="max-w-xl">
                        <DeleteAccount />
                    </div>
                </div>
            </div>
        </>
    );
}

function SectionHeader({ title, hint }) {
    return (
        <header>
            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">{title}</h2>
            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{hint}</p>
        </header>
    );
}

function Saved({ show }) {
    const t = useT();

    if (!show) {
        return null;
    }

    return <p className="text-sm text-gray-600 dark:text-gray-400">{t('profile.saved')}</p>;
}

function ProfileInformation({ user, localeOptions, status }) {
    const t = useT();
    const { data, setData, patch, processing, errors } = useForm({
        name: user.name,
        email: user.email,
        locale: user.locale ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        patch('/profile');
    };

    return (
        <section>
            <SectionHeader
                title={t('profile.information.title')}
                hint={t('profile.information.hint')}
            />

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="name" value={t('profile.information.name')} />
                    <TextInput
                        id="name"
                        name="name"
                        value={data.name}
                        autoComplete="name"
                        required
                        className="mt-1"
                        onChange={(e) => setData('name', e.target.value)}
                    />
                    <InputError message={errors.name} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="email" value={t('profile.information.email')} />
                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        autoComplete="username"
                        required
                        className="mt-1"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} className="mt-2" />

                    {user.isUnverified && (
                        <p className="mt-2 text-sm text-gray-800 dark:text-gray-100">
                            {t('profile.information.unverified')} <ResendVerification />
                        </p>
                    )}
                </div>

                <div>
                    <InputLabel htmlFor="locale" value={t('profile.information.locale')} />
                    <SelectInput
                        id="locale"
                        name="locale"
                        value={data.locale}
                        options={localeOptions}
                        placeholder={t('profile.information.locale_browser')}
                        className="mt-1"
                        onChange={(e) => setData('locale', e.target.value)}
                    />
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {t('profile.information.locale_hint')}
                    </p>
                    <InputError message={errors.locale} className="mt-2" />
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('profile.information.submit')}
                    </PrimaryButton>

                    <Saved show={status === 'profile-updated'} />
                </div>
            </form>
        </section>
    );
}

// Eigenes kleines Formular, damit der Versand nicht am Stammdaten-Formular hängt
// (dessen Absenden würde sonst die noch nicht gespeicherte Adresse mitschicken).
function ResendVerification() {
    const t = useT();
    const { post, processing } = useForm({});
    const [sent, setSent] = useState(false);

    if (sent) {
        return (
            <span className="font-medium text-green-600 dark:text-green-400">
                {t('profile.information.resent')}
            </span>
        );
    }

    return (
        <button
            type="button"
            disabled={processing}
            onClick={() =>
                post('/email/verification-notification', {
                    preserveScroll: true,
                    onSuccess: () => setSent(true),
                })
            }
            className="text-sm text-gray-600 underline hover:text-gray-900 disabled:opacity-50 dark:text-gray-400 dark:hover:text-gray-100"
        >
            {t('profile.information.resend')}
        </button>
    );
}

function UpdatePassword() {
    const t = useT();
    const currentRef = useRef(null);
    const passwordRef = useRef(null);
    const { data, setData, put, processing, errors, reset, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();

        put('/password', {
            // Der Server legt die Fehler dieses Formulars im Bag „updatePassword"
            // ab; ohne dieselbe Angabe hier kämen sie verschachtelt an und die
            // Feldfehler blieben unsichtbar.
            errorBag: 'updatePassword',
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (received) => {
                // Nach einem Fehlschlag im jeweils betroffenen Feld landen.
                if (received.password) {
                    reset('password', 'password_confirmation');
                    passwordRef.current?.focus();
                }

                if (received.current_password) {
                    reset('current_password');
                    currentRef.current?.focus();
                }
            },
        });
    };

    return (
        <section>
            <SectionHeader title={t('profile.password.title')} hint={t('profile.password.hint')} />

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="current_password" value={t('profile.password.current')} />
                    <TextInput
                        id="current_password"
                        ref={currentRef}
                        type="password"
                        name="current_password"
                        value={data.current_password}
                        autoComplete="current-password"
                        className="mt-1"
                        onChange={(e) => setData('current_password', e.target.value)}
                    />
                    <InputError message={errors.current_password} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="new_password" value={t('profile.password.new')} />
                    <TextInput
                        id="new_password"
                        ref={passwordRef}
                        type="password"
                        name="password"
                        value={data.password}
                        autoComplete="new-password"
                        className="mt-1"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div>
                    <InputLabel
                        htmlFor="new_password_confirmation"
                        value={t('profile.password.confirmation')}
                    />
                    <TextInput
                        id="new_password_confirmation"
                        type="password"
                        name="password_confirmation"
                        value={data.password_confirmation}
                        autoComplete="new-password"
                        className="mt-1"
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    <InputError message={errors.password_confirmation} className="mt-2" />
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('profile.password.submit')}
                    </PrimaryButton>

                    <Saved show={recentlySuccessful} />
                </div>
            </form>
        </section>
    );
}

function DeleteAccount() {
    const t = useT();
    const [open, setOpen] = useState(false);
    const passwordRef = useRef(null);
    const {
        data,
        setData,
        delete: destroy,
        processing,
        errors,
        reset,
        clearErrors,
    } = useForm({ password: '' });

    // Bei einem Validierungsfehler (falsches Passwort) bleibt der Dialog offen.
    useEffect(() => {
        if (errors.password) setOpen(true);
    }, [errors.password]);

    useEffect(() => {
        if (open) passwordRef.current?.focus();
    }, [open]);

    const close = () => {
        setOpen(false);
        clearErrors();
        reset();
    };

    const submit = (e) => {
        e.preventDefault();
        destroy('/profile', { errorBag: 'userDeletion', preserveScroll: true });
    };

    return (
        <section className="space-y-6">
            <SectionHeader title={t('profile.delete.title')} hint={t('profile.delete.hint')} />

            <DangerButton type="button" onClick={() => setOpen(true)}>
                {t('profile.delete.button')}
            </DangerButton>

            {open && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div
                        className="fixed inset-0 bg-gray-500/75 dark:bg-gray-900/75"
                        onClick={close}
                    ></div>

                    <div className="relative w-full max-w-lg rounded-lg bg-white shadow-xl dark:bg-gray-800">
                        <form onSubmit={submit} className="p-6">
                            <h2 className="text-lg font-medium text-gray-900 dark:text-gray-100">
                                {t('profile.delete.dialog_title')}
                            </h2>
                            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                {t('profile.delete.dialog_hint')}
                            </p>

                            <div className="mt-6">
                                <InputLabel
                                    htmlFor="delete_password"
                                    value={t('profile.delete.password')}
                                    className="sr-only"
                                />
                                <TextInput
                                    id="delete_password"
                                    ref={passwordRef}
                                    type="password"
                                    name="password"
                                    value={data.password}
                                    autoComplete="current-password"
                                    placeholder={t('profile.delete.password')}
                                    className="mt-1 sm:w-3/4"
                                    onChange={(e) => setData('password', e.target.value)}
                                />
                                <InputError message={errors.password} className="mt-2" />
                            </div>

                            <div className="mt-6 flex justify-end gap-3">
                                <SecondaryButton type="button" onClick={close}>
                                    {t('profile.delete.cancel')}
                                </SecondaryButton>

                                <DangerButton type="submit" disabled={processing}>
                                    {t('profile.delete.confirm')}
                                </DangerButton>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </section>
    );
}
