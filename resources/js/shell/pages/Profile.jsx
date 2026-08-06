import React, { useEffect, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import PageHead from '../components/PageHead.jsx';
import {
    DangerButton,
    InputError,
    InputLabel,
    PrimaryButton,
    SecondaryButton,
    TextInput,
} from '../components/Form.jsx';

// Eigenes Konto verwalten: drei unabhängige Formulare (Stammdaten, Passwort,
// Konto löschen) in je einer Karte — Aufbau wie die Profilseite in Planstack.
// Passwort- und Löschformular haben serverseitig einen eigenen Fehler-Bag, damit
// die gleichnamigen Felder nicht ineinanderlaufen.
const card = 'bg-white p-4 shadow sm:rounded-lg sm:p-8 dark:bg-gray-800';

export default function Profile({ user, status = null }) {
    const { shell } = usePage().props;

    return (
        <>
            <PageHead
                title="Profil"
                appName={shell.appName}
                help="Name, E-Mail-Adresse und Passwort ändern — oder das Konto endgültig löschen."
            />

            <div className="space-y-6">
                <div className={card}>
                    <div className="max-w-xl">
                        <ProfileInformation user={user} status={status} />
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
    if (!show) {
        return null;
    }

    return <p className="text-sm text-gray-600 dark:text-gray-400">Gespeichert.</p>;
}

function ProfileInformation({ user, status }) {
    const { data, setData, patch, processing, errors } = useForm({
        name: user.name,
        email: user.email,
    });

    const submit = (e) => {
        e.preventDefault();
        patch('/profile');
    };

    return (
        <section>
            <SectionHeader title="Stammdaten" hint="Name und E-Mail-Adresse dieses Kontos." />

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="name" value="Name" />
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
                    <InputLabel htmlFor="email" value="E-Mail-Adresse" />
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
                            Diese E-Mail-Adresse ist noch nicht bestätigt. <ResendVerification />
                        </p>
                    )}
                </div>

                <div className="flex items-center gap-4">
                    <PrimaryButton type="submit" disabled={processing}>
                        Speichern
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
    const { post, processing } = useForm({});
    const [sent, setSent] = useState(false);

    if (sent) {
        return (
            <span className="font-medium text-green-600 dark:text-green-400">
                Bestätigungslink verschickt.
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
            Bestätigungslink erneut senden
        </button>
    );
}

function UpdatePassword() {
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
            <SectionHeader
                title="Passwort ändern"
                hint="Ein langes, zufälliges Passwort schützt das Konto am besten."
            />

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="current_password" value="Aktuelles Passwort" />
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
                    <InputLabel htmlFor="new_password" value="Neues Passwort" />
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
                        value="Neues Passwort wiederholen"
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
                        Speichern
                    </PrimaryButton>

                    <Saved show={recentlySuccessful} />
                </div>
            </form>
        </section>
    );
}

function DeleteAccount() {
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
            <SectionHeader
                title="Konto löschen"
                hint="Mit dem Konto verschwinden alle daran hängenden Daten — unwiderruflich."
            />

            <DangerButton type="button" onClick={() => setOpen(true)}>
                Konto löschen
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
                                Konto wirklich löschen?
                            </h2>
                            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Zur Bestätigung bitte das Passwort eingeben. Danach ist das Konto
                                endgültig gelöscht.
                            </p>

                            <div className="mt-6">
                                <InputLabel
                                    htmlFor="delete_password"
                                    value="Passwort"
                                    className="sr-only"
                                />
                                <TextInput
                                    id="delete_password"
                                    ref={passwordRef}
                                    type="password"
                                    name="password"
                                    value={data.password}
                                    autoComplete="current-password"
                                    placeholder="Passwort"
                                    className="mt-1 sm:w-3/4"
                                    onChange={(e) => setData('password', e.target.value)}
                                />
                                <InputError message={errors.password} className="mt-2" />
                            </div>

                            <div className="mt-6 flex justify-end gap-3">
                                <SecondaryButton type="button" onClick={close}>
                                    Abbrechen
                                </SecondaryButton>

                                <DangerButton type="submit" disabled={processing}>
                                    Konto löschen
                                </DangerButton>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </section>
    );
}
