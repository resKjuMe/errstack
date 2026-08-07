import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { PrimaryButton } from '../../components/Form.jsx';
import { useT } from '../../i18n.js';

// Ziel des Links aus der Einladungs-Mail. Angenommen wird sie nur vom Konto mit
// der eingeladenen Adresse — ein weitergeleiteter Link landet sonst im falschen.
export default function Accept({ invitation, token }) {
    const { shell } = usePage().props;
    const t = useT();
    const { post, processing } = useForm({});

    const accept = (e) => {
        e.preventDefault();
        post(`/einladung/${token}`);
    };

    return (
        <>
            <PageHead
                title={t('invitations.title')}
                appName={shell.appName}
                help={t('invitations.help')}
            />

            <div className="max-w-xl">
                <Card
                    title={invitation.organization}
                    description={t('invitations.invited_as', {
                        role: invitation.roleLabel,
                        email: invitation.email,
                    })}
                >
                    {invitation.isExpired && (
                        <p className="text-sm text-red-600 dark:text-red-400">
                            {t('invitations.expired', { date: invitation.expiresAt })}
                        </p>
                    )}

                    {!invitation.isExpired && !invitation.isForCurrentUser && (
                        <p className="text-sm text-red-600 dark:text-red-400">
                            {t('invitations.wrong_account', { email: invitation.email })}
                        </p>
                    )}

                    {!invitation.isExpired && invitation.isForCurrentUser && (
                        <form onSubmit={accept} className="space-y-4">
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                {t('invitations.valid_until', { date: invitation.expiresAt })}
                            </p>

                            <PrimaryButton type="submit" disabled={processing}>
                                {t('invitations.accept')}
                            </PrimaryButton>
                        </form>
                    )}
                </Card>
            </div>
        </>
    );
}
