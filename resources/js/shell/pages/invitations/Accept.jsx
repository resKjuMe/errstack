import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import PageHead from '../../components/PageHead.jsx';
import Card from '../../components/Card.jsx';
import { PrimaryButton } from '../../components/Form.jsx';

// Ziel des Links aus der Einladungs-Mail. Angenommen wird sie nur vom Konto mit
// der eingeladenen Adresse — ein weitergeleiteter Link landet sonst im falschen.
export default function Accept({ invitation, token }) {
    const { shell } = usePage().props;
    const { post, processing } = useForm({});

    const accept = (e) => {
        e.preventDefault();
        post(`/einladung/${token}`);
    };

    return (
        <>
            <PageHead
                title="Einladung"
                appName={shell.appName}
                help="Die Einladung gilt nur für die eingeladene E-Mail-Adresse. Wer mit einem anderen Konto angemeldet ist, meldet sich mit dem passenden an."
            />

            <div className="max-w-xl">
                <Card
                    title={invitation.organization}
                    description={`Eingeladen als: ${invitation.roleLabel} · für ${invitation.email}`}
                >
                    {invitation.isExpired && (
                        <p className="text-sm text-red-600 dark:text-red-400">
                            Diese Einladung ist am {invitation.expiresAt} abgelaufen. Bitte um eine
                            neue bitten.
                        </p>
                    )}

                    {!invitation.isExpired && !invitation.isForCurrentUser && (
                        <p className="text-sm text-red-600 dark:text-red-400">
                            Diese Einladung gehört zu einer anderen E-Mail-Adresse. Bitte mit dem
                            Konto zu {invitation.email} anmelden.
                        </p>
                    )}

                    {!invitation.isExpired && invitation.isForCurrentUser && (
                        <form onSubmit={accept} className="space-y-4">
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                Gültig bis {invitation.expiresAt}.
                            </p>

                            <PrimaryButton type="submit" disabled={processing}>
                                Einladung annehmen
                            </PrimaryButton>
                        </form>
                    )}
                </Card>
            </div>
        </>
    );
}
