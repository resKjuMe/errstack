import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import AppShell from './shell/AppShell.jsx';
import Dashboard from './shell/pages/Dashboard.jsx';
import Components from './shell/pages/Components.jsx';
import Profile from './shell/pages/Profile.jsx';
import OrganizationsIndex from './shell/pages/organizations/Index.jsx';
import OrganizationsShow from './shell/pages/organizations/Show.jsx';
import TeamsShow from './shell/pages/teams/Show.jsx';
import InvitationsAccept from './shell/pages/invitations/Accept.jsx';
import Login from './shell/pages/auth/Login.jsx';
import Register from './shell/pages/auth/Register.jsx';
import ForgotPassword from './shell/pages/auth/ForgotPassword.jsx';
import ResetPassword from './shell/pages/auth/ResetPassword.jsx';
import ConfirmPassword from './shell/pages/auth/ConfirmPassword.jsx';
import VerifyEmail from './shell/pages/auth/VerifyEmail.jsx';

// Seiten-Registry: Inertia löst den vom Server gelieferten Seitennamen hier
// auf. Jede Seite wird in die AppShell gehängt (gemeinsames Layout), sofern sie
// nicht selbst ein Layout mitbringt — die Anmeldeseiten bringen mit der
// GuestShell ihr eigenes mit.
const pages = {
    Dashboard,
    Components,
    Profile,
    'organizations/Index': OrganizationsIndex,
    'organizations/Show': OrganizationsShow,
    'teams/Show': TeamsShow,
    'invitations/Accept': InvitationsAccept,
    'auth/Login': Login,
    'auth/Register': Register,
    'auth/ForgotPassword': ForgotPassword,
    'auth/ResetPassword': ResetPassword,
    'auth/ConfirmPassword': ConfirmPassword,
    'auth/VerifyEmail': VerifyEmail,
};

createInertiaApp({
    resolve: (name) => {
        const page = pages[name];

        if (!page) {
            throw new Error(`Unbekannte Inertia-Seite: ${name}`);
        }

        page.layout = page.layout ?? ((content) => <AppShell>{content}</AppShell>);

        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#f43f5e' },
});
