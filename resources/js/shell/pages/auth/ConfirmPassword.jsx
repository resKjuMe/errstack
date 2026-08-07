import React from 'react';
import { useForm } from '@inertiajs/react';
import GuestShell from '../../GuestShell.jsx';
import { useT } from '../../i18n.js';
import { InputError, InputLabel, PrimaryButton, TextInput } from '../../components/Form.jsx';

export default function ConfirmPassword() {
    const t = useT();
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    const submit = (e) => {
        e.preventDefault();
        post('/confirm-password', { onFinish: () => reset('password') });
    };

    return (
        <>
            <div className="mb-4 text-sm text-gray-600 dark:text-gray-400">
                {t('auth_ui.confirm.intro')}
            </div>

            <form onSubmit={submit}>
                <div>
                    <InputLabel htmlFor="password" value={t('auth_ui.confirm.password')} />
                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        autoComplete="current-password"
                        autoFocus
                        required
                        className="mt-1"
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} className="mt-2" />
                </div>

                <div className="mt-4 flex items-center justify-end">
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('auth_ui.confirm.submit')}
                    </PrimaryButton>
                </div>
            </form>
        </>
    );
}

ConfirmPassword.layout = (page) => <GuestShell titleKey="auth_ui.confirm.title">{page}</GuestShell>;
