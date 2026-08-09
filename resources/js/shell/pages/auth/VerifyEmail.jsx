import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import GuestShell from '../../GuestShell.jsx';
import { useT } from '../../i18n.js';
import { PrimaryButton, formLinkClass } from '../../components/Form.jsx';

export default function VerifyEmail({ status = null }) {
    const t = useT();
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();
        post('/email/verification-notification');
    };

    return (
        <>
            <div className="mb-4 text-sm text-gray-600 dark:text-gray-400">
                {t('auth_ui.verify.intro')}
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-4 text-sm font-medium text-green-600 dark:text-green-400">
                    {t('auth_ui.verify.sent')}
                </div>
            )}

            {/* Der Abmelde-Link liegt außerhalb des Formulars: als <button> ohne
                eigenes Formular würde er sonst dessen Absenden auslösen. */}
            <div className="mt-4 flex items-center justify-between">
                <form onSubmit={submit}>
                    <PrimaryButton type="submit" disabled={processing}>
                        {t('auth_ui.verify.resend')}
                    </PrimaryButton>
                </form>

                <div className="flex flex-col items-end gap-1">
                    <Link href="/einstellungen/konto/profil" className={formLinkClass}>
                        {t('auth_ui.verify.change_address')}
                    </Link>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        type="button"
                        className={formLinkClass}
                    >
                        {t('auth_ui.verify.sign_out')}
                    </Link>
                </div>
            </div>
        </>
    );
}

VerifyEmail.layout = (page) => <GuestShell titleKey="auth_ui.verify.title">{page}</GuestShell>;
