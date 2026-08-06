import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import GuestShell from '../../GuestShell.jsx';
import { PrimaryButton, formLinkClass } from '../../components/Form.jsx';

export default function VerifyEmail({ status = null }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    return (
        <>
            <div className="mb-4 text-sm text-gray-600 dark:text-gray-400">
                Zum Abschluss noch die E-Mail-Adresse bestätigen: dazu den Link in der zugeschickten
                Mail anklicken. Keine Mail erhalten? Wir schicken gern eine neue.
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
                    Ein neuer Bestätigungslink ist unterwegs an die hinterlegte Adresse.
                </div>
            )}

            {/* Der Abmelde-Link liegt außerhalb des Formulars: als <button> ohne
                eigenes Formular würde er sonst dessen Absenden auslösen. */}
            <div className="mt-4 flex items-center justify-between">
                <form onSubmit={submit}>
                    <PrimaryButton type="submit" disabled={processing}>
                        Mail erneut senden
                    </PrimaryButton>
                </form>

                <div className="flex flex-col items-end gap-1">
                    <Link href="/profile" className={formLinkClass}>
                        Adresse ändern
                    </Link>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        type="button"
                        className={formLinkClass}
                    >
                        Abmelden
                    </Link>
                </div>
            </div>
        </>
    );
}

VerifyEmail.layout = (page) => <GuestShell title="E-Mail-Adresse bestätigen">{page}</GuestShell>;
