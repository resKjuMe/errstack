import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import GuestShell from '../../GuestShell.jsx';
import { InputError, InputLabel, PrimaryButton, TextInput, formLinkClass } from '../../components/Form.jsx';

export default function ForgotPassword({ status = null }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (e) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <>
            <div className="mb-4 text-sm text-gray-600 dark:text-gray-400">
                E-Mail-Adresse eintragen — danach kommt ein Link, über den ein neues Passwort gesetzt werden kann.
            </div>

            {status && <div className="mb-4 text-sm font-medium text-green-600 dark:text-green-400">{status}</div>}

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="email" value="E-Mail-Adresse" />
                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        autoComplete="username"
                        autoFocus
                        required
                        className="mt-1"
                        onChange={(e) => setData('email', e.target.value)}
                    />
                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div className="mt-4 flex items-center justify-end gap-4">
                    <Link href="/login" className={formLinkClass}>
                        Zurück zur Anmeldung
                    </Link>

                    <PrimaryButton type="submit" disabled={processing}>
                        Link anfordern
                    </PrimaryButton>
                </div>
            </form>
        </>
    );
}

ForgotPassword.layout = (page) => <GuestShell title="Passwort vergessen">{page}</GuestShell>;
