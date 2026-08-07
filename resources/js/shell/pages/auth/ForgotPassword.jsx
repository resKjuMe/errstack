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

export default function ForgotPassword({ status = null }) {
    const t = useT();
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (e) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <>
            <div className="mb-4 text-sm text-gray-600 dark:text-gray-400">
                {t('auth_ui.forgot.intro')}
            </div>

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
                    {status}
                </div>
            )}

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="email" value={t('auth_ui.forgot.email')} />
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
                        {t('auth_ui.forgot.back')}
                    </Link>

                    <PrimaryButton type="submit" disabled={processing}>
                        {t('auth_ui.forgot.submit')}
                    </PrimaryButton>
                </div>
            </form>
        </>
    );
}

ForgotPassword.layout = (page) => <GuestShell titleKey="auth_ui.forgot.title">{page}</GuestShell>;
