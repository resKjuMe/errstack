import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import GuestShell from '../../GuestShell.jsx';
import { useT } from '../../i18n.js';
import {
    InputError,
    InputLabel,
    PrimaryButton,
    TextInput,
    formLinkClass,
} from '../../components/Form.jsx';

export default function Register() {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post('/register', { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <form onSubmit={submit}>
            <div>
                <InputLabel htmlFor="name" value={t('auth_ui.register.name')} />
                <TextInput
                    id="name"
                    name="name"
                    value={data.name}
                    autoComplete="name"
                    autoFocus
                    required
                    className="mt-1"
                    onChange={(e) => setData('name', e.target.value)}
                />
                <InputError message={errors.name} className="mt-2" />
            </div>

            <div className="mt-4">
                <InputLabel htmlFor="email" value={t('auth_ui.register.email')} />
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
            </div>

            <div className="mt-4">
                <InputLabel htmlFor="password" value={t('auth_ui.register.password')} />
                <TextInput
                    id="password"
                    type="password"
                    name="password"
                    value={data.password}
                    autoComplete="new-password"
                    required
                    className="mt-1"
                    onChange={(e) => setData('password', e.target.value)}
                />
                <InputError message={errors.password} className="mt-2" />
            </div>

            <div className="mt-4">
                <InputLabel
                    htmlFor="password_confirmation"
                    value={t('auth_ui.register.password_confirmation')}
                />
                <TextInput
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    value={data.password_confirmation}
                    autoComplete="new-password"
                    required
                    className="mt-1"
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                />
                <InputError message={errors.password_confirmation} className="mt-2" />
            </div>

            <div className="mt-4 flex items-center justify-end gap-4">
                <Link href="/login" className={formLinkClass}>
                    {t('auth_ui.register.have_account')}
                </Link>

                <PrimaryButton type="submit" disabled={processing}>
                    {t('auth_ui.register.submit')}
                </PrimaryButton>
            </div>
        </form>
    );
}

Register.layout = (page) => <GuestShell titleKey="auth_ui.register.title">{page}</GuestShell>;
