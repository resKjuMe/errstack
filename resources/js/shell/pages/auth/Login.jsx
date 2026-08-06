import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import GuestShell from '../../GuestShell.jsx';
import {
    Checkbox,
    InputError,
    InputLabel,
    PrimaryButton,
    TextInput,
    formLinkClass,
} from '../../components/Form.jsx';

export default function Login({ canResetPassword = true, status = null }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post('/login', { onFinish: () => reset('password') });
    };

    return (
        <>
            {status && (
                <div className="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
                    {status}
                </div>
            )}

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

                <div className="mt-4">
                    <InputLabel htmlFor="password" value="Passwort" />
                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        autoComplete="current-password"
                        required
                        className="mt-1"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4 block">
                    <label htmlFor="remember" className="inline-flex items-center">
                        <Checkbox
                            id="remember"
                            name="remember"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                        />
                        <span className="ms-2 text-sm text-gray-600 dark:text-gray-400">
                            Angemeldet bleiben
                        </span>
                    </label>
                </div>

                <div className="mt-4 flex items-center justify-between">
                    <Link href="/register" className={formLinkClass}>
                        Konto anlegen
                    </Link>

                    <div className="flex items-center gap-4">
                        {canResetPassword && (
                            <Link href="/forgot-password" className={formLinkClass}>
                                Passwort vergessen?
                            </Link>
                        )}

                        <PrimaryButton type="submit" disabled={processing}>
                            Anmelden
                        </PrimaryButton>
                    </div>
                </div>
            </form>
        </>
    );
}

Login.layout = (page) => <GuestShell title="Anmelden">{page}</GuestShell>;
